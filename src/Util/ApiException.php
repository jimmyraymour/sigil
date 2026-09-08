<?php
declare(strict_types=1);

namespace Sigil\Util;

/**
 * Typed exception for API-level errors. Carries the error code, HTTP
 * status, optional details and an optional Retry-After hint.
 */
final class ApiException extends \RuntimeException
{
    private string $errorCode;
    private int $httpStatus;
    private array $details;
    private ?int $retryAfter;

    public function __construct(string $errorCode, string $message, int $httpStatus = 400, array $details = [], ?int $retryAfter = null)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->details = $details;
        $this->retryAfter = $retryAfter;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function details(): array
    {
        return $this->details;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self('invalid_input', $message, 400, $details);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self('unauthorized', $message, 401);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function rateLimited(string $message, int $retryAfter): self
    {
        return new self('rate_limited', $message, 429, [], $retryAfter);
    }

    public static function upstreamError(string $message): self
    {
        return new self('upstream_error', $message, 502);
    }

    public static function internal(string $message = 'Internal server error'): self
    {
        return new self('internal_error', $message, 500);
    }
}
