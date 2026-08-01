<?php

declare(strict_types=1);

/**
 * HR: Administratorsko upravljanje zahtjevima i životnim ciklusom API ključeva.
 * EN: Administrator management of requests and the API-key lifecycle.
 *
 * @var \HeartPhrame\View\View $this
 * @var string|null $title
 * @var bool|null $schema_ready
 * @var string|null $settings_menu_html
 * @var list<array<string,mixed>>|null $keys
 * @var list<array<string,mixed>>|null $pending_requests
 * @var list<array<string,mixed>>|null $scope_groups
 * @var array<string,string>|null $one_time_token
 * @var string|null $language
 * @var string|null $csrf_token_name
 * @var string|null $csrf_token
 */

$pageTitle = is_string($title ?? null) ? $title : __('API ključevi');
$schemaReady = (bool)($schema_ready ?? false);
$settingsMenuHtml = is_string($settings_menu_html ?? null) ? trim($settings_menu_html) : '';
$keys = is_array($keys ?? null) ? $keys : [];
$pendingRequests = is_array($pending_requests ?? null) ? $pending_requests : [];
$scopeGroups = is_array($scope_groups ?? null) ? $scope_groups : [];
$oneTimeToken = is_array($one_time_token ?? null) ? $one_time_token : null;
$language = ($language ?? 'hr') === 'en' ? 'en' : 'hr';
$csrfTokenName = is_string($csrf_token_name ?? null) ? $csrf_token_name : '_csrf_token';
$csrfToken = is_string($csrf_token ?? null) ? $csrf_token : '';

$pathFor = static function (\HeartPhrame\View\View $view, string $name, string $fallback): string {
    return $view->urlGenerator->namedRouteExists($name)
        ? $view->urlGenerator->getPathFor($name)
        : $fallback;
};
$createPath = $pathFor($this, 'auth.setup.api-keys.create', '/settings/auth/api-keys/create');
$rotatePath = $pathFor($this, 'auth.setup.api-keys.rotate', '/settings/auth/api-keys/rotate');
$revokePath = $pathFor($this, 'auth.setup.api-keys.revoke', '/settings/auth/api-keys/revoke');
$deletePath = $pathFor($this, 'auth.setup.api-keys.delete', '/settings/auth/api-keys/delete');
$userSearchPath = $pathFor($this, 'auth.setup.api-keys.users', '/settings/auth/api-keys/users');
$approvePath = $pathFor(
    $this,
    'auth.setup.api-keys.requests.approve',
    '/settings/auth/api-keys/requests/approve',
);
$rejectPath = $pathFor(
    $this,
    'auth.setup.api-keys.requests.reject',
    '/settings/auth/api-keys/requests/reject',
);

$userLabel = static function (mixed $user): string {
    if (!is_array($user)) {
        return __('Nepoznat korisnik');
    }

    $displayName = is_scalar($user['display_name'] ?? null) ? trim((string)$user['display_name']) : '';
    $login = is_scalar($user['login_identifier'] ?? null) ? trim((string)$user['login_identifier']) : '';

    return $displayName !== '' ? $displayName . ($login !== '' ? ' (' . $login . ')' : '') : $login;
};
$localized = static function (mixed $value, string $language, string $fallback = ''): string {
    if (!is_array($value)) {
        return $fallback;
    }

    $selected = is_scalar($value[$language] ?? null) ? trim((string)$value[$language]) : '';
    $other = is_scalar($value[$language === 'hr' ? 'en' : 'hr'] ?? null)
        ? trim((string)$value[$language === 'hr' ? 'en' : 'hr'])
        : '';

    return $selected !== '' ? $selected : ($other !== '' ? $other : $fallback);
};
$dateTimeFormat = $language === 'en' ? 'm/d/Y H:i' : 'd. m. Y. H:i';
$formatDateTime = static function (mixed $value, string $emptyLabel = '') use ($dateTimeFormat): string {
    if (!is_scalar($value) || trim((string)$value) === '') {
        return $emptyLabel;
    }

    try {
        return (new DateTimeImmutable((string)$value))->format($dateTimeFormat);
    } catch (Throwable) {
        return (string)$value;
    }
};
$activeKeys = [];
$inactiveKeys = [];
foreach ($keys as $key) {
    $revoked = is_scalar($key['revoked_at'] ?? null) && trim((string)$key['revoked_at']) !== '';
    $expired = is_scalar($key['expires_at'] ?? null)
        && trim((string)$key['expires_at']) !== ''
        && strtotime((string)$key['expires_at']) <= time();
    if ($revoked || $expired) {
        $inactiveKeys[] = $key;
    } else {
        $activeKeys[] = $key;
    }
}

