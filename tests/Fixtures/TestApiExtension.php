<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Fixtures;

use AaiEduHr\HeartPhrameModuleApi\Contract\ApiExtensionInterface;
use AaiEduHr\HeartPhrameModuleApi\Contract\ApiRouteRegistry;

/** HR: Proširenje za test otkrivanja. EN: Extension used by discovery tests. */
final class TestApiExtension implements ApiExtensionInterface
{
    /** HR: Bilježi broj registracija. EN: Tracks the registration count. */
    public int $registrations = 0;

    /** HR: Vraća testni identifikator. EN: Returns the test identifier. */
    public function id(): string
    {
        return 'test-extension';
    }

    /** HR: Bilježi poziv registra. EN: Records the registry invocation. */
    public function register(ApiRouteRegistry $routes): void
    {
        ++$this->registrations;
    }
}
