# HeartPhrame API modul

[English version](README.md)

`heartphrame-module-api` pruža zajedničku, verzioniranu HTTP granicu za
HeartPhrame aplikacije. Modul je namjerno odvojen od domenskih modula: posjeduje
Bearer autentikaciju, JSON envelope, problem odgovore, request ID, otkrivanje
scopeova i administraciju API ključeva, dok Auth i budući domenski moduli
zadržavaju svoja poslovna pravila.

## Ovisnosti

Obavezno, redoslijedom uključivanja:

1. `aaieduhr/heartphrame-framework` (`dev-main`)
2. `aaieduhr/heartphrame-module-orm` (`dev-main`)
3. `aaieduhr/heartphrame-module-auth` (`dev-main`)
4. `aaieduhr/heartphrame-module-api` (`dev-main`)

Opcionalne domenske integracije:

- Calendar: CRUD kalendara/događaja i ICS.
- HTML Editor: stranice, verzije, prijevodi i privitci.
- Notification: inbox i stanje pročitanosti vlasnika API ključa.
- Task: stanje objavljenih zadataka i audit.
- Workspace: ACL resursi i upravljanje stablom.
- Workspace Search: ACL pretraga naslova, sadržaja, autora, localea i datuma.

Theme i Menu samo su opcionalne prezentacijske integracije. Detaljni primjeri
nalaze se u `docs/dependencies_hr.md` i domenskim vodičima u `docs/`.

```bash
composer require aaieduhr/heartphrame-module-api:dev-main
vendor/bin/hph api:install-migration
vendor/bin/hph orm-migrate:up
```

Theme i Menu nisu obavezni. Ekran API ključeva koristi Bootstrap-kompatibilan
HTML i framework zadane stilove kada Theme nije prisutan. Ako je Menu
instaliran, modul dodaje stavku **API ključevi** unutar Auth postavki.

## Tijek API ključa

Administrator može izravno izdati ključ. Odabir vlasnika koristi ograničenu
serversku pretragu umjesto učitavanja svih računa u stranicu. Postojeći
ključevi podijeljeni su na aktivne i opozvane/istekle; popis prikazuje
lokalizirano vrijeme zadnje uporabe i isteka te omogućuje rotaciju, opoziv i
trajno brisanje.

Prijavljeni korisnik može zatražiti ključ na svojem profilu. Administrator vidi
zahtjeve koji čekaju odluku na istom ekranu te ih može odobriti ili odbiti.
Odluka stvara obavijest kada je Notification uključen. Odobrena tajna ostaje
šifrirana samo dok vlasnik ne otvori sigurnu stranicu za jednokratno
preuzimanje; trajne obavijesti nikada ne sadrže čistu tajnu.

## Osnovne Auth rute

```text
GET    /api/v1
GET    /api/v1/users
POST   /api/v1/users
GET    /api/v1/users/{userId}
PATCH  /api/v1/users/{userId}
DELETE /api/v1/users/{userId}
PUT    /api/v1/users/{userId}/groups
GET    /api/v1/groups
POST   /api/v1/groups
GET    /api/v1/groups/{groupId}
PATCH  /api/v1/groups/{groupId}
DELETE /api/v1/groups/{groupId}
GET    /api/v1/audit
```

Svaka ruta zahtijeva `Authorization: Bearer <token>`. Administracija lokalnih
korisnika i grupa dodatno zahtijeva da vlasnik ključa bude aktivni
administrator. Scope može ograničiti administratorski ključ, ali nikada ne može
dodijeliti pravo koje vlasnik nema u aplikaciji.

Primjeri ruta relativni su prema baznoj putanji aplikacije. Ako je HeartPhrame
instaliran ispod `/hfc`, poveznice odgovora i `Location` zaglavlja automatski
koriste `/hfc/api/v1/...`.

## Modularni scopeovi

Svaki domenski modul može izložiti neutralni `config/api.php` bez ovisnosti o
ovom modulu. API čita opise samo iz modula navedenih u
`app.modules.enabled`, pa dinamički gradi katalog i GUI ključeva. Uklanjanje ili
isključivanje domenskog modula uklanja njegove scopeove iz novih ključeva.

