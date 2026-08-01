<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HR: Testni handler uvijek vraća unaprijed pripremljen odgovor.
 *
 * EN: Test handler that always returns a prebuilt response.
 */
final readonly class FixedResponseHandler implements RequestHandlerInterface
{
    /**
     * HR: Prima odgovor koji treba vratiti.
     *
     * EN: Receives the response to return.
     */
    public function __construct(private ResponseInterface $response)
    {
    }

    /**
     * HR: Vraća pripremljeni odgovor bez domenske obrade.
     *
     * EN: Returns the prepared response without domain processing.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
