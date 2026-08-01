<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use RuntimeException;
use Throwable;

/**
 * HR: Prikuplja i validira scopeove koje prijavljuju trenutačno uključeni moduli.
 *
 * EN: Collects and validates scopes advertised by currently enabled modules.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiScopeRegistryTest
 */
final readonly class ApiScopeRegistry
{
    /**
     * HR: Inicijalizira dinamički katalog scopeova iz uključenih modula.
     *
     * EN: Initializes the dynamic scope catalog from enabled modules.
     */
    public function __construct(
        private ComposerBridge $composer,
        private ConfigInterface $config,
    ) {
    }

    /**
     * HR: Vraća sve trenutno dostupne scope oznake.
     *
     * EN: Returns all scope identifiers currently available.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $scopes = [];
        foreach ($this->grouped() as $group) {
            foreach ($group['scopes'] as $scope) {
                $scopes[] = $scope['name'];
            }
        }

        return $scopes;
    }

    /**
     * HR: Grupira scopeove po resursu zajedno s dvojezičnim opisima za GUI.
     *
     * EN: Groups scopes by resource together with bilingual GUI descriptions.
     *
     * @return list<array{
     *   module:string,
     *   resource:string,
     *   label:array{hr:string,en:string},
     *   scopes:list<array{
     *     name:string,
     *     label:array{hr:string,en:string},
     *     description:array{hr:string,en:string}
     *   }>
     * }>
     */
    public function grouped(): array
    {
        $groups = [];
        $seenScopes = [];

        foreach ($this->enabledDescriptors() as $descriptor) {
            $module = is_scalar($descriptor['module'] ?? null) ? trim((string)$descriptor['module']) : '';
            $resources = is_array($descriptor['resources'] ?? null) ? $descriptor['resources'] : [];

            foreach ($resources as $resource => $definition) {
                if (!is_string($resource)) {
                    continue;
                }

                if (!$this->validResource($resource)) {
                    continue;
                }

                if (!is_array($definition)) {
                    continue;
                }

                $scopes = [];
                $scopeDefinitions = is_array($definition['scopes'] ?? null) ? $definition['scopes'] : [];
                foreach ($scopeDefinitions as $scope => $scopeDefinition) {
                    if (!is_string($scope)) {
                        continue;
                    }

                    if (!is_array($scopeDefinition)) {
                        continue;
                    }

                    if (!$this->validScope($scope)) {
                        continue;
                    }

                    if (!str_starts_with($scope, $resource . ':')) {
                        continue;
                    }

                    if (isset($seenScopes[$scope])) {
                        throw new RuntimeException('Duplicate API scope descriptor: ' . $scope);
                    }

                    $seenScopes[$scope] = true;
                    $scopes[] = [
                        'name' => $scope,
                        'label' => $this->localized($scopeDefinition['label'] ?? null, $scope),
                        'description' => $this->localized($scopeDefinition['description'] ?? null, ''),
                    ];
                }

                if ($scopes === []) {
                    continue;
                }

                $groups[] = [
                    'module' => $module,
                    'resource' => $resource,
                    'label' => $this->localized($definition['label'] ?? null, $resource),
                    'scopes' => $scopes,
                ];
            }
        }

        return $groups;
    }

    /**
     * HR: Validira administratorov odabir prema katalogu instaliranih modula.
     *
     * EN: Validates the administrator selection against the installed-module catalog.
     *
     * @param iterable<mixed> $scopes
     * @return list<string>
     */
    public function normalize(iterable $scopes): array
    {
        $available = array_fill_keys($this->all(), true);
        $resources = [];
        foreach (array_keys($available) as $scope) {
            $resource = strstr($scope, ':', true);
            if (is_string($resource) && $resource !== '') {
                $resources[$resource] = true;
            }
        }

        $normalized = [];
        foreach ($scopes as $scope) {
            if (!is_scalar($scope)) {
                continue;
            }

            $scope = strtolower(trim((string)$scope));
            $resource = str_ends_with($scope, ':*') ? substr($scope, 0, -2) : '';
            if (!isset($available[$scope]) && ($resource === '' || !isset($resources[$resource]))) {
                throw new RuntimeException(__('Scope nije dostupan u uključenim modulima: ') . $scope);
            }

            $normalized[$scope] = true;
        }

        if ($normalized === []) {
            throw new RuntimeException(__('Odaberi barem jedan API scope.'));
        }

        return array_keys($normalized);
    }

    /**
     * HR: Učitava neutralne `config/api.php` opise samo iz uključenih modula.
     *
     * EN: Loads neutral `config/api.php` descriptors only from enabled modules.
     *
     * @return list<array<string,mixed>>
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
            } catch (Throwable) {
                continue;
            }

            if (!is_array($descriptor)) {
                continue;
            }

            $normalized = [];
            foreach ($descriptor as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }

            $descriptors[] = $normalized;
        }

        return $descriptors;
    }

    /**
     * HR: Normalizira dvojezični tekst uz sigurnu zadanu vrijednost.
     *
     * EN: Normalizes bilingual text with a safe fallback.
     *
     * @return array{hr:string,en:string}
     */
    private function localized(mixed $value, string $fallback): array
    {
        $value = is_array($value) ? $value : [];
        $hr = is_scalar($value['hr'] ?? null) ? trim((string)$value['hr']) : '';
        $en = is_scalar($value['en'] ?? null) ? trim((string)$value['en']) : '';

        return [
            'hr' => $hr !== '' ? $hr : ($en !== '' ? $en : $fallback),
            'en' => $en !== '' ? $en : ($hr !== '' ? $hr : $fallback),
        ];
    }

    /**
     * HR: Provjerava tehnički oblik naziva resursa.
     *
     * EN: Validates the technical resource-name shape.
     */
    private function validResource(string $resource): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]*$/D', $resource) === 1;
    }

    /**
     * HR: Provjerava tehnički oblik scope oznake.
     *
     * EN: Validates the technical scope-name shape.
     */
    private function validScope(string $scope): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]*:[a-z][a-z0-9_-]*$/D', $scope) === 1;
    }
}
