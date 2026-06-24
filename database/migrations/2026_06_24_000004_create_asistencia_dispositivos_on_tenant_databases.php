<?php

use App\Support\TenantMigrationRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `asistencia_dispositivos` en TODAS las bases tenant activas.
 *
 * Catálogo unificado de dispositivos de asistencia: Hikvision primero,
 * ZKTeco/ProFaceX después (en una fase posterior). Cada tenant mantiene su
 * propia fila por (driver, source_table, source_device_id, empresa_codigo),
 * por lo que el mismo dispositivo FÍSICO puede aparecer duplicado en varios
 * tenants — esto es intencional (regla de negocio confirmada por el usuario).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            if (Schema::connection($conn)->hasTable('asistencia_dispositivos')) {
                return;
            }

            Schema::connection($conn)->create('asistencia_dispositivos', function (Blueprint $t) {
                $t->id();
                $t->string('empresa_codigo', 30)->nullable();
                $t->unsignedBigInteger('id_proyecto')->nullable();
                $t->string('driver', 30);
                $t->string('marca', 80)->nullable();
                $t->string('nombre', 255);
                $t->string('source_table', 80);
                $t->string('source_device_id', 100);
                $t->string('bridge_device_id', 100)->nullable();
                $t->string('serial', 150)->nullable();
                $t->string('estado', 30)->default('Activo');
                $t->boolean('is_active')->default(true);
                $t->dateTime('last_seen_at')->nullable();
                $t->json('metadata')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();

                // unique inclusivo: mismo dispositivo FÍSICO puede repetirse
                // entre empresas; la PK de unicidad es driver+source+empresa.
                $t->unique(
                    ['driver', 'source_table', 'source_device_id', 'empresa_codigo'],
                    'uq_asistencia_driver_source_empresa',
                );
                $t->index(['driver', 'is_active'], 'idx_asistencia_driver_active');
                $t->index(['driver', 'bridge_device_id'], 'idx_asistencia_bridge');
                $t->index('empresa_codigo', 'idx_asistencia_empresa');
                $t->index('id_proyecto', 'idx_asistencia_dispositivos_proyecto');
            });
        });
    }

    public function down(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            Schema::connection($conn)->dropIfExists('asistencia_dispositivos');
        });
    }
};
