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

Raspon događaja uključuje oba rubna datuma. `expand_recurring=0` vraća izvorne
događaje bez proširenih ponavljanja. Zamjena ACL-a prima JSON listu `rules` s
korisničkim/grupnim subjektima i read/write zastavicama. ICS vraća
`text/calendar`, a ostali uspješni resursi zajednički JSON envelope.

Calendar ugradnja Editora sprema UUID-eve i parametre prikaza. Ovim rutama
uređuju se živi kalendarski podaci koji se renderiraju u stranici.

`DELETE /api/v1/calendars/{calendarUuid}` trajno briše kalendar, događaje,
pretplate i ACL; nema soft-delete ni restore rute. Postojeći Editor blok ostaje
u dokumentu, a njegov render nakon brisanja prikazuje jasnu poruku o obrisanom
kalendaru.
