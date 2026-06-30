<?php

use App\Support\TenantMigrationRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            if (Schema::connection($conn)->hasTable('asistencia_dispositivos')) {
                Schema::connection($conn)->table('asistencia_dispositivos', function (Blueprint $table) use ($conn) {
                    if (!Schema::connection($conn)->hasColumn('asistencia_dispositivos', 'isup_device_id_raw')) {
                        $table->string('isup_device_id_raw', 100)->nullable()->after('bridge_device_id');
                    }
                    if (!Schema::connection($conn)->hasColumn('asistencia_dispositivos', 'isup_device_id_canonical')) {
                        $table->string('isup_device_id_canonical', 100)->nullable()->after('isup_device_id_raw');
                    }
                    if (!Schema::connection($conn)->hasColumn('asistencia_dispositivos', 'device_sequence')) {
                        $table->unsignedSmallInteger('device_sequence')->nullable()->after('isup_device_id_canonical');
                    }
                    if (!Schema::connection($conn)->hasColumn('asistencia_dispositivos', 'physical_device_key')) {
                        $table->string('physical_device_key', 150)->nullable()->after('device_sequence');
                    }
                    if (!Schema::connection($conn)->hasColumn('asistencia_dispositivos', 'mac_address')) {
                        $table->string('mac_address', 50)->nullable()->after('physical_device_key');
                    }
                });

                Schema::connection($conn)->table('asistencia_dispositivos', function (Blueprint $table) {
                    $table->unique(['driver', 'isup_device_id_canonical'], 'uq_asistencia_driver_isup_canonical');
                    $table->unique(['empresa_codigo', 'driver', 'physical_device_key'], 'uq_asistencia_empresa_driver_physical');
                });
            }

            if (Schema::connection($conn)->hasTable('hikvision_device_info')) {
                Schema::connection($conn)->table('hikvision_device_info', function (Blueprint $table) use ($conn) {
                    if (!Schema::connection($conn)->hasColumn('hikvision_device_info', 'ISUP_DEVICE_ID_RAW')) {
                        $table->string('ISUP_DEVICE_ID_RAW', 100)->nullable()->after('BRIDGE_DEVICE_ID');
                    }
                    if (!Schema::connection($conn)->hasColumn('hikvision_device_info', 'ISUP_DEVICE_ID_CANONICAL')) {
                        $table->string('ISUP_DEVICE_ID_CANONICAL', 100)->nullable()->after('ISUP_DEVICE_ID_RAW');
                    }
                    if (!Schema::connection($conn)->hasColumn('hikvision_device_info', 'DEVICE_SEQUENCE')) {
                        $table->unsignedSmallInteger('DEVICE_SEQUENCE')->nullable()->after('ISUP_DEVICE_ID_CANONICAL');
                    }
                    if (!Schema::connection($conn)->hasColumn('hikvision_device_info', 'MAC_ADDRESS')) {
                        $table->string('MAC_ADDRESS', 50)->nullable()->after('DEVICE_SEQUENCE');
                    }
                    if (!Schema::connection($conn)->hasColumn('hikvision_device_info', 'PHYSICAL_DEVICE_KEY')) {
                        $table->string('PHYSICAL_DEVICE_KEY', 150)->nullable()->after('MAC_ADDRESS');
                    }
                });

                Schema::connection($conn)->table('hikvision_device_info', function (Blueprint $table) {
                    $table->unique('ISUP_DEVICE_ID_CANONICAL', 'uk_hik_device_isup_canonical');
                    $table->unique('PHYSICAL_DEVICE_KEY', 'uk_hik_device_physical_key');
                });
            }
        });
    }

    public function down(): void
    {
        TenantMigrationRunner::foreachTenant(function (string $conn) {
            if (Schema::connection($conn)->hasTable('asistencia_dispositivos')) {
                Schema::connection($conn)->table('asistencia_dispositivos', function (Blueprint $table) {
                    $table->dropUnique('uq_asistencia_driver_isup_canonical');
                    $table->dropUnique('uq_asistencia_empresa_driver_physical');
                });
            }

            if (Schema::connection($conn)->hasTable('hikvision_device_info')) {
                Schema::connection($conn)->table('hikvision_device_info', function (Blueprint $table) {
                    $table->dropUnique('uk_hik_device_isup_canonical');
                    $table->dropUnique('uk_hik_device_physical_key');
                });
            }
        });
    }
};
