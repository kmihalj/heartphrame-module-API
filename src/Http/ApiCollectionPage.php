<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Http;

/**
 * HR: Nepromjenjivi rezultat cursor-paginacije koji tvornica odgovora pretvara
 *     u standardni API envelope.
 * EN: Immutable cursor-pagination result converted by the response factory
 *     into the standard API envelope.
 *
 * Početnici / Beginners:
 * HR: Ovaj objekt odvaja rezanje kolekcije od JSON formata HTTP odgovora.
 * EN: This object separates collection slicing from the HTTP response's JSON format.
 *
 * @param list<mixed> $items
 */
final readonly class ApiCollectionPage
{
    /**
     * HR: Sprema elemente stranice, metapodatke i poveznice za navigaciju.
     * EN: Stores page items, metadata, and navigation links.
     *
     * @param list<mixed> $items
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $links
     */
    public function __construct(
        public array $items,
        public array $meta,
        public array $links,
    ) {
    }
}
