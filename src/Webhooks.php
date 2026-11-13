<?php

declare(strict_types=1);

namespace Procteye;

use Procteye\Exception\SignatureVerificationException;

/**
 * Webhook signature verification implementing the Standard Webhooks spec
 * (https://www.standardwebhooks.com), matching the gateway's standardwebhooks
 * signer and interoperable with the Node and Python SDKs.
 */
final class Webhooks
{
    /** Default tolerance for the webhook timestamp: 5 minutes. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify a webhook signature and return the parsed event.
     *
     * @param string               $payload The exact raw request body. Do NOT pass a
     *                                       re-serialized object — the signature is over
     *                                       the raw bytes.
     * @param array<string,mixed>  $headers Incoming request headers (webhook-id,
     *                                       webhook-timestamp, webhook-signature). Values
     *                                       may be strings or arrays of strings.
     * @param string               $secret  The endpoint secret, e.g. whsec_….
     * @param int                  $tolerance Max allowed age of the webhook, in seconds.
     *
     * @return array<string,mixed> The parsed event.
     *
     * @throws SignatureVerificationException on any missing header, bad
     *         signature, or a timestamp outside the tolerance window.
     */
    public static function constructEvent(
        string $payload,
        array $headers,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE_SECONDS
    ): array {
        if (trim($secret) === '') {
            throw new SignatureVerificationException('Webhook secret must be a non-empty string.');
        }

        $id = self::getHeader($headers, 'webhook-id');
        $timestamp = self::getHeader($headers, 'webhook-timestamp');
        $signatureHeader = self::getHeader($headers, 'webhook-signature');

        if ($id === '' || $timestamp === '' || $signatureHeader === '') {
            throw new SignatureVerificationException(
                'Missing required webhook headers (webhook-id, webhook-timestamp, webhook-signature).'
            );
        }

        self::verifyTimestamp($timestamp, $tolerance);

        $signedContent = "{$id}.{$timestamp}.{$payload}";
        $expected = self::sign($signedContent, $secret);

        // The header may carry a space-delimited list of `v1,<sig>` versions.
        $passed = false;
        foreach (explode(' ', $signatureHeader) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $segments = explode(',', $part, 2);
            $version = $segments[0];
            $value = $segments[1] ?? '';
            if ($version !== 'v1' || $value === '') {
                continue;
            }
            if (hash_equals($expected, $value)) {
                $passed = true;
                break;
            }
        }

        if (!$passed) {
            throw new SignatureVerificationException('Webhook signature verification failed.');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new SignatureVerificationException('Webhook payload is not valid JSON.');
        }
        return $event;
    }

    /**
     * Compute the base64 HMAC-SHA256 of $signedContent.
     *
     * Per Standard Webhooks, the portion after `whsec_` is base64 and is the
     * raw HMAC key.
     */
    private static function sign(string $signedContent, string $secret): string
    {
        if (trim($secret) === '') {
            throw new SignatureVerificationException('Webhook secret must be a non-empty string.');
        }
        $keyB64 = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        if (trim($keyB64) === '') {
            throw new SignatureVerificationException('Webhook secret is invalid or empty.');
        }
        $key = base64_decode($keyB64, true);
        if ($key === false) {
            throw new SignatureVerificationException('Invalid webhook secret: not valid base64.');
        }
        if (strlen($key) < 32) {
            throw new SignatureVerificationException('Webhook secret key must be at least 32 bytes.');
        }
        return base64_encode(hash_hmac('sha256', $signedContent, $key, true));
    }

    private static function verifyTimestamp(string $timestamp, int $tolerance): void
    {
        if (!ctype_digit(ltrim($timestamp, '-')) || !is_numeric($timestamp)) {
            throw new SignatureVerificationException('Invalid webhook-timestamp header.');
        }
        $then = (int) $timestamp;
        $now = time();
        if (abs($now - $then) > $tolerance) {
            throw new SignatureVerificationException(
                "Webhook timestamp is outside the allowed tolerance ({$tolerance}s)."
            );
        }
    }

    /**
     * Case-insensitive header lookup. Accepts string or array values; returns
     * "" when absent.
     *
     * @param array<string,mixed> $headers
     */
    private static function getHeader(array $headers, string $name): string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $lower) {
                if (is_array($value)) {
                    return $value === [] ? '' : (string) reset($value);
                }
                return (string) $value;
            }
        }
        return '';
    }
}
