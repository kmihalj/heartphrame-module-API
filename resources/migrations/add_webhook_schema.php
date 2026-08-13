<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Nadograđuje ranije razvojne instalacije tablicama pouzdane webhook
     *     isporuke. Čista instalacija ih već dobiva iz početne migracije, pa su
     *     provjere postojanja namjerno idempotentne.
     * EN: Upgrades earlier development installations with reliable webhook
     *     delivery tables. A clean install already receives them from the
     *     initial migration, so the existence checks are deliberately idempotent.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();
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
     * HR: Ne briše tablice pri rollbacku korektivne migracije. Aktualna početna
     *     migracija također ih stvara pa se ne može pouzdano dokazati vlasništvo.
     * EN: Does not drop tables when rolling back the corrective migration. The
     *     current initial migration also creates them, so ownership cannot be proven.
     */
    public function down(Database $db): void
    {
        // HR: Namjerno bez destruktivnog postupka; vidi komentar metode.
        // EN: Intentionally non-destructive; see the method documentation.
    }
};
