# HTTP API audita

```text
GET /api/v1/audit
```

Ruta zahtijeva `audit:read`, a vlasnik API ključa mora biti aktivni
administrator. Sam scope nikada ne podiže prava običnog korisnika.

Kada je opcionalni Audit modul uključen, query parametri mogu filtrirati
`event_key`, `module`, `action`, `outcome`, `channel`, `actor_user_id`,
`workspace_id`, `page_id`, `target`, `created_from` i `created_to`. Paginacija
koristi ograničene vrijednosti `page` i `per_page`. Centralni servis redigira
lozinke, API tajne, tokene, kolačiće, tijela dokumenata i usporedive osjetljive
podatke prije spremanja, a time i prije HTTP odgovora.

Bez Audit modula API zadržava kompatibilnost s manjim Auth auditom i njegovim
filtrima događaja, aktera, ciljnog korisnika i vremena.

Audit je preko HTTP-a samo za čitanje. Svaka API radnja koja mijenja stanje i
dalje stvara svoj domenski ili HTTP audit događaj kroz isti servis koji koristi
web sučelje. Tehnički PSR-3 logovi namjerno nisu dostupni kroz ovu rutu.
