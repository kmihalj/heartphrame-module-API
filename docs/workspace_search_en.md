# Workspace Search HTTP API

Croatian version: [workspace_search_hr.md](workspace_search_hr.md)

The route exists only while Workspace Search and its required Workspace,
Editor, Menu, Auth, and ORM modules are installed and enabled. Every request
requires a Bearer key with `workspace-search:read`.

## Route and filters

```text
GET /api/v1/workspace-search?q=agenda&lang=en&workspace=team&author=Ana&from=2026-01-01&to=2026-12-31&page=1&per_page=20
```

- `q` searches published titles, plain page text, and author names;
- `lang` prefers that locale and falls back to the configured site default;
- `workspace`, `author`, `from`, and `to` are optional filters;
- `page` and `per_page` control pagination up to the configured maximum.

The index contains no ACL rows. Before counting or returning anything, the
service calculates visible Workspaces, inherited page restrictions, active
publication workflows, and language fallback for the API-key owner. A guest
web search uses the same service with no user and therefore sees public pages
only. Restricted titles cannot leak through totals, snippets, or suggestions.

## cURL example

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/workspace-search?q=calendar&lang=en&per_page=10"
```

Representative response:

```json
{
  "data": [
    {
      "workspace_slug": "conference",
      "node_slug": "daily-program",
      "language": "en",
      "title": "Daily program",
      "snippet": "Conference calendar and room schedule ...",
      "author_name": "Ana Example",
      "published_at": "2026-08-12 10:00:00",
      "url": "/hfc/workspace/conference/daily-program"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 10,
    "total": 1,
    "pages": 1,
    "language": "en"
  },
  "links": {"self": "/hfc/api/v1/workspace-search?q=calendar&lang=en&per_page=10"}
}
```

## Plain PHP example

```php
<?php

declare(strict_types=1);

$url = rtrim((string)getenv('HPH_API_URL'), '/')
    . '/workspace-search?' . http_build_query([
        'q' => 'calendar',
        'lang' => 'en',
        'per_page' => 10,
    ]);
$context = stream_context_create(['http' => [
    'method' => 'GET',
    'header' => [
        'Authorization: Bearer ' . (string)getenv('HPH_API_TOKEN'),
        'Accept: application/json',
    ],
    'ignore_errors' => true,
]]);
$payload = json_decode((string)file_get_contents($url, false, $context), true, 512, JSON_THROW_ON_ERROR);

foreach ($payload['data'] ?? [] as $item) {
    printf("%s: %s\n", $item['workspace_slug'], $item['title']);
}
```

Run `vendor/bin/hph workspace-search:rebuild` after bulk imports or restores.
Normal requests perform a bounded automatic refresh according to
`config/workspace-search.php`.
