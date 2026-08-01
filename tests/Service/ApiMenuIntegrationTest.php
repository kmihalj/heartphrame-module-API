<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Service\ApiMenuIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(ApiMenuIntegration::class)]
final class ApiMenuIntegrationTest extends TestCase
{
    /**
     * HR: API modul osvježava postojeću stavku bez promjene ručno podešenog
     * redoslijeda u zajedničkom settings meniju.
     *
     * EN: The API module refreshes an existing item without changing its
     * manually configured order in the shared settings menu.
     */
    public function testUpdatesOwnedMenuItemAndPreservesOrder(): void
    {
        $integration = new ApiMenuIntegration($this->emptyContainer());
        $method = new ReflectionMethod(ApiMenuIntegration::class, 'update');
        $items = [[
            'id' => 'auth',
            'children' => [[
                'id' => 'auth.setup.api-keys',
                'route' => 'stale.route',
                'order' => 345,
            ]],
        ]];

        $arguments = [&$items];
        $this->assertTrue($method->invokeArgs($integration, $arguments));
        $this->assertSame('auth.setup.api-keys', $items[0]['children'][0]['route']);
        $this->assertSame(345, $items[0]['children'][0]['order']);
    }

    /**
     * HR: Vraća prazan kontejner jer privatni test ne koristi opcionalni Menu.
     *
     * EN: Returns an empty container because the private test does not use the
     * optional Menu module.
     */
    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            /**
             * HR: Odbija dohvat servisa u izoliranom testu.
             *
             * EN: Rejects service lookup in the isolated test.
             */
            public function get(string $id): mixed
            {
                throw new RuntimeException('Service is intentionally unavailable: ' . $id);
            }

            /**
             * HR: Potvrđuje da testni kontejner nema servise.
             *
             * EN: Confirms that the test container has no services.
             */
            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
