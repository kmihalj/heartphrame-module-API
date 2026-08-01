<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HR: Završava valjani browser CORS preflight bez pokretanja domenskog kontrolera.
 *
 * EN: Completes a valid browser CORS preflight without invoking a domain controller.
 */
final readonly class ApiPreflightController
{
    /**
     * HR: Prima osnovnu HTTP tvornicu za prazan odgovor.
     *
     * EN: Receives the base HTTP factory for an empty response.
     */
    public function __construct(private ResponseFactory $responses)
    {
    }

    /**
     * HR: Vraća prazan 204 odgovor koji CORS middleware nadopunjuje zaglavljima.
     *
     * EN: Returns an empty 204 response decorated by the CORS middleware.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'OPTIONS') {
            return $this->responses->createResponse(405);
        }

        return $this->responses->createResponse(204);
    }
}
