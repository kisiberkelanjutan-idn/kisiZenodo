<?php

/**
 * @file plugins/generic/kisiZenodo/classes/migration/KisiZenodoMigration.php
 *
 * Idempotent schema installer for tracking Zenodo draft reservations and published deposits.
 */

namespace APP\plugins\generic\kisiZenodo\classes\migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class KisiZenodoMigration extends Migration
{
    public const TABLE_NAME = 'kisi_zenodo_deposits';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        Schema::create(self::TABLE_NAME, static function (Blueprint $table): void {
            $table->bigIncrements('deposit_id');
            $table->bigInteger('context_id');
            $table->bigInteger('submission_id');
            $table->bigInteger('publication_id')->default(0);
            $table->string('environment', 24)->default('sandbox');
            $table->string('zenodo_record_id', 64)->nullable();
            $table->string('zenodo_request_id', 64)->nullable();
            $table->string('reserved_doi', 128)->nullable();
            $table->string('concept_doi', 128)->nullable();
            $table->string('uploaded_filename', 255)->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['context_id', 'submission_id', 'environment'], 'kisi_zenodo_ctx_sub_env_uq');
            $table->index(['context_id', 'status'], 'kisi_zenodo_ctx_status_idx');
        });
    }

    public function down(): void
    {
        // Intentionally preserve deposit DOI mapping records when plugin is disabled/reloaded.
    }
}
