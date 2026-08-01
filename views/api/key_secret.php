<?php

declare(strict_types=1);

/**
 * HR: Jednokratni prikaz odobrenog API tokena.
 * EN: One-time display of an approved API token.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var string $token
 * @var array<string,mixed> $request
 * @var string $profile_path
 */

$token = is_string($token ?? null) ? $token : '';
$request = is_array($request ?? null) ? $request : [];
$profilePath = is_string($profile_path ?? null) ? $profile_path : '';

$this->addToPlaceholder('head', <<<'HTML'
<style>
.api-key-secret {
    background: var(--hph-surface-muted-bg, var(--bs-tertiary-bg, #f8f9fa));
    border: 1px solid var(--hph-border, var(--bs-border-color, #dee2e6));
    color: var(--hph-surface-text, var(--bs-body-color, #212529));
    overflow-wrap: anywhere;
}
</style>
HTML);
$this->addToPlaceholder('tail', <<<'HTML'
<script>
// HR: Korisnik kopira jednokratni token samo izravnom radnjom.
// EN: The user copies the one-time token only through an explicit action.
(function () {
    var button = document.querySelector('[data-api-key-copy]');
    var token = document.querySelector('[data-api-key-token]');
    if (!button || !token || !navigator.clipboard) {
        return;
    }
    button.addEventListener('click', function () {
        navigator.clipboard.writeText(token.textContent || '').then(function () {
            button.textContent = button.getAttribute('data-copied') || button.textContent;
        });
    });
}());
</script>
HTML);
?>
<section class="card shadow-sm">
    <div class="card-body p-4">
        <h1 class="h3"><?= $this->escape($title ?? __('Odobreni API ključ')) ?></h1>
        <p class="text-body-secondary">
            <?= __('Ovo je jedini prikaz punog API keya i secreta. Spremite ga prije napuštanja stranice.') ?>
        </p>
        <dl class="row">
            <dt class="col-sm-3"><?= __('Naziv') ?></dt>
            <dd class="col-sm-9"><?= $this->escape((string)($request['name'] ?? '')) ?></dd>
        </dl>
        <code class="api-key-secret d-block rounded p-3 mb-3" data-api-key-token><?= $this->escape($token) ?></code>
        <div class="d-flex flex-wrap gap-2">
            <button
                class="btn btn-warning"
                type="button"
                data-api-key-copy
                data-copied="<?= $this->escape(__('Kopirano')) ?>"
            ><?= __('Kopiraj ključ') ?></button>
            <a class="btn btn-secondary" href="<?= $this->escape($profilePath) ?>"><?= __('Povratak na profil') ?></a>
        </div>
    </div>
</section>
