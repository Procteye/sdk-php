<?php

declare(strict_types=1);

namespace Procteye\Exception;

/**
 * Base class for every error raised by the Procteye SDK.
 *
 * Every non-2xx response is mapped to one of the subclasses. All errors expose
 * the machine-readable error code, the HTTP status, the request id, and a
 * documentation URL when the API provides one.
 *
 * Note: PHP's {@see \Exception::getCode()} is final, so we store the string
 * error code (e.g. "insufficient_credits") in the inherited untyped `$code`
 * property. `getCode()` therefore returns the string error code.
 */
class ProcteyeException extends \Exception
{
    private ?int $httpStatus;
    private ?string $errorType;
    private ?string $requestId;
    private ?string $docUrl;

    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorType = null,
        ?string $requestId = null,
        ?string $docUrl = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        // Override the inherited (untyped) `$code` property with the string
        // API error code so getCode() surfaces it. getCode() is final.
        $this->code = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->errorType = $errorType;
        $this->requestId = $requestId;
        $this->docUrl = $docUrl;
    }

    /** The HTTP status code of the response, when there was one. */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** The machine-readable error type from the envelope, e.g. "invalid_request_error". */
    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    /** The request id (from the envelope or the X-Request-Id header). */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** A URL to relevant documentation, when the API provides one. */
    public function getDocUrl(): ?string
    {
        return $this->docUrl;
    }

    public function __toString(): string
    {
        $parts = [$this->getMessage()];
        if ($this->getCode()) {
            $parts[] = 'code=' . $this->getCode();
        }
        if ($this->requestId !== null) {
            $parts[] = 'request_id=' . $this->requestId;
        }
        return implode(' ', $parts);
    }

    /**
     * Build the appropriate typed error from an HTTP status and parsed envelope.
     *
     * @param array<string,mixed>|null $payload The decoded JSON body, if any.
     */
    public static function fromResponse(int $status, ?array $payload, ?string $requestId): self
    {
        $error = [];
        if (is_array($payload) && isset($payload['error']) && is_array($payload['error'])) {
            $error = $payload['error'];
        }

        $message = isset($error['message']) && is_string($error['message'])
            ? $error['message']
            : "Procteye request failed with status {$status}";
        $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
        $type = isset($error['type']) && is_string($error['type']) ? $error['type'] : null;
        $docUrl = isset($error['doc_url']) && is_string($error['doc_url']) ? $error['doc_url'] : null;
        $reqId = (isset($error['request_id']) && is_string($error['request_id']))
            ? $error['request_id']
            : $requestId;

        $args = [$message, $status, $code, $type, $reqId, $docUrl];

        if ($status === 401) {
            return new AuthenticationException(...$args);
        }
        if ($status === 403) {
            return new PermissionException(...$args);
        }
        if ($status === 402) {
            return new BillingException(...$args);
        }
        if (in_array($status, [400, 404, 422], true)) {
            return new InvalidRequestException(...$args);
        }
        if ($status === 429) {
            return new RateLimitException(...$args);
        }
        if ($status >= 500) {
            return new ApiException(...$args);
        }
        // Any other unexpected 4xx maps to InvalidRequestException.
        return new InvalidRequestException(...$args);
    }
}
