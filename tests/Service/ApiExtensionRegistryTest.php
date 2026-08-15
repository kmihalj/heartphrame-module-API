<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Contract\ApiRouteRegistry;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiExtensionRegistry;
use AaiEduHr\HeartPhrameModuleApi\Tests\Fixtures\TestApiExtension;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/** HR: Pokriva otkrivanje proširenja uključenih modula. EN: Covers enabled-module extension discovery. */
#[CoversClass(ApiExtensionRegistry::class)]
#[CoversClass(ApiRouteRegistry::class)]
final class ApiExtensionRegistryTest extends TestCase
{
    /** HR: Registrira opis instaliranog i uključenog modula. EN: Registers an installed enabled descriptor. */
    public function testRegistersEnabledExtension(): void
    {
        $extension = new TestApiExtension();
        $registry = $this->registry($extension, true, ['vendor/test-extension']);

        $registry->registerEnabled();

        $this->assertSame(1, $extension->registrations);
    }

    /** HR: Preskače modul koji nije instaliran. EN: Skips a module that is not installed. */
    public function testSkipsMissingModule(): void
    {
        $extension = new TestApiExtension();
        $registry = $this->registry($extension, false, ['vendor/test-extension']);

        $registry->registerEnabled();

        $this->assertSame(0, $extension->registrations);
    }

    /** HR: Prekida kada servis proširenja nedostaje. EN: Fails when an extension service is missing. */
    public function testRejectsUnavailableExtensionService(): void
    {
        $extension = new TestApiExtension();
        $registry = $this->registry($extension, true, ['vendor/test-extension'], false);

        $this->expectException(RuntimeException::class);
        $registry->registerEnabled();
    }

    /**
     * HR: Gradi kontrolirano okruženje registra.
     * EN: Builds a controlled registry environment.
     *
     * @param list<string> $enabled
     */
    private function registry(
        TestApiExtension $extension,
        bool $installed,
        array $enabled,
        bool $serviceAvailable = true,
    ): ApiExtensionRegistry {
        $fixturePath = dirname(__DIR__) . '/Fixtures/ApiExtensionModule';
        $composer = new class ($installed, $fixturePath) extends ComposerBridge {
            /** HR: Sprema testno stanje paketa. EN: Stores the test package state. */
            public function __construct(
                private readonly bool $installed,
                private readonly string $fixturePath,
            ) {
            }

            /** HR: Vraća kontrolirano stanje instalacije. EN: Returns controlled installation state. */
            public function isInstalled(string $packageName): bool
            {
                return $packageName === 'vendor/test-extension' && $this->installed;
            }

            /** HR: Vraća putanju testnog modula. EN: Returns the test module path. */
            public function getInstallPath(string $packageName): ?string
            {
                return $this->isInstalled($packageName) ? $this->fixturePath : null;
            }
        };
        $container = new class ($extension, $serviceAvailable) implements ContainerInterface {
            /** HR: Sprema testni servis. EN: Stores the test service. */
            public function __construct(
                private readonly TestApiExtension $extension,
                private readonly bool $serviceAvailable,
            ) {
            }

            /** HR: Vraća testno proširenje. EN: Returns the test extension. */
            public function get(string $id): mixed
            {
                if (!$this->has($id)) {
                    throw new RuntimeException('Missing test service.');
                }

                return $this->extension;
            }

            /** HR: Prijavljuje dostupnost testnog servisa. EN: Reports test service availability. */
            public function has(string $id): bool
            {
                return $this->serviceAvailable && $id === TestApiExtension::class;
            }
        };

        return new ApiExtensionRegistry(
            $composer,
            new Config(new Helper(), ['app' => ['modules' => ['enabled' => $enabled]]]),
            $container,
            new ApiRouteRegistry(new Routes()),
        );
    }
}
