<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Service\OpenApiDocumentService;
use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HR: Poslužuje OpenAPI opis iz stvarnog registra aktivnih API ruta.
 *
 * EN: Serves the OpenAPI description from the actual active API route registry.
 */
final readonly class OpenApiController
{
    /**
     * HR: Prima generator dokumenta i HTTP tvornicu.
     *
     * EN: Receives the document generator and HTTP response factory.
     */
    public function __construct(
        private OpenApiDocumentService $documents,
        private ResponseFactory $responses,
    ) {
    }

    /**
     * HR: Vraća OpenAPI 3.1 JSON za trenutačno uključene module.
     *
     * EN: Returns OpenAPI 3.1 JSON for currently enabled modules.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->json(
            $this->documents->generate($request),
            headers: ['Cache-Control' => 'no-store'],
            flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            contentType: 'application/vnd.oai.openapi+json;version=3.1.0',
        );
    }
}
