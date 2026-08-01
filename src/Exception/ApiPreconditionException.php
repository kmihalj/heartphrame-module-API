<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Exception;

use RuntimeException;

/**
 * HR: Predstavlja nedostajući ili zastarjeli `If-Match` uvjet izmjene.
 * EN: Represents a missing or stale `If-Match` write precondition.
 */
final class ApiPreconditionException extends RuntimeException
{
    /**
     * HR: Sprema stabilni kod problema i pripadajući HTTP status.
     * EN: Stores a stable problem code and its HTTP status.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}
