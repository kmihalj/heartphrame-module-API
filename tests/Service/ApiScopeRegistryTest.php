<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Service\ApiScopeRegistry;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ApiScopeRegistry::class)]
final class ApiScopeRegistryTest extends TestCase
{
    private string $temporaryDirectory;

    /**
     * HR: Kreira privremeni modul za test dinamičkog otkrivanja descriptor-a.
     *
     * EN: Creates a temporary module for dynamic-descriptor discovery tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . '/heartphrame-api-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->temporaryDirectory . '/config', 0777, true));
    }

    /**
     * HR: Uklanja privremeni descriptor nakon svakog testa.
     *
     * EN: Removes the temporary descriptor after every test.
     */
    protected function tearDown(): void
    {
        $descriptor = $this->temporaryDirectory . '/config/api.php';
        if (is_file($descriptor)) {
            unlink($descriptor);
        }

        if (is_dir($this->temporaryDirectory . '/config')) {
            rmdir($this->temporaryDirectory . '/config');
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    /**
     * HR: Dokazuje da katalog sadrži samo scopeove uključenog i instaliranog
     * modula te da zadržava dvojezične oznake.
     *
     * EN: Proves that the catalog contains only scopes from an enabled and
     * installed module while preserving bilingual labels.
     */
    public function testDiscoversScopesFromEnabledModulesOnly(): void
    {
        $this->writeDescriptor();
        $registry = $this->registry(
            ['vendor/enabled-module', 'vendor/disabled-module'],
            ['vendor/enabled-module' => $this->temporaryDirectory],
        );

        $this->assertSame(['users:read', 'users:create'], $registry->all());
        $this->assertSame('Korisnici', $registry->grouped()[0]['label']['hr']);
        $this->assertSame(
            ['users:create', 'users:*'],
            $registry->normalize([' users:create ', 'users:create', 'users:*']),
        );
    }

    /**
     * HR: Nepostojeći scope mora biti odbijen prije izdavanja ključa.
     *
     * EN: An unavailable scope must be rejected before a key is issued.
     */
    public function testRejectsScopeNotExposedByInstalledModules(): void
    {
        $this->writeDescriptor();
        $registry = $this->registry(
            ['vendor/enabled-module'],
            ['vendor/enabled-module' => $this->temporaryDirectory],
        );

        $this->expectException(RuntimeException::class);
        $registry->normalize(['calendar:write']);
    }

    /**
     * HR: Zapisuje neutralni dvojezični API descriptor lažnog modula.
     *
     * EN: Writes a neutral bilingual API descriptor for the fake module.
     */
    private function writeDescriptor(): void
    {
        $descriptor = <<<'PHP'
<?php

declare(strict_types=1);

return [
    'module' => 'auth',
    'resources' => [
        'users' => [
            'label' => ['hr' => 'Korisnici', 'en' => 'Users'],
            'scopes' => [
                'users:read' => [
                    'label' => ['hr' => 'Pregled', 'en' => 'Read'],
                    'description' => ['hr' => 'Pregled korisnika.', 'en' => 'Read users.'],
                ],
                'users:create' => [
                    'label' => ['hr' => 'Kreiranje', 'en' => 'Create'],
                    'description' => ['hr' => 'Kreiranje korisnika.', 'en' => 'Create users.'],
                ],
            ],
        ],
    ],
];
PHP;

        $this->assertNotFalse(file_put_contents($this->temporaryDirectory . '/config/api.php', $descriptor));
    }

    /**
     * HR: Sastavlja registar s kontroliranim popisom paketa i putanja.
     *
     * EN: Builds a registry with a controlled package and path list.
     *
     * @param list<string> $enabled
     * @param array<string,string> $installPaths
     */
    private function registry(array $enabled, array $installPaths): ApiScopeRegistry
    {
        $composer = $this->createMock(ComposerBridge::class);
        $composer
            ->method('isInstalled')
            ->willReturnCallback(static fn(string $package): bool => isset($installPaths[$package]));
        $composer
            ->method('getInstallPath')
            ->willReturnCallback(static fn(string $package): ?string => $installPaths[$package] ?? null);

        $config = $this->createMock(ConfigInterface::class);
        $config
            ->method('getAsArrayWithValuesAsNonEmptyStrings')
            ->with('app.modules.enabled')
            ->willReturn($enabled);

        return new ApiScopeRegistry($composer, $config);
    }
}
