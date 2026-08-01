<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi;

/**
 * HR: Sadrži stabilne identifikatore kojima API modul povezuje middleware i kontrolere.
 *
 * EN: Holds stable identifiers used by the API module to connect middleware and controllers.
 */
final class ModuleApi
{
    public const PACKAGE_NAME = 'aaieduhr/heartphrame-module-api';

    public const TABLE_RATE_LIMITS = 'api_rate_limits';

    public const TABLE_IDEMPOTENCY_KEYS = 'api_idempotency_keys';

    public const TABLE_KEY_REQUESTS = 'api_key_requests';

    public const TABLE_WEBHOOK_SUBSCRIPTIONS = 'api_webhook_subscriptions';

    public const TABLE_WEBHOOK_DELIVERIES = 'api_webhook_deliveries';

    public const REQUEST_ID_ATTRIBUTE = 'heartphrame.api.request_id';

    public const IDENTITY_ATTRIBUTE = 'heartphrame.api.identity';

    /**
     * HR: Sprječava instanciranje klase koja služi samo kao spremnik konstanti.
     *
     * EN: Prevents instantiation of this constants-only container.
     */
    private function __construct()
    {
    }
}
