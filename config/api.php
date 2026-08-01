<?php

declare(strict_types=1);

/**
 * HR: API modul oglašava scopeove za upravljanje vlastitim webhook pretplatama.
 * EN: The API module advertises scopes for managing owned webhook subscriptions.
 */
return [
    'module' => 'api',
    'resources' => [
        'webhooks' => [
            'label' => [
                'hr' => 'Webhookovi',
                'en' => 'Webhooks',
            ],
            'description' => [
                'hr' => 'Pretplate na događaje i pregled rezultata isporuke.',
                'en' => 'Event subscriptions and delivery result inspection.',
            ],
            'scopes' => [
                'webhooks:read' => [
                    'label' => ['hr' => 'Čitanje', 'en' => 'Read'],
                    'description' => [
                        'hr' => 'Pregled vlastitih pretplata i pokušaja isporuke.',
                        'en' => 'Inspect owned subscriptions and delivery attempts.',
                    ],
                ],
                'webhooks:manage' => [
                    'label' => ['hr' => 'Upravljanje', 'en' => 'Manage'],
                    'description' => [
                        'hr' => 'Kreiranje, izmjena, rotacija tajne i uklanjanje vlastitih pretplata.',
                        'en' => 'Create, update, rotate secrets, and remove owned subscriptions.',
                    ],
                ],
            ],
        ],
    ],
];
