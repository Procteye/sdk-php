<?php

declare(strict_types=1);

namespace Procteye;

use Procteye\Exception\ApiException;
use Procteye\Exception\ConnectionException;
use Procteye\Exception\ProcteyeException;

/**
 * Synchronous, server-side client for the Procteye Integrity API.
 *
 * Uses only ext-curl from the PHP standard library, so there are no hard
 * runtime dependencies.
 *
 * Authenticates with a secret API key sent as a Bearer token on every
 * request; the key is resolved from the constructor argument or the
 * PROCTEYE_API_KEY env var. The configured base_url is validated up front —
 * only https:// is accepted, with an exception for loopback hosts so local
 * development keeps working over http://. Requests that fail with 429, a
 * 5xx status, or a transport error are retried automatically (up to
 * max_retries) with exponential backoff and full jitter, honoring
 * Retry-After.
 *
 * Example:
 * ```php
 * $client = new Procteye\Client(getenv('PROCTEYE_API_KEY'));
 * $session = $client->sessions->create(['assurance_level' => 'HIGH']);
 * echo $session->getId(), ' ', $session->getClientSecret();
 * ```
 */
final class Client
{
    public const VERSION = '2.1.0';
    public const API_VERSION = '2026-07-01';
    public const DEFAULT_BASE_URL = 'https://api.procteye.com';
    public const DEFAULT_TIMEOUT = 30;
    public const DEFAULT_MAX_RETRIES = 2;
    /** Ceiling (seconds) for a Retry-After-driven wait between retries. Mirrors
     * MAX_RETRY_WAIT_SECONDS in the node and java SDKs, so a hostile or
     * misconfigured Retry-After (e.g. a day-long value from a misbehaving
     * proxy) can never stall a PHP-FPM worker for hours -- and stays well
     * clear of the 32-bit usleep() microsecond overflow a very large,
     * unclamped Retry-After could otherwise trigger. */
    public const MAX_RETRY_WAIT_SECONDS = 60;

    /** Error code of the one 409 that is retried: the first request with the
     * same Idempotency-Key is still in progress. */
    public const IDEMPOTENCY_IN_PROGRESS = 'idempotency_request_in_progress';

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;

    /** Operations on integrity sessions: the core resource of the API. */
    public readonly Sessions $sessions;

    /**
     * @param string|null $apiKey Secret API key (pe_sk_live_… / pe_sk_test_…).
     *                            Defaults to the PROCTEYE_API_KEY env var.
     * @param array{base_url?:string,timeout?:int|float,max_retries?:int} $options
     *
     * @throws ProcteyeException when no API key can be resolved.
     * @throws \InvalidArgumentException when timeout or max_retries is out of range.
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $key = $apiKey ?? self::envOrNull('PROCTEYE_API_KEY');
        if ($key === null || $key === '') {
            throw new ProcteyeException(
                'No API key provided. Pass it to new Client($apiKey) or set PROCTEYE_API_KEY.'
            );
        }
        $this->apiKey = $key;

        $baseUrl = $options['base_url']
            ?? self::envOrNull('PROCTEYE_BASE_URL')
            ?? self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim(self::validateBaseUrl($baseUrl), '/');

        $timeout = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be greater than 0.');
        }
        $this->timeout = $timeout;

        $maxRetries = (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('max_retries must be greater than or equal to 0.');
        }
        $this->maxRetries = $maxRetries;

        $this->sessions = new Sessions($this);
    }

    /**
     * Low-level request primitive with typed errors and automatic retries.
     *
     * Retries on 429/5xx (and transport errors) with exponential backoff + full
     * jitter, honoring Retry-After. POSTs carry an Idempotency-Key so retries
     * are safe.
     *
     * @param array{query?:array<string,mixed>,body?:array<string,mixed>,idempotency_key?:string,headers?:array<string,string>} $options
     *
     * @return array<string,mixed>|null The decoded JSON response body.
     *
     * @throws ProcteyeException
     */
    public function request(string $method, string $path, array $options = []): ?array
    {
        $method = strtoupper($method);
        $url = $this->buildUrl($path, $options['query'] ?? null);
        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'PE-Version' => self::API_VERSION,
            'User-Agent' => 'procteye-php/' . self::VERSION,
        ];

        $body = null;
        if (array_key_exists('body', $options) && $options['body'] !== null) {
            $headers['Content-Type'] = 'application/json';
            // The request body is always a JSON object. PHP encodes an empty
            // array as [], so send {} for an empty body (e.g. complete/cancel).
            $encoded = json_encode($options['body'] === [] ? new \stdClass() : $options['body']);
            if ($encoded === false) {
                throw new ProcteyeException('Failed to JSON-encode request body: ' . json_last_error_msg());
            }
            $body = $encoded;
        }

