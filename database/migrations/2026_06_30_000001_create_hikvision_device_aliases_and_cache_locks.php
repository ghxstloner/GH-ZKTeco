<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('mysql')->hasTable('hikvision_device_aliases')) {
            Schema::connection('mysql')->create('hikvision_device_aliases', function (Blueprint $table) {
                $table->id();
                $table->string('empresa_codigo', 30);
                $table->string('alias_device_id', 100);
                $table->string('canonical_device_id', 100);
                $table->unsignedSmallInteger('device_sequence');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('alias_device_id', 'uk_hik_alias_device_id');
                $table->unique(['empresa_codigo', 'canonical_device_id'], 'uk_hik_alias_empresa_canonical');
                $table->index(['empresa_codigo', 'is_active'], 'idx_hik_alias_empresa_active');
            });
        }

        $empresa27 = DB::connection('mysql')
            ->table('nomempresa')
            ->where('codigo', '27')
            ->where('nomina_activo', 1)
            ->exists();

        if ($empresa27) {
            DB::connection('mysql')->table('hikvision_device_aliases')->updateOrInsert(
                ['alias_device_id' => '27'],
                [
                    'empresa_codigo' => '27',
                    'canonical_device_id' => '27001',
                    'device_sequence' => 1,
                    'is_active' => 1,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        if (!Schema::connection('mysql')->hasTable('cache')) {
            Schema::connection('mysql')->create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (!Schema::connection('mysql')->hasTable('cache_locks')) {
            Schema::connection('mysql')->create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('hikvision_device_aliases');
    }
};
