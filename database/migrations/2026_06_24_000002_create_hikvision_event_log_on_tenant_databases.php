<?php

use App\Support\TenantMigrationRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `hikvision_event_log` en TODAS las bases tenant activas para
 * persistir los eventos AcsEvent (marcaciones) consultados vía PULL al
 * Bridge ISUP Hikvision.
 *
 * Idempotencia: la tabla usa DEDUP_KEY (varchar 64, NOT NULL, UNIQUE).
 * - Si el evento trae serialNo -> DEDUP_KEY = "{serial}|{bridge}|{serialNo}".
 * - Si no trae serialNo -> DEDUP_KEY = sha1 de los campos identificadores.
 * El nullable SERIAL_NO queda como columna normal indexada (no unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            if (Schema::connection($conn)->hasTable('hikvision_event_log')) {
                return;
            }

            Schema::connection($conn)->create('hikvision_event_log', function (Blueprint $t) {
                $t->unsignedBigInteger('EVENT_ID', true)->primary();
                $t->string('DEVICE_SERIAL', 100);
                $t->string('DEDUP_KEY', 64);
                $t->string('EMPLOYEE_NO', 50)->nullable();
                $t->unsignedInteger('FICHA')->nullable();
                $t->dateTime('EVENT_TIME');
                $t->unsignedInteger('MAJOR_EVENT')->nullable();
                $t->unsignedInteger('MINOR_EVENT')->nullable();
                $t->string('VERIFY_MODE', 50)->nullable();
                $t->string('ATTENDANCE_STATUS', 30)->nullable();
                $t->json('RAW_RESPONSE')->nullable();
                $t->unsignedTinyInteger('PROCESSED')->default(0);
                $t->string('SYNC_ERROR', 500)->nullable();
                $t->string('BRIDGE_DEVICE_ID', 100)->nullable();
                $t->string('EMPLOYEE_NO_STRING', 50)->nullable();
                $t->unsignedBigInteger('SERIAL_NO')->nullable();
                $t->string('EVENT_NAME', 255)->nullable();
                $t->unsignedInteger('CARD_READER_NO')->nullable();
                $t->unsignedInteger('DOOR_NO')->nullable();
                $t->unsignedInteger('CARD_TYPE')->nullable();
                $t->string('USER_TYPE', 50)->nullable();
                $t->string('MASK', 30)->nullable();
                $t->string('CURRENT_VERIFY_MODE', 50)->nullable();
                $t->json('FACE_RECT')->nullable();
                $t->string('SKIP_REASON', 255)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();

                $t->unique('DEDUP_KEY', 'uk_dedup_key');
                $t->index('EVENT_TIME', 'idx_event_time');
                $t->index('EMPLOYEE_NO', 'idx_employee');
                $t->index('PROCESSED', 'idx_processed');
            });
        });
    }

    public function down(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            Schema::connection($conn)->dropIfExists('hikvision_event_log');
        });
    }
};
