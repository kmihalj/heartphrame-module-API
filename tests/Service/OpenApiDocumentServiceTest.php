<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Service\ApiScopeRegistry;
use AaiEduHr\HeartPhrameModuleApi\Service\OpenApiDocumentService;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Http\Request;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava da OpenAPI opis nastaje iz aktivnog router registra.
 *
 * EN: Verifies that the OpenAPI description comes from the active router registry.
 */
#[CoversClass(OpenApiDocumentService::class)]
#[UsesClass(ApiScopeRegistry::class)]
final class OpenApiDocumentServiceTest extends TestCase
{
    /**
     * HR: Uključuje stvarne rute, izostavlja OPTIONS i zadržava podmapu servera.
     *
     * EN: Includes real routes, omits OPTIONS, and preserves the server subdirectory.
     */
    public function testGeneratesDocumentFromRegisteredRoutes(): void
    {
        $routes = new Routes();
        $routes->addRoute(
            'GET',
            '/api/v1/widgets/{widgetId}',
            'WidgetController@show',
            'api.v1.widgets.show',
        );
        $routes->addRoute('OPTIONS', '/api/v1/widgets/{widgetId}', 'CorsController@handle');
        $routes->addRoute('GET', '/health', 'HealthController@show', 'health');

        $config = new Config(new Helper(), ['app' => ['modules' => ['enabled' => []]]]);
        $service = new OpenApiDocumentService(
            $routes,
            new ApiScopeRegistry(new ComposerBridge(), $config),
        );

        $document = $service->generate(
            new Request('GET', 'https://example.test/hfc/api/v1/openapi.json'),
        );

        $this->assertSame('3.1.0', $document['openapi'] ?? null);
        $this->assertSame('https://example.test/hfc', $document['servers'][0]['url'] ?? null);
        $this->assertSame(
            'api.v1.widgets.show',
            $document['paths']['/api/v1/widgets/{widgetId}']['get']['operationId'] ?? null,
        );
        $this->assertArrayNotHasKey('options', $document['paths']['/api/v1/widgets/{widgetId}'] ?? []);
        $this->assertArrayNotHasKey('/health', $document['paths'] ?? []);
    }
}
