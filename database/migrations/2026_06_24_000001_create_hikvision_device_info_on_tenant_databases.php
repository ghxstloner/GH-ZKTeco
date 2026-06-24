<?php

use App\Support\TenantMigrationRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `hikvision_device_info` en TODAS las bases tenant activas.
 *
 * - Se omite el tenant si su BD NO existe (warning, sin fallo).
 * - Falla explícitamente si la BD existe pero la migración no se puede
 *   ejecutar (SQL, permisos, estructura, conexión).
 *
 * NOTA de diseño: no se crean aquí USERNAME/PASSWORD/BRIDGE_TOKEN por
 * dispositivo. Los secretos (URL + token del Bridge) viven solo en
 * config/hikvision.php desde .env. Esta fase funciona exclusivamente en
 * modo bridge (TRANSPORT_MODE = 'bridge').
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            if (Schema::connection($conn)->hasTable('hikvision_device_info')) {
                return;
            }

            Schema::connection($conn)->create('hikvision_device_info', function (Blueprint $t) {
                $t->unsignedBigInteger('DEVICE_ID', true)->primary();
                $t->string('DEVICE_SERIAL', 100);
                $t->string('DEVICE_NAME', 255)->default('');
                $t->string('IP_ADDRESS', 45)->default('');
                $t->unsignedSmallInteger('PORT')->default(80);
                $t->string('STATE', 30)->default('Online');
                $t->dateTime('LAST_ACTIVITY')->nullable();
                $t->dateTime('LAST_POLLED_AT')->nullable();
                $t->string('FW_VERSION', 50)->nullable();
                $t->string('DEVICE_TYPE', 50)->nullable();
                $t->string('ISAPI_VERSION', 20)->nullable();
                $t->string('DEVICE_TIMEZONE', 50)->nullable();
                $t->unsignedInteger('USER_COUNT')->default(0);
                $t->unsignedTinyInteger('IS_ACTIVE')->default(1);
                $t->string('TRANSPORT_MODE', 20)->default('bridge');
                $t->string('BRIDGE_URL', 255)->nullable();
                $t->string('BRIDGE_DEVICE_ID', 50)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();

                $t->unique('DEVICE_SERIAL', 'uk_device_serial');
                $t->index('IS_ACTIVE', 'idx_is_active');
            });
        });
    }

    public function down(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            Schema::connection($conn)->dropIfExists('hikvision_device_info');
        });
    }
};
