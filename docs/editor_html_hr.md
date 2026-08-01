# HTTP API HTML Editora

English version: [editor_html_en.md](editor_html_en.md)

Rute u nastavku registriraju se samo kada je
`aaieduhr/heartphrame-module-editor-html` instaliran i uključen. Sve zahtijevaju
`Authorization: Bearer <token>`. Potrebni scope ograničava ključ, a vlasnik
ključa neovisno mora proći Editor ili Workspace ACL.

## Scopeovi

| Scope | Radnje |
| --- | --- |
| `page:read` | Popis i čitanje objavljenih stranica i verzija |
| `page:write` | Kreiranje, izmjena, brisanje, prijevod, vraćanje i odbacivanje nacrta |
| `page:publish` | Objavljivanje Workspace nacrta |
| `attachment:read` | Popis, pregled i preuzimanje privitaka |
| `attachment:write` | Upload, izmjena, brisanje, prekid dijelova i vidljivost |

`page:write` namjerno uključuje kreiranje jer nova Workspace stranica započinje
kao nacrt. Objavljivanje ostaje zasebno pravo.

## Rute stranica

```text
GET    /api/v1/pages
POST   /api/v1/pages
GET    /api/v1/pages/{documentId}
PATCH  /api/v1/pages/{documentId}
DELETE /api/v1/pages/{documentId}
GET    /api/v1/pages/{documentId}/draft
DELETE /api/v1/pages/{documentId}/draft
POST   /api/v1/pages/{documentId}/publish
POST   /api/v1/pages/{documentId}/translations
```

Za rutu koja čita jedan jezik koristi se query parametar `lang`. Kreiranje
Workspace stranice zahtijeva `workspace_slug`, a može imati i `parent_id`.
Izmjena Workspace nacrta mora poslati zadnji `draft_revision`; zastarjela
izmjena vraća `409 Conflict`. Obični endpoint stranice uvijek vraća objavljeni
sadržaj.

U samostalnom načinu uspješna izmjena odmah objavljuje verziju, a endpointi
nacrta ili objave vraćaju konflikt. U Workspace načinu kreiranje i uređivanje
mijenjaju jedan zajednički nacrt dok ga ovlašteni objavljivač ne objavi.

Kreiranje i izmjena prihvaćaju točno jedno od polja `html` ili uređeni
`content`. Strukturirani sadržaj podržava bilo koju kombinaciju:

- `{"type":"html","html":"..."}`;
- `{"type":"calendar", ...}` s jednim ili više `calendar_uuids`;
- `{"type":"task_list", ...}` s jednim ili više task `items`.

Vraćena stranica, nacrt i verzija sadrže kanonski `html`, uređeni povratno
zapisiv `content` i ravnu listu `embeds`. Pojedinačnom čitanju objavljene
stranice dodajte `rendered=1` za ACL-aware `rendered_html` s aktualnim
kalendarima i stanjem zadataka. Potpuna shema blokova nalazi se u API vodiču
Editor modula.

Calendar zapis u `embeds` vraća `available`, `forbidden`, `deleted` ili
`partial` za mješoviti blok, uz status svakog UUID-a. Postojeća referenca na
kasnije obrisani kalendar ostaje u `content` i `html`; `rendered_html` na
njezinu mjestu prikazuje poruku da kalendar više nije dostupan. Novi blok ne
može umetnuti nedostupan UUID.

## Rute verzija

```text
GET  /api/v1/pages/{documentId}/versions
GET  /api/v1/pages/{documentId}/versions/{versionNumber}
POST /api/v1/pages/{documentId}/versions/{versionNumber}/restore
```

Workspace povijest sadrži samo objavljene verzije. Vraćanje stvara nacrt u
Workspace načinu, a odmah objavljenu verziju u samostalnom načinu.

## Rute privitaka

```text
GET    /api/v1/pages/{documentId}/attachments
POST   /api/v1/pages/{documentId}/attachments
POST   /api/v1/pages/{documentId}/attachments/chunks
DELETE /api/v1/pages/{documentId}/attachments/chunks/{uploadId}
PUT    /api/v1/pages/{documentId}/attachment-visibility
GET    /api/v1/pages/{documentId}/attachments/{assetUuid}
GET    /api/v1/pages/{documentId}/attachments/{assetUuid}/content
PATCH  /api/v1/pages/{documentId}/attachments/{assetUuid}
DELETE /api/v1/pages/{documentId}/attachments/{assetUuid}
```

Obični i chunk upload koriste `multipart/form-data`. Međudijelovi vraćaju
`202 Accepted`, a završni dio i obični upload vraćaju `201 Created`. Prekid
vraća `204 No Content` i uklanja prenesene dijelove. Ruta sadržaja šalje izvorne
bajtove sa spremljenim MIME tipom i nazivom datoteke.

## Odgovori i greške

### cURL primjer čitanja

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/pages"
```

Reprezentativni odgovor:

```json
{
  "data": [{"id": 42, "document_key": "dobrodosli", "language": "hr"}],
  "meta": {"request_id": "req_..."},
  "links": {"self": "/api/v1/pages"}
}
```

Rezultat je filtriran ACL-om. PHP klijent, idempotentni write primjer i obrada
problem odgovora nalaze se u [brzom početku](quickstart_hr.md).

JSON resursi koriste zajednički API envelope:

```json
{
  "data": {},
  "meta": {"request_id": "..."},
  "links": {"self": "/hfc/api/v1/pages/42"}
}
```

Validacija, nepostojeći resursi, zabrane, konflikti i interne greške koriste
zajednički RFC 9457 problem odgovor sa stabilnim `code` i `request_id`.
Uobičajeni statusi su `401`, `403`, `404`, `409` i `422`.

Potpuno poslovno ponašanje samostalnog i Workspace načina dokumentirano je u
HTML Editor modulu pod naslovom **API integracija**.
