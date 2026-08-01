# HTTP API Notification modula

Notification rute postoje samo kada je Notification modul instaliran i
uključen:

```text
GET    /api/v1/notifications
GET    /api/v1/notifications/{uuid}
PATCH  /api/v1/notifications/{uuid}
POST   /api/v1/notifications/read-all
DELETE /api/v1/notifications/{uuid}
```

## cURL primjer i odgovor

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/notifications"
```

```json
{
  "data": [{"uuid": "79aa...", "title": "Zatražen pregled", "is_read": false}],
  "meta": {"request_id": "req_..."},
  "links": {"self": "/api/v1/notifications"}
}
```

Ruta uvijek koristi vlasnika API ključa; nije moguće poslati ID korisnika i
čitati tuđi inbox. PHP primjer je u [brzom početku](quickstart_hr.md).

`notifications:read` dopušta popis i čitanje poruka vlasnika API ključa.
`notifications:write` dopušta promjenu stanja pročitanosti i uklanjanje vlastite
pročitane poruke. API ključ nikada ne može pregledavati ni mijenjati tuđi inbox.

API namjerno nema rutu za stvaranje proizvoljnih obavijesti. Domenski moduli ih
stvaraju kao posljedicu stvarne poslovne radnje. Primjerice, slanje Workspace
nacrta na pregled kroz Editor API obavještava efektivne objavljivače jednako
kao radnja u web sučelju.

E-mail kopije nisu API resurs. One su opcionalni interni transport i pokušavaju
se poslati samo kada ih je primatelj uključio u osobnim postavkama i kada je
E-mail modul dostupan.
