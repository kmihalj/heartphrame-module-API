# Workspace Search HTTP API

English version: [workspace_search_en.md](workspace_search_en.md)

Ruta postoji samo dok su Workspace Search i njegovi obavezni moduli Workspace,
Editor, Menu, Auth i ORM instalirani i uključeni. Svaki zahtjev treba Bearer
ključ sa scopeom `workspace-search:read`.

## Ruta i filtri

```text
GET /api/v1/workspace-search?q=raspored&lang=hr&workspace=tim&author=Ana&from=2026-01-01&to=2026-12-31&page=1&per_page=20
```

- `q` pretražuje objavljene naslove, obični tekst stranice i ime autora;
- `lang` daje prednost zadanom localeu, uz fallback na zadani jezik sitea;
- `workspace`, `author`, `from` i `to` opcionalni su filtri;
- `page` i `per_page` određuju straničenje do konfiguriranog maksimuma.

Indeks ne sadrži ACL retke. Prije brojanja ili vraćanja bilo čega servis za
vlasnika API ključa računa vidljiva područja, naslijeđena ograničenja stranice,
aktivni tijek objave i jezični fallback. Web pretraga gosta koristi isti servis
bez korisnika i zato vidi samo javne stranice. Ograničeni naslovi ne mogu
procuriti kroz broj rezultata, isječke ni prijedloge.

## cURL primjer

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/workspace-search?q=kalendar&lang=hr&per_page=10"
```

Primjer odgovora:

```json
{
  "data": [
    {
      "workspace_slug": "konferencija",
      "node_slug": "dnevni-program",
      "language": "hr",
      "title": "Dnevni program",
      "snippet": "Kalendar konferencije i raspored dvorana ...",
      "author_name": "Ana Primjer",
      "published_at": "2026-08-12 10:00:00",
      "url": "/hfc/workspace/konferencija/dnevni-program"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 10,
    "total": 1,
    "pages": 1,
    "language": "hr"
  },
  "links": {"self": "/hfc/api/v1/workspace-search?q=kalendar&lang=hr&per_page=10"}
}
```

## Primjer u običnom PHP-u

```php
<?php

declare(strict_types=1);

$url = rtrim((string)getenv('HPH_API_URL'), '/')
    . '/workspace-search?' . http_build_query([
        'q' => 'kalendar',
        'lang' => 'hr',
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

Nakon masovnog uvoza ili vraćanja backupa pokrenite
`vendor/bin/hph workspace-search:rebuild`. Obični zahtjevi rade ograničeno
automatsko osvježavanje prema `config/workspace-search.php`.
