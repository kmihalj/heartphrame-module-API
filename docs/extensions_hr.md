# Domenska API proširenja

Engleska verzija: [extensions_en.md](extensions_en.md)

## Pravilo vlasništva

API posjeduje autentikaciju, zaštite zahtjeva, omotnice odgovora, otkrivanje
scopeova, ograničenje brzine, idempotenciju, webhookove i sigurni registar ruta.
Domenski modul posjeduje javni domenski servis, opcionalni HTTP kontroler,
deklaraciju ruta, opis scopeova, primjere i testove. API nikada ne uvozi
kontroler drugog modula niti čita njegove tablice.

## Minimalna izvedba

API treba staviti u `require-dev` i `suggest` domenskog paketa, a ne u
`require`. Modul tako ostaje instalabilan bez HTTP API-ja.

```php
final readonly class ExampleApiExtension implements ApiExtensionInterface
{
    public function id(): string { return 'example'; }

    public function register(ApiRouteRegistry $routes): void
    {
        $routes->add(
            'GET',
            '/api/v1/examples/{id}',
            ExampleResourceController::class,
            'show',
            'api.v1.examples.show',
        );
    }
}
```

String proširenja dodaje se u `config/api.php`. Izraz `::class` siguran je kada
API nije instaliran jer ne učitava klasu.

```php
return [
    'module' => 'example',
    'extension' => ExampleApiExtension::class,
    'resources' => [/* dvojezični scopeovi */],
];
```

Proširenje i kontroler registriraju se samo kada postoji
`ApiExtensionInterface`. Kontroler prije poziva transportno neutralnog
domenskog servisa mora provjeriti scope i stvarna prava korisnika.
`ApiRouteRegistry` automatski dodaje Bearer autentikaciju te odbija putanje
izvan `/api/v1`, duplikate naziva, duplikate metode/putanje i nepostojeće
kontrolere.

## Kontrolni popis testova

- modul radi bez instaliranog API-ja;
- proširenje registrira točan broj i nazive ruta;
- svaka ruta dobiva `ApiAuthenticationMiddleware`;
- scope nikada ne zaobilazi uobičajeni domenski ACL;
- greške imaju stabilne problem kodove i ne otkrivaju interne podatke;
- cURL, PHP i primjeri odgovora nalaze se u hrvatskoj i engleskoj dokumentaciji
  vlasničkog modula.
