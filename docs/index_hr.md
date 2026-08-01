# API modul: arhitektura i uporaba

## Odgovornosti

API modul obrađuje HTTP protokol, ali ne čita tablice drugih modula:

1. `ApiAuthenticationMiddleware` provjerava Bearer ključ preko javnog Auth servisa.
2. `ApiScopeRegistry` čita scope opise uključenih modula.
3. Kontroler provjerava scope i delegira domenskom servisu.
4. Domenski modul provodi poslovna pravila i vraća sigurni DTO.
5. `ApiResponseFactory` stvara jedinstveni JSON ili `application/problem+json`.

Takva podjela početniku jasno pokazuje gdje pripada nova funkcionalnost, a
naprednom developeru omogućuje zamjenu HTTP sloja bez dupliranja domenskih
pravila.

## Ključevi

Ključ se kreira u **Postavke > Korisnici > API ključevi**. Tajna se prikazuje
samo jednom. U bazi se sprema samo sigurni hash. Rotacija odmah poništava staru
tajnu, a opoziv zadržava auditabilni zapis. Trajno brisanje uklanja redak
ključa, ali ostavlja zaseban audit događaj.

Polje vlasnika je pretraživi combobox sa serverskom pretragom pa ekran ne
renderira tisuće korisnika. Aktivni te opozvani/istekli ključevi nalaze se u
odvojenim sklopivim cjelinama. Zadnja uporaba i istek slijede aktivni locale;
prazne vrijednosti prikazuju se kao **Nikad** i **Bez isteka**.

Korisnik može poslati zahtjev sa svojeg Auth profila i istodobno imati najviše
jedan zahtjev koji čeka odluku. Administrator ga odobrava ili odbija na ekranu
ključeva. Odobrenje izdaje stvarni Auth ključ i šalje jednokratnu poveznicu za
preuzimanje, a odbijanje šalje odluku i opcionalnu napomenu. Privremeno
šifrirana tajna briše se pri prvom preuzimanju i ne može se ponovno prikazati.

Auth je vlasnik ključeva i identiteta. Ekran i rute postoje samo kada je
`heartphrame-module-api` uključen.

## Odgovori

Uspješni odgovor:

```json
{
  "data": {},
  "meta": {"request_id": "…"},
  "links": {"self": "/hfc/api/v1/users/1"}
}
```

Poveznice uključuju stvarnu baznu putanju aplikacije. Greška koristi RFC 9457
problem JSON s `type: "about:blank"`, stabilnim `code` i `request_id`. Interni
stack trace, SQL i tajne nikada se ne vraćaju klijentu.

`GET /api/v1` vraća i `scope_groups` te objekt `security`. Klijent tako otkriva
samo resurse modula koji su trenutačno uključeni i ne mora hardkodirati profil
instalacije.

## Auth scopeovi

- `users:read`, `users:create`, `users:update`, `users:delete`
- `groups:read`, `groups:manage`
- `audit:read`

`groups:manage` obuhvaća kreiranje, izmjenu i brisanje nesistemskih grupa te
promjenu članstava. Brisanje korisnika je namjerno deaktivacija i opoziv
ključeva; korisnički identitet ostaje dostupan auditu.

`audit:read` je dodatno ograničen samo na administratore i izlaže redigirani,
paginirani tok sigurnosnih i upravljačkih događaja. Vidi
[upute za Audit API](audit_hr.md).

## Workspace scopeovi i rute

Kada je Workspace instaliran i uključen, API registrira 17 dodatnih ruta.
`workspace:read` izlistava vidljiva područja, čita jedno područje i njegovo
ACL-filtrirano stablo za odabrani jezik. `workspace:manage` obuhvaća izmjenu
podataka, soft brisanje i vraćanje, ACL područja, link-čvorove, redoslijed
stabla i izravna ograničenja čvora.

Scope je nužan, ali nije dovoljan. Vlasnik API ključa mora imati ista efektivna
prava koja bi trebao u web sučelju. Član samo za čitanje zato dobiva `403` kada
koristi ključ koji sadrži `workspace:manage`. Kreiranje dokumenata i radnje s
privicima ostavljeni su HTML Editor API-ju.

Potpuni popis ruta i domensko ponašanje dokumentirani su u Workspace modulu
pod naslovom **API integracija**.

## Scopeovi i rute HTML Editora

Kada je HTML Editor instaliran i uključen, API uvjetno registrira 22 rute za
stranice, verzije, prijevode, nacrte i privitke. Scopeovi su `page:read`,
`page:write`, `page:publish`, `attachment:read` i `attachment:write`.

Objavljeno čitanje, nacrti, povijest, upload i prava namjerno se ponašaju
drugačije kada Workspace posjeduje dokument. Pogledaj potpuni
[HTTP API HTML Editora](editor_html_hr.md).

Slanje Workspace nacrta preko
`POST /api/v1/pages/{documentId}/review` poziva istu Workspace poslovnu metodu
kao web sučelje. Efektivni objavljivači dobivaju istu dedupliciranu in-app
obavijest.

## Calendar scopeovi i rute

Kada je Calendar uključen, API dodaje 12 ruta za kalendare, događaje, ACL i ICS
sa scopeovima `calendar:read` i `calendar:write`. Calendar ACL uvijek se ponovno
provjerava. Vidi [HTTP API Calendara](calendar_hr.md).

## Task scopeovi i rute

Kada je Task uključen, API dodaje četiri rute zadataka pod stranicom sa
scopeovima `task:read` i `task:write`. Definicije su u objavljenom Editor HTML-u,
a stanje i audit ostaju odvojeni. Vidi [HTTP API Task modula](task_hr.md).

## Notification scopeovi i rute

Kada je Notification uključen, API dodaje pet ruta sa scopeovima
`notifications:read` i `notifications:write`. One rade isključivo nad inboxom
vlasnika API ključa. Vidi
[HTTP API Notification modula](notification_hr.md).

## Webhook scopeovi i rute

API modul daje `webhooks:read` i `webhooks:manage`. Pretplata pripada API
ključu koji ju je kreirao, dok administrator može pregledati sve pretplate.
Isporuku obavlja trajni CLI worker, a ne sam poslovni HTTP zahtjev. Pogledaj
[upute za webhookove](webhooks_hr.md).

## Sigurnost i granice modula

Ograničenje brzine, idempotentni write zahtjevi, limiti JSON-a i replay
ponašanje opisani su u [sigurnosnim uputama](security_hr.md). Obavezne i
opcionalne veze modula navedene su u
[uputama o ovisnostima](dependencies_hr.md).

Menu, Theme i E-mail namjerno ne izlažu rute. E-mail ostaje opcionalni interni
transport. Primatelj upravlja e-mail kopijama u vlastitim postavkama računa, a
SMTP greška nikada ne poništava in-app obavijest ni poslovnu radnju koja ju je
stvorila.

## Dodavanje novog modula

Domenski modul dodaje `config/api.php` s resursima, dvojezičnim nazivima i
scopeovima. Datoteka ne smije importati klase API modula. API modul može
registrirati opcionalni kontroler koji poziva javni domenski servis, a nikada
privatne tablice domenskog modula.
