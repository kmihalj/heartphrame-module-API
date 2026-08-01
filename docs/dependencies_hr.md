# Ovisnosti API modula

API modul zahtijeva Framework, Auth i ORM. Auth posjeduje API ključeve i
identitet njihova vlasnika, a ORM prijenosno sprema stanje ograničenja brzine,
idempotencije, webhook pretplata i outboxa. Webhook transport koristi PHP
streamove i ne zahtijeva vanjski mailer ni HTTP klijent.

Domenske integracije su opcionalne i otkrivaju se samo iz uključenih modula:

- Workspace daje `workspace:*` i zadržava ACL/stablo pravila.
- HTML Editor daje `page:*` i `attachment:*`; uz uključen Workspace radnje nad
  stranicama slijede Workspace objavu i naslijeđeni ACL.
- Calendar daje `calendar:*` i ponovno provjerava Calendar ACL.
- Task daje `task:*` i ponovno provjerava vidljivost stranice i pravilo liste.
- Notification daje `notifications:*` za inbox vlasnika ključa.

Smjer ovisnosti ostaje domenski neutralan: ti moduli izlažu javne servise i
`config/api.php`, ali ne zahtijevaju API modul. API posjeduje HTTP adaptere i
može se ukloniti bez prekida web workflowa.

Menu i Theme su samo opcionalne web integracije. E-mail je opcionalni interni
transport. Nijedan od ta tri modula ne daje API resurse.
