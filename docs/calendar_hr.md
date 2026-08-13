# HTTP API Calendara

English version: [calendar_en.md](calendar_en.md)

Rute postoje samo dok je Calendar instaliran i uključen. Svaki zahtjev treba
Bearer ključ s navedenim scopeom, a vlasnik ključa mora proći i Calendar ACL.

## Scopeovi

- `calendar:read`: vidljivi kalendari, događaji i ICS izvoz.
- `calendar:write`: CRUD kalendara/događaja i potpuna zamjena ACL-a.

## Rute

```text
GET    /api/v1/calendars
POST   /api/v1/calendars
POST   /api/v1/calendars/import
GET    /api/v1/calendars/{calendarUuid}
PATCH  /api/v1/calendars/{calendarUuid}
DELETE /api/v1/calendars/{calendarUuid}
PUT    /api/v1/calendars/{calendarUuid}/acl
GET    /api/v1/calendars/{calendarUuid}/export.ics
GET    /api/v1/calendars/{calendarUuid}/events?from=YYYY-MM-DD&to=YYYY-MM-DD
POST   /api/v1/calendars/{calendarUuid}/events
GET    /api/v1/calendars/{calendarUuid}/events/{eventId}
PATCH  /api/v1/calendars/{calendarUuid}/events/{eventId}
DELETE /api/v1/calendars/{calendarUuid}/events/{eventId}
```

## cURL primjer i odgovor

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/calendars"
```

Reprezentativni odgovor:

```json
{
  "data": [{"uuid": "6d5e...", "name": "Timski kalendar"}],
  "meta": {"request_id": "req_..."},
  "links": {"self": "/api/v1/calendars"}
}
```

Vraćaju se samo kalendari koje vlasnik API ključa smije čitati. Zajednički
PHP klijent i obrada grešaka nalaze se u [brzom početku](quickstart_hr.md).

Raspon događaja uključuje oba rubna datuma. `expand_recurring=0` vraća izvorne
događaje bez proširenih ponavljanja. Zamjena ACL-a prima JSON listu `rules` s
korisničkim/grupnim subjektima i read/write zastavicama. ICS vraća
`text/calendar`, a ostali uspješni resursi zajednički JSON envelope.

Calendar ugradnja Editora sprema UUID-eve i parametre prikaza. Ovim rutama
uređuju se živi kalendarski podaci koji se renderiraju u stranici.

Ruta uvoza prihvaća isti payload kao web uvoz Calendara i ponovno provjerava
pravo pisanja vlasnika API ključa. Minimalni primjer:

```bash
curl --fail-with-body --silent --show-error \
  --request POST \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Content-Type: application/json' \
  --data '{"name":"Uvezeni raspored","ics":"BEGIN:VCALENDAR\\r\\nVERSION:2.0\\r\\nEND:VCALENDAR\\r\\n"}' \
  "$HPH_API_URL/calendars/import"
```

Polje `ics` je obavezno. Opcije naziva/UUID-a i brojači uvoza slijede domenski
ugovor uvoza, a neispravni iCalendar vraća zajednički validacijski problem.

`DELETE /api/v1/calendars/{calendarUuid}` trajno briše kalendar, događaje,
pretplate i ACL; nema soft-delete ni restore rute. Postojeći Editor blok ostaje
u dokumentu, a njegov render nakon brisanja prikazuje jasnu poruku o obrisanom
kalendaru.