$this->addToPlaceholder('head', <<<'HTML'
<style>
.api-key-secret {
    background: var(--hph-surface-muted-bg, var(--bs-tertiary-bg, #f8f9fa));
    border: 1px solid var(--hph-border, var(--bs-border-color, #dee2e6));
    color: var(--hph-surface-text, var(--bs-body-color, #212529));
    overflow-wrap: anywhere;
}
.api-scope-groups {
    border-top: 1px solid var(--hph-border, var(--bs-border-color, #dee2e6));
}
.api-scope-group {
    display: grid;
    gap: .75rem 1.25rem;
    grid-template-columns: minmax(9rem, 12rem) minmax(0, 1fr);
    padding: .9rem 0;
    border-bottom: 1px solid var(--hph-border, var(--bs-border-color, #dee2e6));
}
.api-scope-options {
    display: grid;
    gap: .75rem 1rem;
    grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
}
.api-scope-option {
    display: grid;
    gap: .5rem;
    grid-template-columns: auto minmax(0, 1fr);
    margin: 0;
}
.api-scope-option small {
    color: var(--hph-surface-muted-text, var(--bs-secondary-color, #6c757d));
    display: block;
    line-height: 1.35;
}
.api-key-table {
    min-width: 72rem;
}
.api-owner-picker {
    position: relative;
}
.api-owner-results {
    background: var(--hph-surface-bg, var(--bs-body-bg, #fff));
    border: 1px solid var(--hph-border, var(--bs-border-color, #dee2e6));
    color: var(--hph-surface-text, var(--bs-body-color, #212529));
    display: none;
    left: 0;
    max-height: 18rem;
    overflow-y: auto;
    position: absolute;
    right: 0;
    top: calc(100% + .25rem);
    z-index: 1080;
}
.api-owner-results.is-open {
    display: block;
}
.api-owner-option {
    background: transparent;
    border: 0;
    color: inherit;
    display: block;
    padding: .6rem .75rem;
    text-align: left;
    width: 100%;
}
.api-owner-option:hover,
.api-owner-option:focus,
.api-owner-option.is-active {
    background: var(--hph-primary-soft-bg, var(--bs-primary-bg-subtle, #cfe2ff));
    color: var(--hph-primary-soft-text, var(--bs-primary-text-emphasis, #052c65));
    outline: 0;
}
.api-settings-fold {
    border: 1px solid var(--hph-border, var(--bs-border-color, #dee2e6));
    border-radius: var(--bs-border-radius, .375rem);
    margin-top: 1rem;
}
.api-settings-fold > summary {
    cursor: pointer;
    font-weight: 600;
    padding: .9rem 1rem;
}
.api-settings-fold[open] > summary {
    border-bottom: 1px solid var(--hph-border, var(--bs-border-color, #dee2e6));
}
.api-request-item + .api-request-item {
    border-top: 1px solid var(--hph-border, var(--bs-border-color, #dee2e6));
}
@media (max-width: 767.98px) {
    .api-scope-group {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
HTML);

$tailConfiguration = [
    'searchUrl' => $userSearchPath,
    'searchPlaceholder' => __('Upiši ime ili korisničku oznaku'),
    'noResults' => __('Nema pronađenih korisnika.'),
    'searchError' => __('Pretraga korisnika nije uspjela.'),
    'copied' => __('Kopirano'),
];
$tailConfigurationJson = json_encode(
    $tailConfiguration,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);
$this->addToPlaceholder('tail', '<script type="application/json" id="api-key-ui-config">'
    . htmlspecialchars($tailConfigurationJson, ENT_NOQUOTES, 'UTF-8')
    . '</script>');
$this->addToPlaceholder('tail', <<<'HTML'
<script>
// HR: Pretraživi vlasnik dohvaća ograničen broj korisnika tek kada administrator traži.
// EN: The searchable owner fetches a bounded user list only when the administrator searches.
(function () {
    'use strict';

    var configNode = document.getElementById('api-key-ui-config');
    var search = document.querySelector('[data-api-owner-search]');
    var hidden = document.querySelector('[data-api-owner-id]');
    var results = document.querySelector('[data-api-owner-results]');
    var copyButton = document.querySelector('[data-api-key-copy]');
    var token = document.querySelector('[data-api-key-token]');
    var config = {};
    var timer = 0;
    var controller = null;
    var activeIndex = -1;

    try {
        config = configNode ? JSON.parse(configNode.textContent || '{}') : {};
    } catch (error) {
        config = {};
    }

    if (copyButton && token && navigator.clipboard) {
        copyButton.addEventListener('click', function () {
            navigator.clipboard.writeText(token.textContent || '').then(function () {
                copyButton.textContent = config.copied || copyButton.textContent;
            });
        });
    }

    if (!search || !hidden || !results) {
        return;
    }

    function closeResults() {
        results.classList.remove('is-open');
        search.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
    }

    function selectItem(item) {
        hidden.value = String(item.id || '');
        search.value = String(item.label || '');
        search.setCustomValidity('');
        closeResults();
    }

    function render(items, message) {
        results.replaceChildren();
        activeIndex = -1;
        if (!Array.isArray(items) || items.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'p-3 text-body-secondary';
            empty.textContent = message || config.noResults || '';
            results.appendChild(empty);
        } else {
            items.forEach(function (item) {
                var option = document.createElement('button');
                option.type = 'button';
                option.className = 'api-owner-option';
                option.setAttribute('role', 'option');
                option.dataset.ownerOption = '1';
                option.textContent = String(item.label || '');
                option.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    selectItem(item);
                });
                results.appendChild(option);
            });
        }
        results.classList.add('is-open');
        search.setAttribute('aria-expanded', 'true');
    }

    function loadUsers() {
        if (controller) {
            controller.abort();
        }
        controller = new AbortController();
        var separator = String(config.searchUrl || '').indexOf('?') === -1 ? '?' : '&';
        fetch(String(config.searchUrl || '') + separator + 'q=' + encodeURIComponent(search.value.trim()), {
            headers: {'Accept': 'application/json'},
            signal: controller.signal
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (payload) {
            render(payload && Array.isArray(payload.items) ? payload.items : []);
        }).catch(function (error) {
            if (error.name !== 'AbortError') {
                render([], config.searchError || '');
            }
        });
    }

    search.addEventListener('focus', loadUsers);
    search.addEventListener('input', function () {
        hidden.value = '';
        search.setCustomValidity('');
        window.clearTimeout(timer);
        timer = window.setTimeout(loadUsers, 250);
    });
    search.addEventListener('keydown', function (event) {
        var options = Array.from(results.querySelectorAll('[data-owner-option]'));
        if (event.key === 'Escape') {
            closeResults();
            return;
        }
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Enter') {
            return;
        }
        if (!results.classList.contains('is-open')) {
            loadUsers();
            return;
        }
        event.preventDefault();
        if (event.key === 'Enter' && activeIndex >= 0 && options[activeIndex]) {
            options[activeIndex].dispatchEvent(new MouseEvent('mousedown', {bubbles: true}));
            return;
        }
        if (options.length === 0) {
            return;
        }
        activeIndex += event.key === 'ArrowDown' ? 1 : -1;
        activeIndex = Math.max(0, Math.min(options.length - 1, activeIndex));
        options.forEach(function (option, index) {
            option.classList.toggle('is-active', index === activeIndex);
            option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
        });
        options[activeIndex].scrollIntoView({block: 'nearest'});
    });
    search.form.addEventListener('submit', function (event) {
        if (hidden.value !== '') {
            return;
        }
        event.preventDefault();
        search.setCustomValidity(search.getAttribute('data-owner-required') || '');
        search.reportValidity();
    });
    document.addEventListener('mousedown', function (event) {
        if (!results.contains(event.target) && event.target !== search) {
            closeResults();
        }
    });
}());
</script>
HTML);

/**
 * HR: Ispisuje jednu tablicu ključeva unutar sklopivog odjeljka.
 * EN: Renders one key table inside a collapsible section.
 *
 * @param list<array<string,mixed>> $sectionKeys
 */
$renderKeyTable = static function (
    array $sectionKeys,
    \HeartPhrame\View\View $view,
    callable $userLabel,
    callable $formatDateTime,
    string $csrfTokenName,
    string $csrfToken,
    string $rotatePath,
    string $revokePath,
    string $deletePath,
): void {
    ?>
    <?php if ($sectionKeys === []) : ?>
        <div class="p-3 text-body-secondary"><?= __('Nema API ključeva u ovom odjeljku.') ?></div>
    <?php else : ?>
        <div class="table-responsive p-3 pt-0">
            <table class="table table-hover align-middle api-key-table mb-0">
                <thead>
                    <tr>
                        <th><?= __('Naziv') ?></th>
                        <th><?= __('Vlasnik') ?></th>
                        <th><?= __('Scopeovi') ?></th>
                        <th><?= __('Zadnja uporaba') ?></th>
                        <th><?= __('Vrijedi do') ?></th>
                        <th><?= __('Status') ?></th>
                        <th class="text-end"><?= __('Radnje') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sectionKeys as $key) : ?>
                        <?php
                        $keyId = is_numeric($key['id'] ?? null) ? (int)$key['id'] : 0;
                        $revoked = is_scalar($key['revoked_at'] ?? null)
                            && trim((string)$key['revoked_at']) !== '';
                        $expired = is_scalar($key['expires_at'] ?? null)
                            && trim((string)$key['expires_at']) !== ''
                            && strtotime((string)$key['expires_at']) <= time();
                        $scopes = is_array($key['scopes'] ?? null) ? $key['scopes'] : [];
                        ?>
                        <tr>
                            <td>
                                <strong class="d-block"><?= $view->escape((string)($key['name'] ?? '')) ?></strong>
                                <code><?= $view->escape((string)($key['public_id'] ?? '')) ?></code>
                            </td>
                            <td><?= $view->escape($userLabel($key['user'] ?? null)) ?></td>
                            <td><small><?= $view->escape(implode(', ', $scopes)) ?></small></td>
                            <td><?= $view->escape($formatDateTime($key['last_used_at'] ?? null, __('Nikad'))) ?></td>
                            <td><?= $view->escape($formatDateTime($key['expires_at'] ?? null, __('Bez isteka'))) ?></td>
                            <td>
                                <?php if ($revoked) : ?>
                                    <span class="badge text-bg-danger"><?= __('Opozvan') ?></span>
                                <?php elseif ($expired) : ?>
                                    <span class="badge text-bg-warning"><?= __('Istekao') ?></span>
                                <?php else : ?>
                                    <span class="badge text-bg-success"><?= __('Aktivan') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                    <?php if (!$revoked && !$expired) : ?>
                                        <form method="post" action="<?= $view->escape($rotatePath) ?>">
                                            <input type="hidden" name="<?= $view->escape($csrfTokenName) ?>" value="<?= $view->escape($csrfToken) ?>">
                                            <input type="hidden" name="key_id" value="<?= $keyId ?>">
                                            <button class="btn btn-sm btn-secondary" type="submit"><?= __('Rotiraj') ?></button>
                                        </form>
                                        <form method="post" action="<?= $view->escape($revokePath) ?>">
                                            <input type="hidden" name="<?= $view->escape($csrfTokenName) ?>" value="<?= $view->escape($csrfToken) ?>">
                                            <input type="hidden" name="key_id" value="<?= $keyId ?>">
                                            <button class="btn btn-sm btn-warning" type="submit"><?= __('Opozovi') ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= $view->escape($deletePath) ?>" onsubmit="return confirm('<?= $view->escape(__('Trajno obrisati ovaj API ključ?')) ?>');">
                                        <input type="hidden" name="<?= $view->escape($csrfTokenName) ?>" value="<?= $view->escape($csrfToken) ?>">
                                        <input type="hidden" name="key_id" value="<?= $keyId ?>">
                                        <button class="btn btn-sm btn-danger" type="submit"><?= __('Obriši') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php
};
?>

<?= $this->forModulePartial('aaieduhr/heartphrame-module-auth', 'auth/toasts') ?>

<div class="row g-4">
    <?php if ($settingsMenuHtml !== '') : ?>
        <aside class="col-lg-3"><?= $settingsMenuHtml ?></aside>
    <?php endif; ?>

    <main class="<?= $settingsMenuHtml !== '' ? 'col-lg-9' : 'col-12' ?>">
        <?php if (is_string($oneTimeToken['token'] ?? null)) : ?>
            <section class="card shadow-sm border-warning mb-4">
                <div class="card-body">
                    <h1 class="h5"><?= __('Spremi API ključ sada') ?></h1>
                    <p><?= __('Tajna se prikazuje samo jednom. Nakon osvježavanja više je nije moguće dohvatiti.') ?></p>
                    <code class="api-key-secret d-block rounded p-3 mb-3" data-api-key-token><?= $this->escape((string)$oneTimeToken['token']) ?></code>
                    <button class="btn btn-warning" type="button" data-api-key-copy><?= __('Kopiraj ključ') ?></button>
                </div>
            </section>
        <?php endif; ?>

        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h1 class="h3 mb-1"><?= $this->escape($pageTitle) ?></h1>
                <p class="text-body-secondary mb-4">
                    <?= __('Ključ koristi identitet vlasnika, a scopeovi dodatno ograničavaju dopuštene operacije.') ?>
                </p>

                <?php if (!$schemaReady) : ?>
                    <div class="alert alert-warning mb-0" role="alert">
                        <?= __('Tablice API ključeva nisu kreirane. Pokrenite početnu migraciju API modula.') ?>
                    </div>
                <?php else : ?>
                    <form method="post" action="<?= $this->escape($createPath) ?>" class="row g-3">
                        <input type="hidden" name="<?= $this->escape($csrfTokenName) ?>" value="<?= $this->escape($csrfToken) ?>">

                        <div class="col-md-6">
                            <label class="form-label" for="api-key-name"><?= __('Naziv') ?></label>
                            <input class="form-control" id="api-key-name" name="name" maxlength="190" required>
                        </div>
                        <div class="col-md-6 api-owner-picker">
                            <label class="form-label" for="api-key-owner-search"><?= __('Vlasnik ključa') ?></label>
                            <input
                                class="form-control"
                                id="api-key-owner-search"
                                type="search"
                                autocomplete="off"
                                role="combobox"
                                aria-autocomplete="list"
                                aria-controls="api-key-owner-results"
                                aria-expanded="false"
                                placeholder="<?= $this->escape(__('Upiši ime ili korisničku oznaku')) ?>"
                                data-api-owner-search
                                data-owner-required="<?= $this->escape(__('Odaberite korisnika iz rezultata pretrage.')) ?>"
                                required
                            >
                            <input type="hidden" name="user_id" data-api-owner-id>
                            <div
                                class="api-owner-results rounded shadow"
                                id="api-key-owner-results"
                                role="listbox"
                                data-api-owner-results
                            ></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="api-key-description"><?= __('Opis') ?></label>
                            <textarea class="form-control" id="api-key-description" name="description" rows="3"></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="api-key-expiry"><?= __('Istječe') ?></label>
                            <input class="form-control" id="api-key-expiry" name="expires_at" type="datetime-local">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="api-key-ips"><?= __('Dopuštene IP adrese') ?></label>
                            <textarea class="form-control" id="api-key-ips" name="allowed_ips" rows="3" placeholder="192.0.2.10&#10;10.0.0.0/8"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-label"><?= __('Scopeovi') ?></div>
                            <div class="api-scope-groups">
                                <?php foreach ($scopeGroups as $group) : ?>
                                    <?php
                                    $resource = is_scalar($group['resource'] ?? null) ? (string)$group['resource'] : '';
                                    $groupScopes = is_array($group['scopes'] ?? null) ? $group['scopes'] : [];
                                    ?>
                                    <div class="api-scope-group">
                                        <div>
                                            <strong class="d-block"><?= $this->escape($localized($group['label'] ?? null, $language, $resource)) ?></strong>
                                            <code><?= $this->escape($resource) ?></code>
                                        </div>
                                        <div class="api-scope-options">
                                            <?php foreach ($groupScopes as $scope) : ?>
                                                <?php $scopeName = is_array($scope) ? (string)($scope['name'] ?? '') : ''; ?>
                                                <label class="form-check api-scope-option">
                                                    <input class="form-check-input" type="checkbox" name="scopes[]" value="<?= $this->escape($scopeName) ?>">
                                                    <span class="form-check-label">
                                                        <strong class="d-block"><?= $this->escape($localized($scope['label'] ?? null, $language, $scopeName)) ?></strong>
                                                        <code><?= $this->escape($scopeName) ?></code>
                                                        <small><?= $this->escape($localized($scope['description'] ?? null, $language)) ?></small>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" type="submit"><?= __('Kreiraj API ključ') ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($schemaReady) : ?>
            <section class="card shadow-sm mb-4" id="api-key-requests">
                <div class="card-body">
                    <h2 class="h4 mb-1"><?= __('Zahtjevi koji čekaju odluku') ?></h2>
                    <p class="text-body-secondary"><?= __('Odobrenjem se izdaje ključ, a vlasnik dobiva obavijest sa sigurnom jednokratnom poveznicom.') ?></p>
                    <?php if ($pendingRequests === []) : ?>
                        <div class="alert alert-secondary mb-0" role="status"><?= __('Nema zahtjeva koji čekaju odluku.') ?></div>
                    <?php else : ?>
                        <?php foreach ($pendingRequests as $pendingRequest) : ?>
                            <?php
                            $requestId = is_numeric($pendingRequest['id'] ?? null)
                                ? (int)$pendingRequest['id']
                                : 0;
                            $requestScopes = is_array($pendingRequest['scopes'] ?? null)
                                ? $pendingRequest['scopes']
                                : [];
                            $requestIps = is_array($pendingRequest['allowed_ips'] ?? null)
                                ? $pendingRequest['allowed_ips']
                                : [];
                            ?>
                            <article class="api-request-item py-3">
                                <div class="row g-3 align-items-start">
                                    <div class="col-lg-7">
                                        <h3 class="h6 mb-1"><?= $this->escape((string)($pendingRequest['name'] ?? '')) ?></h3>
                                        <div class="text-body-secondary small mb-2">
                                            <?= $this->escape($userLabel($pendingRequest['user'] ?? null)) ?>
                                            · <?= $this->escape($formatDateTime($pendingRequest['created_at'] ?? null, '—')) ?>
                                        </div>
                                        <?php if (trim((string)($pendingRequest['description'] ?? '')) !== '') : ?>
                                            <p class="mb-2"><?= nl2br($this->escape((string)$pendingRequest['description'])) ?></p>
                                        <?php endif; ?>
                                        <div class="small"><strong><?= __('Scopeovi') ?>:</strong> <?= $this->escape(implode(', ', $requestScopes)) ?></div>
                                        <div class="small"><strong><?= __('Vrijedi do') ?>:</strong> <?= $this->escape($formatDateTime($pendingRequest['expires_at'] ?? null, __('Bez isteka'))) ?></div>
                                        <div class="small"><strong><?= __('Dopuštene IP adrese') ?>:</strong> <?= $this->escape($requestIps !== [] ? implode(', ', $requestIps) : __('Sve IP adrese')) ?></div>
                                    </div>
                                    <div class="col-lg-5">
                                        <form method="post" action="<?= $this->escape($approvePath) ?>" class="mb-2">
                                            <input type="hidden" name="<?= $this->escape($csrfTokenName) ?>" value="<?= $this->escape($csrfToken) ?>">
                                            <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                            <button class="btn btn-success w-100" type="submit"><?= __('Odobri zahtjev') ?></button>
                                        </form>
                                        <form method="post" action="<?= $this->escape($rejectPath) ?>">
                                            <input type="hidden" name="<?= $this->escape($csrfTokenName) ?>" value="<?= $this->escape($csrfToken) ?>">
                                            <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                            <label class="form-label small" for="api-rejection-note-<?= $requestId ?>"><?= __('Napomena korisniku (opcionalno)') ?></label>
                                            <textarea class="form-control form-control-sm mb-2" id="api-rejection-note-<?= $requestId ?>" name="decision_note" rows="2"></textarea>
                                            <button class="btn btn-danger w-100" type="submit"><?= __('Odbij zahtjev') ?></button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h4 mb-1"><?= __('Postojeći API ključevi') ?></h2>
                    <p class="text-body-secondary mb-0">
                        <?= __('Aktivni ključevi mogu se rotirati ili opozvati. Svi ključevi mogu se trajno obrisati.') ?>
                    </p>
                    <details class="api-settings-fold">
                        <summary><?= __('Važeći i aktivni ključevi') ?> (<?= count($activeKeys) ?>)</summary>
                        <?php $renderKeyTable(
                            $activeKeys,
                            $this,
                            $userLabel,
                            $formatDateTime,
                            $csrfTokenName,
                            $csrfToken,
                            $rotatePath,
                            $revokePath,
                            $deletePath,
                        ); ?>
                    </details>
                    <details class="api-settings-fold">
                        <summary><?= __('Opozvani i istekli ključevi') ?> (<?= count($inactiveKeys) ?>)</summary>
                        <?php $renderKeyTable(
                            $inactiveKeys,
                            $this,
                            $userLabel,
                            $formatDateTime,
                            $csrfTokenName,
                            $csrfToken,
                            $rotatePath,
                            $revokePath,
                            $deletePath,
                        ); ?>
                    </details>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
