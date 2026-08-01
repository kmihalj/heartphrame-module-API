# HTTP API Task modula

English version: [task_en.md](task_en.md)

Rute postoje samo dok je Task instaliran i uključen. Rade s definicijama u
aktualno objavljenoj stranici, nikada s nacrtom.

## Scopeovi i rute

- `task:read`: popis, jedan zadatak i audit povijest.
- `task:write`: idempotentna promjena stanja.

```text
GET /api/v1/pages/{documentId}/tasks?lang=hr
GET /api/v1/pages/{documentId}/tasks/{taskUuid}?lang=hr
PUT /api/v1/pages/{documentId}/tasks/{taskUuid}/state?lang=hr
GET /api/v1/pages/{documentId}/tasks/{taskUuid}/history?lang=hr&limit=50
```

## cURL primjer i odgovor

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/pages/42/tasks?lang=hr"
```

```json
{
  "data": [{"uuid": "task-...", "label": "Objavi upute", "completed": false}],
  "meta": {"request_id": "req_..."},
  "links": {"self": "/api/v1/pages/42/tasks?lang=hr"}
}
```

Definicije dolaze iz objavljenog Editor dokumenta, a promjenjivo stanje iz Task
tablica. PHP i write primjeri nalaze se u [brzom početku](quickstart_hr.md).

Tijelo promjene stanja:

```json
{"completed": true}
```

Vlasnik API ključa mora smjeti čitati stranicu. Task `editors` dodatno
zahtijeva pravo uređivanja, a `viewers` smije mijenjati svaki prijavljeni
čitatelj. Stvarni prijelaz zapisuje korisnika i vrijeme. Ponavljanje iste
željene vrijednosti ne stvara dupli događaj ni novu verziju stranice.

Za kreiranje i izmjenu definicije taska koristi se API stranice HTML Editora.
