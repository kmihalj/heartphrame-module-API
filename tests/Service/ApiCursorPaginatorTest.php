<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use HeartPhrame\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava stabilni cursor ugovor zajedničkih API kolekcija.
 * EN: Verifies the stable cursor contract used by shared API collections.
 *
 * @see ApiCursorPaginator
 */
#[CoversClass(ApiCursorPaginator::class)]
#[UsesClass(ApiCollectionPage::class)]
final class ApiCursorPaginatorTest extends TestCase
{
    /**
     * HR: Sljedeća poveznica čuva filtere i cursor vodi na iduću stranicu.
     * EN: The next link preserves filters and the cursor advances to the next page.
     */
    public function testPaginatesAndPreservesFilters(): void
    {
        $paginator = new ApiCursorPaginator();
        $firstRequest = (new Request(
            'GET',
            'https://example.test/api/v1/pages?filter%5Blang%5D=hr&page%5Blimit%5D=2',
        ))->withQueryParams(['filter' => ['lang' => 'hr'], 'page' => ['limit' => 2]]);
        $first = $paginator->paginate(
            $firstRequest,
            [['id' => 1], ['id' => 2], ['id' => 3]],
        );

        $this->assertSame([['id' => 1], ['id' => 2]], $first->items);
        $this->assertTrue($first->meta['page']['has_more']);
        $this->assertIsString($first->meta['page']['next_cursor']);
        $this->assertStringContainsString('filter%5Blang%5D=hr', (string)$first->links['next']);

        $cursor = $first->meta['page']['next_cursor'];
        $secondRequest = (new Request('GET', 'https://example.test' . $first->links['next']))
            ->withQueryParams([
                'filter' => ['lang' => 'hr'],
                'page' => ['limit' => 2, 'after' => $cursor],
            ]);
        $second = $paginator->paginate(
            $secondRequest,
            [['id' => 1], ['id' => 2], ['id' => 3]],
        );

        $this->assertSame([['id' => 3]], $second->items);
        $this->assertFalse($second->meta['page']['has_more']);
        $this->assertNull($second->links['next']);
    }

    /**
     * HR: Nevaljani cursor sigurno započinje od prve stranice.
     * EN: An invalid cursor safely starts from the first page.
     */
    public function testInvalidCursorStartsAtBeginning(): void
    {
        $request = (new Request(
            'GET',
            'https://example.test/api/v1/groups?page%5Bafter%5D=invalid',
        ))->withQueryParams(['page' => ['after' => 'invalid']]);
        $page = (new ApiCursorPaginator())->paginate(
            $request,
            [['id' => 1], ['id' => 2]],
        );

        $this->assertSame([['id' => 1], ['id' => 2]], $page->items);
    }
}
