# HTTP API audita

```text
GET /api/v1/audit
```

Ruta zahtijeva `audit:read`, a vlasnik API ključa mora biti aktivni
administrator. Sam scope nikada ne podiže prava običnog korisnika.

Opcionalni query parametri filtriraju događaj, aktera, ciljnog korisnika i
vremensko razdoblje. Paginacija koristi ograničene vrijednosti `page` i
`per_page`. Auth redigira kontekst prije HTTP adaptera; lozinke, API tajne,
tokeni, kolačići i usporedivi osjetljivi podaci ne izlaze kroz odgovor.

Audit je preko HTTP-a samo za čitanje. Svaka API radnja koja mijenja stanje i
dalje stvara svoj domenski/Auth audit događaj kroz isti servis koji koristi web
sučelje.
