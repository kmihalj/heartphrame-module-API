<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Http;

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HR: Gradi dosljedne JSON uspješne i RFC 9457 problem odgovore.
 *
 * EN: Builds consistent JSON success and RFC 9457 problem responses.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Http\ApiResponseFactoryTest
 */
final readonly class ApiResponseFactory
{
    /**
     * HR: Inicijalizira tvornicu jedinstvenih API i problem odgovora.
     *
     * EN: Initializes the factory for consistent API and problem responses.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private ?ApiEntityTagService $entityTags = null,
    ) {
    }

    /**
     * HR: Dodaje ili dohvaća stabilni request ID aktualnog zahtjeva.
     *
     * EN: Adds or retrieves the stable request ID for the current request.
     */
    public function requestId(ServerRequestInterface $request): string
    {
        $existing = $request->getAttribute(ModuleApi::REQUEST_ID_ATTRIBUTE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $header = trim($request->getHeaderLine('X-Request-Id'));
        if ($header !== '' && preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $header) === 1) {
            return $header;
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * HR: Vraća relativni request-target s baznom putanjom aplikacije i queryjem.
     *
     * EN: Returns a relative request target including the application base path and query.
     */
    public function requestTarget(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        $target = $path !== '' ? $path : '/';
        $query = $request->getUri()->getQuery();

        return $query !== '' ? $target . '?' . $query : $target;
    }

    /**
     * HR: Gradi URL novog podresursa iz stvarne kolekcijske putanje zahtjeva.
     *
     * EN: Builds a new subresource URL from the request's actual collection path.
     */
    public function childTarget(ServerRequestInterface $request, int|string $identifier): string
    {
        return rtrim($request->getUri()->getPath(), '/')
            . '/'
            . rawurlencode((string)$identifier);
    }

    /**
     * HR: Vraća uspješni JSON envelope s metapodacima i poveznicama.
     *
     * EN: Returns a successful JSON envelope with metadata and links.
     *
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $links
     */
    public function success(
        ServerRequestInterface $request,
        mixed $data,
        int $status = 200,
        array $meta = [],
        array $links = [],
    ): ResponseInterface {
        $isCollection = $data instanceof ApiCollectionPage;
        if ($data instanceof ApiCollectionPage) {
            $meta = array_replace_recursive($data->meta, $meta);
            $links = array_replace($data->links, $links);
            $data = $data->items;
        }

        $requestId = $this->requestId($request);
        $meta = ['request_id' => $requestId] + $meta;

        $response = $this->responseFactory
            ->json(
                ['data' => $data, 'meta' => $meta, 'links' => $links],
                $status,
                ['X-Request-Id' => $requestId],
            );

        if (
            !$isCollection
            && $this->entityTags instanceof ApiEntityTagService
            && in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)
            && $status >= 200
            && $status < 300
        ) {
            return $response->withHeader('ETag', $this->entityTags->tag($data));
        }

        return $response;
    }

    /**
     * HR: Vraća RFC 9457 kompatibilan problem odgovor bez internih detalja.
     *
     * EN: Returns an RFC 9457-compatible problem response without internals.
     */
    public function problem(
        ServerRequestInterface $request,
        int $status,
        string $code,
        string $title,
        string $detail,
    ): ResponseInterface {
        $requestId = $this->requestId($request);

        return $this->responseFactory->json(
            [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'code' => $code,
                'instance' => $request->getUri()->getPath(),
                'request_id' => $requestId,
            ],
            $status,
            ['X-Request-Id' => $requestId],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            'application/problem+json; charset=utf-8',
        );
    }

    /**
     * HR: Vraća prazan uspješan odgovor za DELETE operacije.
     *
     * EN: Returns an empty successful response for DELETE operations.
     */
    public function noContent(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = $this->requestId($request);

        return $this->responseFactory
            ->createResponse(204)
            ->withHeader('X-Request-Id', $requestId);
    }
}
