<?php

use App\Support\TenantMigrationRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `hikvision_user_info` en TODAS las bases tenant activas para
 * rastrear el estado de sincronización de cada usuario provisionado a los
 * dispositivos Hikvision.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            if (Schema::connection($conn)->hasTable('hikvision_user_info')) {
                return;
            }

            Schema::connection($conn)->create('hikvision_user_info', function (Blueprint $t) {
                $t->unsignedBigInteger('USER_ID', true)->primary();
                $t->string('DEVICE_SERIAL', 100);
                $t->string('EMPLOYEE_NO', 50);
                $t->unsignedInteger('FICHA');
                $t->string('NAME', 255)->nullable();
                $t->unsignedTinyInteger('PHOTO_SYNCED')->default(0);
                $t->dateTime('LAST_SYNCED_AT')->nullable();
                // Valores: pending, synced, failed, offline,
                // validation_error, feature_disabled, unauthorized, not_found
                $t->string('SYNC_STATUS', 30)->default('pending');
                $t->text('SYNC_ERROR')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();

                $t->unique(['DEVICE_SERIAL', 'EMPLOYEE_NO'], 'uk_user_device');
                $t->index('FICHA', 'idx_ficha');
                $t->index('SYNC_STATUS', 'idx_sync_status');
            });
        });
    }

    public function down(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            Schema::connection($conn)->dropIfExists('hikvision_user_info');
        });
    }
};
