# Webhook API i worker

## Namjena

Webhook obavještava drugu HTTPS aplikaciju nakon uspješne HeartPhrame API
mutacije. Isporuka je asinkrona: izvorni poslovni zahtjev sprema outbox zapis i
normalno završava, a CLI worker naknadno obavlja mrežni poziv. Webhook greška
zato nikada ne može poništiti objavu stranice, promjenu kalendara ili drugu
dovršenu operaciju.

## Scopeovi i vlasništvo

- `webhooks:read`: popis vidljivih pretplata i njihove povijesti isporuke.
- `webhooks:manage`: kreiranje, izmjena, brisanje, rotacija i ponavljanje.

Korisnik koji nije administrator vidi samo pretplate kreirane aktualnim API
ključem. Administratorski ključ s odgovarajućim scopeom može upravljati svim
pretplatama.

## Rute

```text
GET    /api/v1/webhooks
POST   /api/v1/webhooks
GET    /api/v1/webhooks/{uuid}
PATCH  /api/v1/webhooks/{uuid}
DELETE /api/v1/webhooks/{uuid}
POST   /api/v1/webhooks/{uuid}/rotate-secret
GET    /api/v1/webhooks/{uuid}/deliveries
GET    /api/v1/webhooks/{uuid}/deliveries/{deliveryUuid}
POST   /api/v1/webhooks/{uuid}/deliveries/{deliveryUuid}/retry
```

Primjer kreiranja:

```json
{
  "name": "Integracija objave",
  "target_url": "https://consumer.example/hooks/heartphrame",
  "events": ["pages.*", "calendar_events.created"],
  "active": true
}
```

Odgovor samo jednom sadrži `secret`. Javni prikaz pretplate nikada ne sadrži
čistu ni šifriranu tajnu. Izmjena, brisanje, rotacija i ručno ponavljanje koriste
standardni `ETag`/`If-Match` ugovor.

Selektor može biti točan (`pages.published`), wildcard prostora naziva
(`pages.*`) ili svi događaji (`*`). Događaji obuhvaćaju korisnike, grupe,
područja, čvorove/stablo, stranice/workflow, privitke, kalendare/događaje,
zadatke i obavijesti.

## Payload i potpis

Payload je JSON envelope s ID-em događaja, nazivom događaja, vremenom nastanka i
sanitiziranim metapodacima mutacije. Tijela zahtjeva i odgovora namjerno nisu
uključena.

Svaki POST sadrži:

```text
Content-Type: application/json
X-HeartPhrame-Webhook-Id: <UUID događaja>
X-HeartPhrame-Webhook-Event: <naziv događaja>
X-HeartPhrame-Webhook-Timestamp: <Unix sekunde>
X-HeartPhrame-Webhook-Signature: v1=<hex HMAC-SHA256>
```

Pseudokod provjere:

```php
$signed = $timestamp . '.' . $rawRequestBody;
$expected = hash_hmac('sha256', $signed, $secret);
$valid = hash_equals('v1=' . $expected, $receivedSignature);
```

Provjeri sirovo tijelo prije JSON dekodiranja. Odbij prestari timestamp prema
sigurnosnoj politici primatelja i svaki webhook ID obradi samo jednom.

## Pokretanje workera

Jedan batch:

```text
vendor/bin/hph api webhooks:worker --batch-size=20
```

Trajni foreground worker:

```text
vendor/bin/hph api webhooks:worker --watch --sleep=5
```

Stanje reda:

```text
vendor/bin/hph api webhooks:status
```

U produkciji watch naredbu treba pokrenuti kroz systemd, supervisord, launchd
ili drugi process manager. Više workera je sigurno jer se retci preuzimaju
transakcijski. Privremene greške koriste ograničenu eksponencijalnu odgodu, a
terminalne greške ostaju dostupne preko delivery ruta za dijagnostiku i ručno
ponavljanje.

## Konfiguracija

`api.webhooks` podržava:

- `enabled`
- `max_attempts`
- `base_retry_seconds`
- `max_retry_seconds`
- `timeout_seconds`
- `allow_insecure_http`
- `allow_private_networks`
- `allowed_hosts`

U produkciji ostavi HTTP i privatne mreže isključenima. Popis dopuštenih hostova
preporučuje se kada su svi primatelji unaprijed poznati.
