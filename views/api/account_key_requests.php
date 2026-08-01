<?php

declare(strict_types=1);

/**
 * HR: Korisnički zahtjevi za API ključ unutar proširivog Auth profila.
 * EN: User API-key requests inside the extensible Auth profile.
 *
 * @var \HeartPhrame\View\View $this
 * @var list<array<string,mixed>> $requests
 * @var bool $hasPendingRequest
 * @var list<array<string,mixed>> $scopeGroups
 * @var string $requestPath
 * @var string $revealPathTemplate
 */

$requests = is_array($requests ?? null) ? $requests : [];
$hasPendingRequest = (bool)($hasPendingRequest ?? false);
$scopeGroups = is_array($scopeGroups ?? null) ? $scopeGroups : [];
$requestPath = is_string($requestPath ?? null) ? $requestPath : '';
$revealPathTemplate = is_string($revealPathTemplate ?? null) ? $revealPathTemplate : '';
$dateTimeFormat = __('API ključevi') === 'API keys' ? 'm/d/Y H:i' : 'd. m. Y. H:i';
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
$statusLabel = static fn(string $status): string => match ($status) {
    'approved' => __('Odobren'),
    'rejected' => __('Odbijen'),
    default => __('Čeka odluku'),
};
$statusClass = static fn(string $status): string => match ($status) {
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-danger',
    default => 'text-bg-warning',
};
?>
<div class="card shadow-sm" id="api-key-requests">
    <div class="card-body p-4">
        <h2 class="h5 mb-2"><?= __('API pristup') ?></h2>
        <p class="text-body-secondary mb-3">
            <?= __('Zatražite osobni API ključ. Scope ograničava ključ, ali ne dodjeljuje prava koja već nemate u aplikaciji.') ?>
        </p>

        <?php if ($hasPendingRequest) : ?>
            <div class="alert alert-info" role="status">
                <?= __('Vaš zahtjev čeka odluku administratora. Novi zahtjev možete poslati nakon odluke.') ?>
            </div>
        <?php else : ?>
            <details class="border rounded mb-4">
                <summary class="fw-semibold p-3"><?= __('Zatraži API ključ') ?></summary>
                <form method="post" action="<?= $this->escape($requestPath) ?>" class="row g-3 p-3 pt-0">
                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="api-request-name"><?= __('Naziv') ?></label>
                        <input class="form-control" id="api-request-name" name="name" maxlength="190" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="api-request-expiry"><?= __('Istječe') ?></label>
                        <input class="form-control" id="api-request-expiry" name="expires_at" type="datetime-local">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="api-request-description"><?= __('Opis namjene') ?></label>
                        <textarea class="form-control" id="api-request-description" name="description" rows="3"></textarea>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="api-request-ips"><?= __('Dopuštene IP adrese') ?></label>
                        <textarea class="form-control" id="api-request-ips" name="allowed_ips" rows="3" placeholder="192.0.2.10&#10;10.0.0.0/8"></textarea>
                        <div class="form-text"><?= __('Prazno dopušta sve IP adrese.') ?></div>
                    </div>
                    <div class="col-12">
                        <div class="form-label"><?= __('Traženi scopeovi') ?></div>
                        <div class="row g-3">
                            <?php foreach ($scopeGroups as $group) : ?>
                                <?php
                                $groupLabel = is_array($group['label'] ?? null)
                                    ? (string)($group['label'][__('api_language')] ?? '')
                                    : '';
                                $groupScopes = is_array($group['scopes'] ?? null) ? $group['scopes'] : [];
                                ?>
                                <div class="col-12 col-lg-6">
                                    <fieldset class="border rounded p-3 h-100">
                                        <legend class="float-none w-auto px-1 fs-6"><?= $this->escape($groupLabel) ?></legend>
                                        <div class="d-grid gap-2">
                                            <?php foreach ($groupScopes as $scope) : ?>
                                                <?php
                                                $scopeName = is_array($scope) && is_scalar($scope['name'] ?? null)
                                                    ? (string)$scope['name']
                                                    : '';
                                                ?>
                                                <label class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="scopes[]" value="<?= $this->escape($scopeName) ?>">
                                                    <span class="form-check-label"><code><?= $this->escape($scopeName) ?></code></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit"><?= __('Pošalji zahtjev') ?></button>
                    </div>
                </form>
            </details>
        <?php endif; ?>

        <?php if ($requests !== []) : ?>
            <h3 class="h6"><?= __('Moji zahtjevi') ?></h3>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= __('Naziv') ?></th>
                            <th><?= __('Status') ?></th>
                            <th><?= __('Zatraženo') ?></th>
                            <th><?= __('Vrijedi do') ?></th>
                            <th class="text-end"><?= __('Radnje') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request) : ?>
                            <?php
                            $status = is_scalar($request['status'] ?? null) ? (string)$request['status'] : 'pending';
                            $uuid = is_scalar($request['uuid'] ?? null) ? (string)$request['uuid'] : '';
                            $canReveal = $status === 'approved' && (bool)($request['token_available'] ?? false);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= $this->escape((string)($request['name'] ?? '')) ?></strong>
                                    <?php if (trim((string)($request['decision_note'] ?? '')) !== '') : ?>
                                        <small class="d-block text-body-secondary">
                                            <?= __('Napomena administratora:') ?>
                                            <?= $this->escape((string)$request['decision_note']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $statusClass($status) ?>"><?= $statusLabel($status) ?></span></td>
                                <td><?= $this->escape($formatDateTime($request['created_at'] ?? null, '—')) ?></td>
                                <td><?= $this->escape($formatDateTime($request['expires_at'] ?? null, __('Bez isteka'))) ?></td>
                                <td class="text-end">
                                    <?php if ($canReveal) : ?>
                                        <a
                                            class="btn btn-sm btn-warning"
                                            href="<?= $this->escape(str_replace('__UUID__', rawurlencode($uuid), $revealPathTemplate)) ?>"
                                        ><?= __('Prikaži ključ jednom') ?></a>
                                    <?php elseif ($status === 'approved') : ?>
                                        <span class="small text-body-secondary"><?= __('Secret je već prikazan.') ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
