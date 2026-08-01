<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use HeartPhrame\Config\ConfigInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HR: Stvara stabilne ETag oznake i štiti izmjene od prepisivanja novijeg stanja.
 * EN: Creates stable ETag validators and protects writes from overwriting newer state.
 *
 * Početnici / Beginners:
 * HR: Klijent prvo pročita resurs, zapamti njegov `ETag`, a zatim ga pošalje u
 *     `If-Match` zaglavlju. Ako je resurs u međuvremenu promijenjen, izmjena se odbija.
 * EN: A client reads a resource, remembers its `ETag`, and sends it in the
 *     `If-Match` header. A write is rejected if the resource changed meanwhile.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiEntityTagServiceTest
 */
final readonly class ApiEntityTagService
{
    /**
     * HR: Prima konfiguraciju koja omogućuje postupno uključivanje obaveznog uvjeta.
     * EN: Receives configuration that allows gradual enforcement of the required condition.
     */
    public function __construct(private ConfigInterface $config)
    {
    }

    /**
     * HR: Vraća snažni ETag iz kanoniziranog javnog prikaza resursa.
     * EN: Returns a strong ETag derived from the resource's canonical public representation.
     */
    public function tag(mixed $resource): string
    {
        $json = json_encode(
            $this->canonicalize($resource),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return '"' . hash('sha256', $json) . '"';
    }

    /**
     * HR: Provjerava da `If-Match` odgovara trenutačnom resursu.
     * EN: Verifies that `If-Match` matches the current resource.
     *
     * @throws ApiPreconditionException
     */
    public function assertMatches(
        ServerRequestInterface $request,
        mixed $currentResource,
    ): void {
        $header = trim($request->getHeaderLine('If-Match'));
        if ($header === '') {
            if (!$this->config->getAsBoolean('api.require_if_match', true)) {
                return;
            }

            throw new ApiPreconditionException(
                'if_match_required',
                428,
                __('Prije izmjene pročitajte resurs i pošaljite njegov ETag kao If-Match.'),
            );
        }

        if ($header === '*') {
            return;
        }

        $current = $this->tag($currentResource);
        $candidates = array_map(trim(...), explode(',', $header));
        if (!in_array($current, $candidates, true)) {
            throw new ApiPreconditionException(
                'resource_changed',
                412,
                __('Resurs je promijenjen. Ponovno ga pročitajte i ponovite zahtjev s novim ETagom.'),
            );
        }
    }

    /**
     * HR: Rekurzivno sortira ključeve objekata kako redoslijed polja ne bi mijenjao ETag.
     * EN: Recursively sorts object keys so field order does not change the ETag.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
