<?php

declare(strict_types=1);

namespace Emaki;

/**
 * Base of every error this library throws on purpose. Catch a subclass to decide what to do next:
 * AuthError · QuotaError · PlanError · BusyError · RejectedError · ValidationError ·
 * GenerationFailedError · TimeoutError. Anything else (network, 5xx) is this class itself.
 */
class EmakiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?string $errorCode = null,
        public readonly ?string $failureCode = null,
    ) {
        parent::__construct($message);
    }

    /** Map an HTTP error answer to the error that says what to do next. */
    public static function fromHttp(int $status, ?string $message, ?string $code = null): self
    {
        $msg = ($message === null || $message === '') ? "HTTP $status" : $message;
        $class = match (true) {
            $status === 401 => AuthError::class,
            $status === 402 => QuotaError::class,
            $status === 403 => PlanError::class,
            $status === 409 => BusyError::class,
            $code === 'content_cooldown' || $code === 'content_blocked' => RejectedError::class,
            $status === 400 => ValidationError::class,
            default => self::class,
        };
        return new $class($msg, $status, $code);
    }
}
