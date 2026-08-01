<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * HR: Renderira prikaze API modula uz podršku aplikacijskih override datoteka.
 *
 * EN: Renders API-module views with support for application override files.
 */
final readonly class ApiModuleViewRenderer
{
    /**
     * HR: Inicijalizira renderer s podrškom za aplikacijski override prikaza.
     *
     * EN: Initializes the renderer with application view-override support.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private ConfigInterface $config,
    ) {
    }

    /**
     * HR: Renderira API prikaz iz aplikacijskog overridea ili samog modula.
     *
     * EN: Renders an API view from an application override or the module itself.
     *
     * @param array<string,mixed> $data
     */
    public function render(
        string $view,
        array $data = [],
        null|true|string $layout = true,
        int $status = 200,
    ): ResponseInterface {
        $override = $this->findOverrideView($view);
        if ($override !== null) {
            return $this->responseFactory->view($override, $data, $layout, $status);
        }

        return $this->responseFactory->viewForModule(
            ModuleApi::PACKAGE_NAME,
            $view,
            $data,
            $layout,
            $status,
        );
    }

    /**
     * HR: Traži kratku i punu aplikacijsku override putanju.
     *
     * EN: Searches the short and fully-qualified application override paths.
     */
    private function findOverrideView(string $view): ?string
    {
        $root = rtrim($this->config->getAsString('app.views.path') ?? '', '/');
        if ($root === '') {
            return null;
        }

        foreach (
            [
                'modules/heartphrame-module-api/' . $view,
                'modules/aaieduhr/heartphrame-module-api/' . $view,
            ] as $candidate
        ) {
            if (is_file($root . '/' . $candidate . '.php')) {
                return $candidate;
            }
        }

        return null;
    }
}