        if ($isWrite) {
            $headers['Idempotency-Key'] = $options['idempotency_key'] ?? self::uuidv4();
        }

        if (isset($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                $headers[$name] = $value;
            }
        }

        $lastError = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                [$status, $respHeaders, $raw] = $this->dispatch($method, $url, $headers, $body);
            } catch (ConnectionException $exc) {
                if ($attempt < $this->maxRetries) {
                    $lastError = $exc;
                    $this->sleep($this->backoff($attempt, null));
                    continue;
                }
                throw $exc;
            }

            $requestId = $this->headerValue($respHeaders, 'x-request-id');

            if ($status >= 200 && $status < 300) {
                return $this->parseJson($raw, true);
            }

            $payload = $this->parseJson($raw);
            $error = ProcteyeException::fromResponse($status, $payload, $requestId);

            if ($this->isRetryable($status, is_string($error->getCode()) ? $error->getCode() : null) && $attempt < $this->maxRetries) {
                $lastError = $error;
                $this->sleep($this->backoff($attempt, $this->headerValue($respHeaders, 'retry-after')));
                continue;
            }
            throw $error;
        }

        throw $lastError ?? new ConnectionException('Request failed after retries.');
    }

    /**
     * @param array<string,string> $headers
     *
     * @return array{0:int,1:array<string,string>,2:string}
     *
     * @throws ConnectionException on any transport-level failure.
     */
    private function dispatch(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new ConnectionException('Failed to initialize curl.');
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $message = curl_error($ch);
            $errno = curl_errno($ch);
            throw new ConnectionException("Network error contacting Procteye: {$message} (curl errno {$errno}).");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, $responseHeaders, is_string($raw) ? $raw : ''];
    }

    /**
     * @param array<string,mixed>|null $query
     */
    private function buildUrl(string $path, ?array $query): string
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            $pairs = array_filter($query, static fn ($v) => $v !== null);
            if ($pairs !== []) {
                $url .= '?' . http_build_query($pairs);
            }
        }
        return $url;
    }

    /**
     * Decode a response body as JSON. An empty body yields null. When $strict
     * is set (2xx success path), malformed JSON raises a typed ApiException
     * instead of silently returning null; the non-strict path (error envelopes)
     * stays lenient so a non-JSON error body still maps to the appropriate
     * typed API exception.
     *
     * @return array<string,mixed>|null
     *
     * @throws ApiException when $strict and the body is malformed JSON.
     */
    private function parseJson(string $raw, bool $strict = false): ?array
    {
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if ($strict) {
            throw new ApiException(
                'Failed to parse Procteye response as JSON: ' . json_last_error_msg()
            );
        }
        return null;
    }

    private function isRetryable(int $status, ?string $code = null): bool
    {
        // A 409 idempotency_request_in_progress means the first request with
        // this Idempotency-Key is still running; retrying with the same key
        // returns its result. Every other 409 (e.g. idempotency_key_in_use)
        // is final.
        if ($status === 409) {
            return $code === self::IDEMPOTENCY_IN_PROGRESS;
        }
        return $status === 429 || $status >= 500;
    }

    /** Exponential backoff with full jitter, honoring Retry-After (clamped to
     * self::MAX_RETRY_WAIT_SECONDS). Seconds (float). */
    private function backoff(int $attempt, ?string $retryAfter): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            $seconds = (float) $retryAfter;
            if ($seconds >= 0) {
                return min($seconds, (float) self::MAX_RETRY_WAIT_SECONDS);
            }
        }
        $base = min(0.5 * (2 ** $attempt), 8.0);
        return (mt_rand() / mt_getrandmax()) * $base;
    }

    private function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /** @param array<string,string> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        return $headers[strtolower($name)] ?? null;
    }

    private static function envOrNull(string $name): ?string
    {
        $value = getenv($name);
        return ($value === false || $value === '') ? null : $value;
    }

    /**
     * Reject any base URL that would send the secret API key over plaintext.
     *
     * Only `https://` is accepted, with an exception for loopback hosts
     * (`localhost` / `127.0.0.1` / `::1`) so local development and test
     * servers keep working over plain `http://`.
     *
     * @return string The same $baseUrl, when valid.
     *
     * @throws ProcteyeException
     */
    private static function validateBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new ProcteyeException(
                "Invalid base_url '{$baseUrl}': could not be parsed as a URL."
            );
        }
        if ($parts['scheme'] === 'https') {
            return $baseUrl;
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($parts['scheme'] === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        ) {
            return $baseUrl;
        }
        throw new ProcteyeException(
            "Invalid base_url '{$baseUrl}': must use https:// "
            . '(plain http:// is only permitted for localhost). '
            . 'Refusing to send the API key over an unencrypted connection.'
        );
    }

    /** Generate an RFC 4122 version 4 UUID. */
    public static function uuidv4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