Opcionalna Workspace integracija dodaje `/api/v1/workspaces` rute i scopeove
`workspace:read` i `workspace:manage`. HTTP adapter ostaje u ovom modulu, a
Workspace posjeduje ACL i poslovna pravila. Isključivanje Workspacea uklanja te
rute bez narušavanja API modula.

Opcionalna HTML Editor integracija dodaje 22 `/api/v1/pages` rute te scopeove
`page:*` i `attachment:*`. Editor posjeduje sanitizaciju, verzije, privitke i
ponašanje sa ili bez Workspacea; ovaj modul te radnje samo prilagođava HTTP-u.

Opcionalna Calendar integracija dodaje `calendar:read` i `calendar:write` rute
za CRUD kalendara/događaja, ACL i ICS. Opcionalna Task integracija dodaje
`task:read` i `task:write` rute za stanje objavljenih zadataka i audit.
Strukturirani Editor sadržaj može kombinirati bilo koji broj Calendar i Task
ugradnji.

Workspace Search dodaje `workspace-search:read` i
`GET /api/v1/workspace-search`. Naslijeđeni Workspace/page ACL vlasnika API
ključa primjenjuje se prije brojanja, stvaranja isječaka i rezultata.

Opcionalna Notification integracija izlaže samo inbox vlasnika API ključa.
Ne dopušta stvaranje proizvoljnih poruka. Workspace nacrt poslan na pregled
preko API-ja prolazi isti domenski workflow kao web sučelje i zato obavještava
efektivne objavljivače. E-mail kopije ostaju interni best-effort transport i
šalju se samo kada ih je primatelj osobno uključio.

Menu, Theme i E-mail namjerno nemaju javni HTTP API. Menu i Theme podešavaju web
aplikaciju, a E-mail je transport koji koriste servisi.

Zahtjevi koji mijenjaju stanje ograničeni su po brzini i podržavaju zaglavlje
`Idempotency-Key`. Discovery na `GET /api/v1` vraća aktivne grupe scopeova i
sigurnosne mogućnosti. Operativni detalji nalaze se u uputama za sigurnost i
ovisnosti.

## Webhookovi

API modul posjeduje trajne webhook pretplate i asinkronu isporuku.
`webhooks:read` daje popis pretplata i povijest isporuka, a
`webhooks:manage` omogućuje kreiranje, izmjenu, rotaciju, ponavljanje i
brisanje. Tajne potpisa šifrirane su u bazi i prikazuju se samo u odgovoru
kreiranja ili rotacije.

Uspješne API mutacije stavljaju u red sanitizirane događaje poput
`pages.published`, `calendar_events.updated` ili
`workspaces.acl_changed`. Payload nikada ne kopira tijelo zahtjeva ili
odgovora. CLI worker potpisuje točan JSON algoritmom HMAC-SHA256, privremene
greške ponavlja uz ograničenu eksponencijalnu odgodu, a terminalne greške
ostavlja dostupnima za pregled.

```text
vendor/bin/hph api webhooks:worker --batch-size=20
vendor/bin/hph api webhooks:worker --watch --sleep=5
vendor/bin/hph api webhooks:status
```

Rute, provjera potpisa, SSRF zaštita i postavljanje process managera opisani su
u [uputama za webhookove](docs/webhooks_hr.md).

## Politika ovisnosti

Framework i interni HeartPhrame moduli zahtijevaju se s pomične grane
`dev-main`. Ovaj modul ne sprema `composer.lock`; GitHub CI na PHP-u 8.2-8.5
dohvaća najnovija razvojna stanja i pokreće cijeli skup provjera
`composer on-commit`. U `composer.json` ne smije se dodavati fiksno polje
`version` niti zamjena s fiksnom verzijom.

Detalji su u [hrvatskoj dokumentaciji](docs/index_hr.md) i
[engleskoj dokumentaciji](docs/index_en.md).
Ponašanje backup providera opisano je u [integraciji backupa](docs/backup_hr.md).
