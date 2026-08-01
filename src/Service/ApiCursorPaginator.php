<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage;
use Psr\Http\Message\ServerRequestInterface;

use function array_slice;
use function base64_decode;
use function base64_encode;
use function count;
use function http_build_query;
use function is_array;
use function is_numeric;
use function is_scalar;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;
use function trim;

/**
 * HR: Ujednačava cursor-paginaciju API kolekcija koje domenski moduli vraćaju
 *     kao liste.
 * EN: Standardizes cursor pagination for API collections returned as lists by
 *     domain modules.
 *
 * Početnici / Beginners:
 * HR: Cursor skriva interni offset od klijenta. Klijent treba samo proslijediti
 *     vrijednost iz `links.next`, bez pokušaja čitanja njezina sadržaja.
 * EN: The cursor hides the internal offset from the client. A client only needs
 *     to follow `links.next` and must not interpret the cursor.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiCursorPaginatorTest
 */
final readonly class ApiCursorPaginator
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 100;

    /**
     * HR: Reže listu prema `page[limit]` i `page[after]` parametrima.
     * EN: Slices a list according to `page[limit]` and `page[after]` parameters.
     *
     * @param list<mixed> $items
     */
    public function paginate(
        ServerRequestInterface $request,
        array $items,
    ): ApiCollectionPage {
        ['limit' => $limit, 'offset' => $offset] = $this->parameters($request);
        $total = count($items);
        $slice = array_slice($items, $offset, $limit);

        return $this->pageFromWindow($request, $slice, $total);
    }

    /**
     * HR: Vraća ograničenje i offset za servise koji paginiraju izravno u bazi.
     * EN: Returns the limit and offset for services that paginate directly in the database.
     *
     * @return array{limit:int,offset:int}
     */
    public function parameters(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $page = is_array($query['page'] ?? null) ? $query['page'] : [];
        $limit = is_numeric($page['limit'] ?? null)
            ? max(1, min(self::MAX_LIMIT, (int)$page['limit']))
            : self::DEFAULT_LIMIT;

        return [
            'limit' => $limit,
            'offset' => $this->decodeCursor($page['after'] ?? null),
        ];
    }

    /**
     * HR: Gradi standardnu stranicu iz već dohvaćenog DB prozora i ukupnog broja.
     * EN: Builds a standard page from an already fetched database window and total count.
     *
     * @param list<mixed> $items
     */
    public function pageFromWindow(
        ServerRequestInterface $request,
        array $items,
        int $total,
    ): ApiCollectionPage {
        ['limit' => $limit, 'offset' => $offset] = $this->parameters($request);
        $nextOffset = $offset + count($items);
        $hasMore = $nextOffset < $total;
        $nextCursor = $hasMore ? $this->encodeCursor($nextOffset) : null;

        return new ApiCollectionPage(
            $items,
            [
                'page' => [
                    'limit' => $limit,
                    'total' => max(0, $total),
                    'has_more' => $hasMore,
                    'next_cursor' => $nextCursor,
                ],
            ],
            [
                'self' => $this->requestTarget($request),
                'next' => $nextCursor !== null
                    ? $this->nextTarget($request, $limit, $nextCursor)
                    : null,
            ],
        );
    }

    /**
     * HR: Kodira sljedeći interni offset u neprozirni URL-safe cursor.
     * EN: Encodes the next internal offset into an opaque URL-safe cursor.
     */
    private function encodeCursor(int $offset): string
    {
        $json = json_encode(['offset' => $offset], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * HR: Sigurno dekodira cursor; nevaljani cursor započinje od prve stranice.
     * EN: Safely decodes a cursor; an invalid cursor starts from the first page.
     */
    private function decodeCursor(mixed $cursor): int
    {
        if (!is_scalar($cursor) || trim((string)$cursor) === '') {
            return 0;
        }

        $encoded = strtr(trim((string)$cursor), '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $json = base64_decode($encoded, true);
        if (!is_string($json)) {
            return 0;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_numeric($decoded['offset'] ?? null)) {
            return 0;
        }

        return max(0, (int)$decoded['offset']);
    }

    /**
     * HR: Vraća trenutni relativni URL uključujući postojeći query string.
     * EN: Returns the current relative URL including the existing query string.
     */
    private function requestTarget(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();

        return $query !== '' ? $path . '?' . $query : $path;
    }

    /**
     * HR: Gradi iduću poveznicu uz očuvanje svih filtera i sortiranja.
     * EN: Builds the next link while preserving all filters and sorting.
     */
    private function nextTarget(
        ServerRequestInterface $request,
        int $limit,
        string $cursor,
    ): string {
        $query = $request->getQueryParams();
        $query['page'] = ['limit' => $limit, 'after' => $cursor];
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $request->getUri()->getPath() . ($encoded !== '' ? '?' . $encoded : '');
    }
}
