<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use HeartPhrame\Routing\Routes;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HR: Gradi OpenAPI 3.1 dokument iz istog registra koji koristi runtime router.
 *
 * EN: Builds an OpenAPI 3.1 document from the same registry used by the runtime router.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\OpenApiDocumentServiceTest
 */
final readonly class OpenApiDocumentService
{
    /**
     * HR: Prima aktivne rute i dinamički katalog scopeova uključenih modula.
     *
     * EN: Receives active routes and the dynamic scope catalog of enabled modules.
     */
    public function __construct(
        private Routes $routes,
        private ApiScopeRegistry $scopes,
    ) {
    }

    /**
     * HR: Vraća potpuni OpenAPI dokument za trenutačnu instalaciju.
     *
     * EN: Returns the complete OpenAPI document for the current installation.
     *
     * @return array<string,mixed>
     */
    public function generate(ServerRequestInterface $request): array
    {
        $named = $this->routeNames();
        $paths = [];

        foreach ($this->routes->getRoutes() as $method => $routes) {
            if ($method === 'OPTIONS') {
                continue;
            }

            foreach ($routes as $path => $route) {
                if (!str_starts_with((string)$path, '/api/v1')) {
                    continue;
                }

                $publicPath = $this->publicPath($path);
                $paths[$publicPath][strtolower((string)$method)] = $this->operation(
                    $method,
                    $path,
                    $named[$method . ' ' . $path] ?? null,
                );
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'HeartPhrame API',
                'version' => '1.0.0',
                'description' => 'Versioned modular API. Domain ACL is always evaluated in addition to key scopes.',
            ],
            'servers' => [['url' => $this->serverUrl($request)]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'HeartPhrame API key',
                    ],
                ],
                'schemas' => [
                    'SuccessEnvelope' => $this->successSchema(),
                    'Problem' => $this->problemSchema(),
                ],
            ],
            'security' => [['bearerAuth' => []]],
            'x-heartphrame-scope-groups' => $this->scopes->grouped(),
        ];
    }

    /**
     * HR: Opisuje jednu aktivnu rutu bez dupliciranja domenske validacije.
     *
     * EN: Describes one active route without duplicating domain validation.
     *
     * @return array<string,mixed>
     */
    private function operation(string $method, string $path, ?string $name): array
    {
        $unsafe = in_array($method, ['POST', 'PUT', 'PATCH'], true);
        $operation = [
            'operationId' => $name ?? strtolower($method) . preg_replace('/[^A-Za-z0-9]+/', '_', $path),
            'tags' => [$this->tag($path)],
            'parameters' => $this->pathParameters($path),
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/SuccessEnvelope'],
                        ],
                    ],
                ],
                '204' => ['description' => 'Successful response without body'],
                '400' => $this->problemResponse('Invalid request'),
                '401' => $this->problemResponse('Invalid or missing API key'),
                '403' => $this->problemResponse('Missing scope or domain permission'),
                '404' => $this->problemResponse('Resource not found'),
                '409' => $this->problemResponse('Resource state conflict'),
                '422' => $this->problemResponse('Validation failed'),
                '429' => $this->problemResponse('Rate limit exceeded'),
            ],
        ];

        if ($unsafe) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                ],
            ];
        }

        return $operation;
    }

    /**
     * HR: Vraća OpenAPI parametre za sve placeholder dijelove putanje.
     *
     * EN: Returns OpenAPI parameters for all path placeholders.
     *
     * @return list<array<string,mixed>>
     */
    private function pathParameters(string $path): array
    {
        preg_match_all('/\{([A-Za-z_]\w*)}/', $path, $matches);

        return array_map(
            static fn(string $name): array => [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
            $matches[1],
        );
    }

    /**
     * HR: Iz putanje određuje preglednu OpenAPI grupu resursa.
     *
     * EN: Derives a readable OpenAPI resource tag from the path.
     */
    private function tag(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $path)));

        return $parts[2] ?? 'api';
    }

    /**
     * HR: Pretvara nazive ruta u mapu prikladnu za generiranje operation ID-a.
     *
     * EN: Converts route names into a map suitable for operation ID generation.
     *
     * @return array<string,string>
     */
    private function routeNames(): array
    {
        $names = [];
        foreach ($this->routes->getNamedRoutes() as $name => $route) {
            $names[$route['method'] . ' ' . $route['path']] = $name;
        }

        return $names;
    }

    /**
     * HR: Uklanja instalacijsku baznu putanju iz javnog OpenAPI path ključa.
     *
     * EN: Keeps the OpenAPI path key relative to the configured server URL.
     */
    private function publicPath(string $path): string
    {
        return $path;
    }

    /**
     * HR: Gradi server URL iz stvarnog zahtjeva i podržava instalaciju u podmapi.
     *
     * EN: Builds the server URL from the real request and supports subdirectory installs.
     */
    private function serverUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $marker = '/api/v1';
        $position = strpos($path, $marker);
        $basePath = $position === false ? '' : substr($path, 0, $position);
        $authority = $uri->getAuthority();

        return ($authority !== '' ? $uri->getScheme() . '://' . $authority : '')
            . rtrim($basePath, '/');
    }

    /**
     * HR: Vraća zajedničku referencu na RFC 9457 problem odgovor.
     *
     * EN: Returns the shared RFC 9457 problem-response reference.
     *
     * @return array<string,mixed>
     */
    private function problemResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/problem+json' => [
                    'schema' => ['$ref' => '#/components/schemas/Problem'],
                ],
            ],
        ];
    }

    /**
     * HR: Opisuje zajednički uspješni envelope.
     *
     * EN: Describes the shared successful envelope.
     *
     * @return array<string,mixed>
     */
    private function successSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['data', 'meta', 'links'],
            'properties' => [
                'data' => true,
                'meta' => [
                    'type' => 'object',
                    'required' => ['request_id'],
                    'properties' => ['request_id' => ['type' => 'string']],
                    'additionalProperties' => true,
                ],
                'links' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ];
    }

    /**
     * HR: Opisuje zajednički RFC 9457 problem payload.
     *
     * EN: Describes the shared RFC 9457 problem payload.
     *
     * @return array<string,mixed>
     */
    private function problemSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'title', 'status', 'detail', 'code', 'request_id'],
            'properties' => [
                'type' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'code' => ['type' => 'string'],
                'instance' => ['type' => 'string'],
                'request_id' => ['type' => 'string'],
            ],
        ];
    }
}
