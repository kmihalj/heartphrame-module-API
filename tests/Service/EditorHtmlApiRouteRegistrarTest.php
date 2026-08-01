<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Service\EditorHtmlApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleEditorHtml\ModuleEditorHtml;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava da Editor API ostaje opcionalan i registrira potpuni skup ruta.
 * EN: Verifies that the Editor API remains optional and registers its complete route set.
 */
#[CoversClass(EditorHtmlApiRouteRegistrar::class)]
final class EditorHtmlApiRouteRegistrarTest extends TestCase
{
    /**
     * HR: Registrira sve Editor rute kada je modul instaliran i uključen.
     * EN: Registers every Editor route when the module is installed and enabled.
     */
    public function testRegistersEditorRoutesOnlyWhenModuleIsAvailable(): void
    {
        $routes = new Routes();
        $registrar = new EditorHtmlApiRouteRegistrar(
            $this->composer(true),
            new Config(new Helper(), [
                'app' => [
                    'modules' => [
                        'enabled' => [ModuleEditorHtml::PACKAGE_NAME],
                    ],
                ],
            ]),
            $routes,
        );

        $registrar->register();

        $namedRoutes = $routes->getNamedRoutes();
        $this->assertCount(22, $namedRoutes);
        $this->assertSame(
            '/api/v1/pages/{documentId}/review',
            $namedRoutes['api.v1.pages.review']['path'] ?? null,
        );
        $this->assertSame(
            '/api/v1/pages/{documentId}/attachments/{assetUuid}/content',
            $namedRoutes['api.v1.pages.attachments.content']['path'] ?? null,
        );

        $route = $routes->getRoutes()['GET']['/api/v1/pages'] ?? [];
        $this->assertContains(ApiAuthenticationMiddleware::class, $route['middleware'] ?? []);
    }

    /**
     * HR: Ne registrira rute kada Editor nije instaliran ili nije uključen.
     * EN: Registers no routes when the Editor is missing or disabled.
     */
    public function testSkipsRoutesWhenEditorIsUnavailable(): void
    {
        foreach (
            [
                [false, [ModuleEditorHtml::PACKAGE_NAME]],
                [true, []],
            ] as [$installed, $enabled]
        ) {
            $routes = new Routes();
            $registrar = new EditorHtmlApiRouteRegistrar(
                $this->composer($installed),
                new Config(new Helper(), [
                    'app' => [
                        'modules' => [
                            'enabled' => $enabled,
                        ],
                    ],
                ]),
                $routes,
            );

            $registrar->register();
            $this->assertSame([], $routes->getNamedRoutes());
        }
    }

    /**
     * HR: Vraća kontrolirani Composer most za simulaciju prisutnosti Editora.
     * EN: Returns a controlled Composer bridge for simulating Editor availability.
     */
    private function composer(bool $editorInstalled): ComposerBridge
    {
        return new class ($editorInstalled) extends ComposerBridge {
            /**
             * HR: Sprema treba li test prijaviti Editor kao instaliran.
             * EN: Stores whether the test should report the Editor as installed.
             */
            public function __construct(private readonly bool $editorInstalled)
            {
            }

            /**
             * HR: Vraća kontrolirano stanje samo za Editor paket.
             * EN: Returns controlled installation state only for the Editor package.
             */
            public function isInstalled(string $packageName): bool
            {
                return $packageName === ModuleEditorHtml::PACKAGE_NAME
                    && $this->editorInstalled;
            }
        };
    }
}
