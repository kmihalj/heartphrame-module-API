<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Contract;

/**
 * HR: Ugovor kojim uključeni HeartPhrame modul oglašava vlastite API rute.
 *     Implementacija pripada vlasničkom modulu, dok API modul pruža samo
 *     autentikaciju, transportne ugovore i zajednički registar ruta.
 *
 * EN: Contract used by an enabled HeartPhrame module to advertise its own API
 *     routes. The implementation belongs to the owning module, while the API
 *     module provides authentication, transport contracts, and the shared
 *     route registry only.
 */
interface ApiExtensionInterface
{
    /**
     * HR: Vraća stabilni identifikator proširenja radi otkrivanja duplikata.
     * EN: Returns the stable extension identifier used for duplicate detection.
     */
    public function id(): string;

    /**
     * HR: Registrira sve rute vlasničkog modula u zajednički API registar.
     * EN: Registers every owning-module route with the shared API registry.
     */
    public function register(ApiRouteRegistry $routes): void;
}
