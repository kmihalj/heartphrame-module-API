<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Kreira prenosive tablice za zahtjeve korisnika, zaštitu API zahtjeva
     *     i pouzdanu asinkronu isporuku webhook događaja.
     * EN: Creates portable tables for user requests, API-request safeguards,
     *     and reliable asynchronous webhook delivery.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();

        if (!$schema->hasTable(ModuleApi::TABLE_RATE_LIMITS)) {
            $schema->create(ModuleApi::TABLE_RATE_LIMITS, static function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('api_key_id')->unsigned()->index();
                $table->timestamp('window_start');
                $table->integer('request_count')->unsigned()->default(0);
                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->unique(
                    ['api_key_id', 'window_start'],
                    'api_rate_key_window_unique',
                );
            });
        }

        if (!$schema->hasTable(ModuleApi::TABLE_IDEMPOTENCY_KEYS)) {
            $schema->create(ModuleApi::TABLE_IDEMPOTENCY_KEYS, static function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('api_key_id')->unsigned()->index();
                $table->string('idempotency_key', 190);
                $table->string('request_fingerprint', 64);
                $table->integer('response_status')->unsigned()->nullable();
                $table->text('response_headers_json')->nullable();
                $table->longText('response_body')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->unique(
                    ['api_key_id', 'idempotency_key'],
                    'api_idempotency_key_unique',
                );
            });
        }

        if (!$schema->hasTable(ModuleApi::TABLE_KEY_REQUESTS)) {
            $schema->create(ModuleApi::TABLE_KEY_REQUESTS, static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->bigInteger('user_id')->unsigned()->index();
                $table->string('name', 190);
                $table->text('description')->nullable();
                $table->longText('scopes_json');
                $table->text('allowed_ips_json')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 24)->default('pending')->index();
                $table->bigInteger('decided_by_user_id')->unsigned()->nullable()->index();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->bigInteger('api_key_id')->unsigned()->nullable()->index();
                $table->longText('encrypted_token')->nullable();
                $table->timestamp('token_revealed_at')->nullable();
                $table->timestamps();

                $table->index(
                    ['user_id', 'status', 'created_at'],
                    'api_key_request_user_status_idx',
                );
            });
        }

        if (!$schema->hasTable(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)) {
            $schema->create(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS, static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->bigInteger('owner_api_key_id')->unsigned()->index();
                $table->bigInteger('owner_user_id')->unsigned()->index();
                $table->string('name', 190);
                $table->string('target_url', 2048);
                $table->longText('events_json');
                $table->longText('encrypted_secret');
                $table->integer('is_active')->unsigned()->default(1)->index();
                $table->timestamps();

                $table->index(
                    ['owner_api_key_id', 'is_active', 'created_at'],
                    'api_webhook_owner_active_idx',
                );
            });
        }

        if (!$schema->hasTable(ModuleApi::TABLE_WEBHOOK_DELIVERIES)) {
            $schema->create(ModuleApi::TABLE_WEBHOOK_DELIVERIES, static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->bigInteger('subscription_id')->unsigned()->index();
                $table->string('event_uuid', 36)->index();
                $table->string('event_name', 190)->index();
                $table->longText('payload_json');
                $table->string('status', 24)->default('pending')->index();
                $table->integer('attempts')->unsigned()->default(0);
                $table->timestamp('available_at')->index();
                $table->timestamp('locked_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->integer('response_status')->unsigned()->nullable();
                $table->longText('response_body')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(
                    ['status', 'available_at', 'id'],
                    'api_webhook_delivery_worker_idx',
                );
                $table->index(
                    ['subscription_id', 'created_at', 'id'],
                    'api_webhook_delivery_subscription_idx',
                );
            });
        }
    }

    /**
     * HR: Uklanja samo tablice u vlasništvu API modula.
     * EN: Removes only tables owned by the API module.
     */
    public function down(Database $db): void
    {
        $db->schema()->dropIfExists(ModuleApi::TABLE_WEBHOOK_DELIVERIES);
        $db->schema()->dropIfExists(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS);
        $db->schema()->dropIfExists(ModuleApi::TABLE_KEY_REQUESTS);
        $db->schema()->dropIfExists(ModuleApi::TABLE_IDEMPOTENCY_KEYS);
        $db->schema()->dropIfExists(ModuleApi::TABLE_RATE_LIMITS);
    }
};
