<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Exception;

use RuntimeException;

/**
 * HR: Prenosi stabilan HTTP status i javni kod pogreške iz webhook domene do
 *     API kontrolera bez otkrivanja internih detalja.
 * EN: Carries a stable HTTP status and public error code from the webhook
 *     domain to the API controller without exposing internal details.
 */
final class WebhookApiException extends RuntimeException
{
    /**
     * HR: Gradi sigurnu domensku pogrešku s HTTP statusom i strojnim kodom.
     * EN: Builds a safe domain error with an HTTP status and machine-readable code.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
