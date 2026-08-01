# Sigurnost i pouzdanost API-ja

## Autentikacija i autorizacija

Svaka ruta ispod `/api/v1` zahtijeva Bearer ključ osim discovery rute kada je
drugačije podešeno. Scope ograničava što ključ smije zatražiti, a domenski
servis dodatno ponovno provjerava stvarna prava vlasnika ključa.

## Ograničenje brzine

Zadani limit je 120 zahtjeva u minuti po API ključu. Odgovori sadrže
`X-RateLimit-Limit`, `X-RateLimit-Remaining` i `X-RateLimit-Reset`. Iscrpljen
limit vraća `429` sa zaglavljem `Retry-After`.

Brojač koristi prijenosnu ORM transakciju. Istodobni prvi zahtjevi u novom
vremenskom prozoru kratko ponavljaju konfliktni upis; ponovljeni kvar pohrane
zapisuje se u log i zatvara pristup umjesto tihog zaobilaženja ograničenja.

## Idempotentni write zahtjevi

Klijent treba poslati `Idempotency-Key` duljine 8-190 znakova uz
`POST`, `PUT`, `PATCH` i `DELETE`. Ključ se veže uz HTTP metodu, putanju, query
i otisak tijela. Dovršeni sigurni odgovor može se ponoviti sa zaglavljem
`Idempotency-Replayed: true`; uporaba istog ključa za drugi zahtjev vraća `409`.

Upload ne prihvaća ovo zaglavlje jer multipart i streamani payload imaju
zaseban protokol ponavljanja. Spremljena replay tijela ograničena su veličinom,
ističu i ne sadrže serverske greške.

## Ulazni podaci i greške

JSON write prihvaća `application/json` i ograničava veličinu prije dekodiranja.
Greške koriste RFC 9457 problem JSON i request ID. Ako sigurnosna shema nije
dostupna, zaštita write zahtjeva zatvara pristup odgovorom `503`.

## Webhook isporuka

Webhook cilj zadano mora koristiti HTTPS. Ugrađeni korisnički podaci,
nerazrješivi hostovi te privatne ili rezervirane mrežne adrese odbijaju se radi
zaštite od SSRF-a. Razvojni HTTP ili privatna mreža moraju se izričito
uključiti. Opcionalni popis hostova može dodatno suziti isporuku.

Tajna potpisa šifrirana je u bazi. Svaki pokušaj potpisuje
`<unix-vrijeme>.<točno-json-tijelo>` algoritmom HMAC-SHA256. Primatelj treba
odbiti prestari timestamp, potpis usporediti funkcijom konstantnog vremena i
deduplicirati događaj prema `X-HeartPhrame-Webhook-Id`.

Worker ponavlja transportne greške, `408`, `425`, `429` i `5xx`. Ostali `4xx`
odgovori terminalni su, a `410` dodatno isključuje pretplatu. Ponavljanje koristi
ograničenu eksponencijalnu odgodu, dok se zaključavanje prekinutog workera
oporavlja nakon 15 minuta.
