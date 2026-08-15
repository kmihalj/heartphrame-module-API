# Domain API extensions

Croatian version: [extensions_hr.md](extensions_hr.md)

## Ownership rule

API owns authentication, request guards, response envelopes, scope discovery,
rate limits, idempotency, webhooks, and the safe route registry. A domain module
owns its public domain service, optional HTTP controller, route declaration,
scope descriptor, examples, and tests. API never imports another module's
controller or reads its tables.

## Minimal implementation

Keep API in the domain package's `require-dev` and `suggest`, not `require`.
The module therefore remains installable without an HTTP API.

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

Add the extension string to `config/api.php`. A `::class` expression is safe
when API is absent because it does not load the class.

```php
return [
    'module' => 'example',
    'extension' => ExampleApiExtension::class,
    'resources' => [/* bilingual scopes */],
];
```

Register the extension and controller only when
`ApiExtensionInterface` exists. The controller must validate the requested
scope and actor permissions before calling the transport-neutral domain
service. `ApiRouteRegistry` automatically adds Bearer authentication and
rejects non-`/api/v1` paths, duplicate names, duplicate method/path pairs, and
missing controllers.

## Test checklist

- the module works with API absent;
- the extension registers the exact route count and names;
- every route receives `ApiAuthenticationMiddleware`;
- scope possession never bypasses normal domain ACL;
- errors use stable problem codes and disclose no internals;
- cURL, PHP, and response examples live in the owning module's English and
  Croatian documentation.
