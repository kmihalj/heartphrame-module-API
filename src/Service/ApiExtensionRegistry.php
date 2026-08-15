<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Contract\ApiExtensionInterface;
use AaiEduHr\HeartPhrameModuleApi\Contract\ApiRouteRegistry;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * HR: Otkriva API proširenja isključivo iz `config/api.php` uključenih modula.
 *     API jezgra zato ne poznaje nijedan domenski modul niti njegov kontroler.
 *
 * EN: Discovers API extensions exclusively from enabled modules' `config/api.php`.
 *     The API core therefore knows no domain module or domain controller.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiExtensionRegistryTest
 */
final readonly class ApiExtensionRegistry
{
    /** HR: Prima stanje modula, spremnik i registar ruta. EN: Receives module state, the container, and route registry. */
    public function __construct(
        private ComposerBridge $composer,
        private ConfigInterface $config,
        private ContainerInterface $container,
        private ApiRouteRegistry $routes,
    ) {
    }

    /**
     * HR: Registrira svako valjano i jedinstveno proširenje uključenih modula.
     * EN: Registers every valid and unique extension from enabled modules.
     */
    public function registerEnabled(): void
    {
        $registered = [];
        foreach ($this->enabledDescriptors() as $descriptor) {
            $extensionClass = $descriptor['extension'] ?? null;
            if (!is_string($extensionClass)) {
                continue;
            }

            if (trim($extensionClass) === '') {
                continue;
            }

            if (!class_exists($extensionClass) || !$this->container->has($extensionClass)) {
                throw new RuntimeException('Enabled API extension service is unavailable: ' . $extensionClass);
            }

            $extension = $this->container->get($extensionClass);
            if (!$extension instanceof ApiExtensionInterface) {
                throw new RuntimeException('API extension must implement ApiExtensionInterface: ' . $extensionClass);
            }

            $id = trim($extension->id());
            if ($id === '') {
                throw new RuntimeException('API extension identifier cannot be empty: ' . $extensionClass);
            }

            if (isset($registered[$id])) {
                throw new RuntimeException('Duplicate API extension identifier: ' . $id);
            }

            $extension->register($this->routes);
            $registered[$id] = true;
        }
    }

    /**
     * HR: Učitava neutralne API opise samo iz instaliranih i uključenih modula.
     * EN: Loads neutral API descriptors only from installed and enabled modules.
     *
     * @return list<array{extension:mixed}>
     */
    private function enabledDescriptors(): array
    {
        $descriptors = [];
        $enabled = $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];
        foreach ($enabled as $package) {
            if (!$this->composer->isInstalled($package)) {
                continue;
            }

            $path = $this->composer->getInstallPath($package);
            $descriptorPath = is_string($path) ? rtrim($path, '/') . '/config/api.php' : '';
            if ($descriptorPath === '') {
                continue;
            }

            if (!is_file($descriptorPath)) {
                continue;
            }

            try {
                $descriptor = require $descriptorPath;
            } catch (Throwable $throwable) {
                throw new RuntimeException(
                    'Unable to load API descriptor for enabled module "' . $package . '".',
                    0,
                    $throwable,
                );
            }

            if (!is_array($descriptor)) {
                throw new RuntimeException('API descriptor must return an array: ' . $descriptorPath);
            }

            $descriptors[] = ['extension' => $descriptor['extension'] ?? null];
        }

        return $descriptors;
    }
}
