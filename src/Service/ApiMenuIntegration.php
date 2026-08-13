<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * HR: Uključuje upravljanje API ključevima u postavke samo kada je Menu dostupan.
 *
 * EN: Adds API-key administration to settings only when Menu is available.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiMenuIntegrationTest
 */
final readonly class ApiMenuIntegration
{
    private const MENU_REPOSITORY = \AaiEduHr\HeartPhrameModuleMenu\Service\MenuConfigRepository::class;

    /**
     * HR: Prima container bez tvrde ovisnosti o opcionalnom Menu modulu.
     *
     * EN: Receives the container without a hard dependency on the optional Menu module.
     */
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * HR: Dodaje stavku API ključeva pod postojeću Auth grupu postavki.
     *
     * EN: Adds the API-key item below the existing Auth settings group.
     */
    public function registerSettingsMenuItem(): void
    {
        if (!class_exists(self::MENU_REPOSITORY)) {
            return;
        }

        try {
            $repository = $this->container->get(self::MENU_REPOSITORY);
            if (!is_object($repository) || !method_exists($repository, 'jsonPathForSection')) {
                return;
            }

            $path = $repository->jsonPathForSection('settings');
            if (!is_string($path) || $path === '') {
                return;
            }

            $items = $this->read($path);
            $original = $items;
            if (!$this->update($items)) {
                $items[] = $this->definition();
            }

            if ($items !== $original) {
                $this->write($path, $items);
            }
        } catch (Throwable) {
            // HR: Menu integracija je opcionalna i ne smije zaustaviti API.
            // EN: Menu integration is optional and must not stop the API.
        }
    }

    /**
     * HR: Čita postojeće stablo postavki.
     *
     * EN: Reads the existing settings tree.
     *
     * @return list<array<string,mixed>>
     */
    private function read(string $path): array
    {
        $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;

        return $this->rows($decoded);
    }

    /**
     * HR: Osvježava postojeću stavku na mjestu i čuva ručno podešen redoslijed.
     *
     * EN: Refreshes the existing item in place while preserving manual ordering.
     *
     * @param list<array<string,mixed>> $items
     */
    private function update(array &$items): bool
    {
        foreach ($items as &$item) {
            if (($item['id'] ?? null) === 'auth.setup.api-keys') {
                $order = $item['order'] ?? null;
                $item = array_replace($item, $this->definition());
                if (is_numeric($order)) {
                    $item['order'] = (int)$order;
                }

                unset($item);

                return true;
            }

            $children = $this->rows($item['children'] ?? null);
            if ($children !== [] && $this->update($children)) {
                $item['children'] = $children;
                unset($item);

                return true;
            }
        }

        unset($item);

        return false;
    }

    /**
     * HR: Vraća definiciju stavke koju API modul posjeduje.
     *
     * EN: Returns the menu-item definition owned by the API module.
     *
     * @return array<string,mixed>
     */
    private function definition(): array
    {
        return [
            'id' => 'auth.setup.api-keys',
            'parent_id' => 'auth',
            'label' => ['hr' => 'API ključevi', 'en' => 'API keys'],
            'route' => 'auth.setup.api-keys',
            'url' => '',
            'query' => '',
            'order' => 70,
            'enabled' => true,
            'level' => 1,
        ];
    }

    /**
     * HR: Atomarno zapisuje settings JSON.
     *
     * EN: Atomically writes the settings JSON.
     *
     * @param list<array<string,mixed>> $items
     */
    private function write(string $path, array $items): void
    {
        $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json . PHP_EOL) !== false) {
            rename($temporary, $path);
        }
    }

    /**
     * HR: Normalizira miješanu vrijednost u listu menu redaka.
     *
     * EN: Normalizes a mixed value into a menu-row list.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = [];
            foreach ($row as $key => $itemValue) {
                if (is_string($key)) {
                    $normalized[$key] = $itemValue;
                }
            }

            $rows[] = $normalized;
        }

        return $rows;
    }
}
