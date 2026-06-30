<?php

namespace Tests\Feature\Hikvision;

use App\Models\Attendance\AsistenciaDispositivo;
use App\Services\Attendance\AsistenciaDispositivoService;
use App\Services\DatabaseSwitchService;
use App\Services\Hikvision\HikvisionDeviceIdentityResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HikvisionDeviceProvisioningTest extends TestCase
{
    private string $centralDatabase;

    private string $tenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centralDatabase = tempnam(sys_get_temp_dir(), 'flow-central-');
        $this->tenantDatabase = tempnam(sys_get_temp_dir(), 'flow-tenant-');

        $sqlite = [
            'driver' => 'sqlite',
            'database' => $this->centralDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ];
        $tenantSqlite = $sqlite;
        $tenantSqlite['database'] = $this->tenantDatabase;

        Config::set('database.connections.mysql', $sqlite);
        Config::set('database.connections.empresa_template', $tenantSqlite);
        DB::purge('mysql');
        DB::purge('empresa');

        Schema::connection('mysql')->create('nomempresa', function ($table) {
            $table->integer('codigo');
            $table->string('nombre')->nullable();
            $table->string('bd_nomina');
            $table->string('bd_contabilidad')->nullable();
            $table->integer('nomina_activo')->default(1);
        });

        Schema::connection('mysql')->create('hikvision_device_aliases', function ($table) {
            $table->id();
            $table->string('empresa_codigo', 30);
            $table->string('alias_device_id', 100)->unique();
            $table->string('canonical_device_id', 100);
            $table->unsignedSmallInteger('device_sequence');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::connection('mysql')->table('nomempresa')->insert([
            'codigo' => 27,
            'nombre' => 'Empresa 27',
            'bd_nomina' => $this->tenantDatabase,
            'bd_contabilidad' => null,
            'nomina_activo' => 1,
        ]);

        DB::connection('mysql')->table('hikvision_device_aliases')->insert([
            'empresa_codigo' => '27',
            'alias_device_id' => '27',
            'canonical_device_id' => '27001',
            'device_sequence' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DatabaseSwitchService::setBdEmpresa('27');
        $this->createTenantTables();
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        DB::purge('empresa');

        if (isset($this->centralDatabase) && is_file($this->centralDatabase)) {
            @unlink($this->centralDatabase);
        }
        if (isset($this->tenantDatabase) && is_file($this->tenantDatabase)) {
            @unlink($this->tenantDatabase);
        }

        parent::tearDown();
    }

    public function test_padded_and_unpadded_ids_upsert_one_device(): void
    {
        $this->upsertHikvision('00027001', 'SERIAL-A');
        $this->upsertHikvision('27001', 'SERIAL-B');

        $rows = DB::connection('empresa')->table('asistencia_dispositivos')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('27001', $rows[0]->source_device_id);
        $this->assertSame('SERIAL-B', $rows[0]->serial);
    }

    public function test_serial_change_with_same_canonical_and_mac_updates_same_row(): void
    {
        $this->upsertHikvision('27001', 'DS-K1T321MFWX20241227V030920ENGF6877195', '88de39375b0a');
        $this->upsertHikvision('27001', 'DS-K1T321MFWX20241227V030920ENGF6877196', '88:de:39:37:5b:0a');

        $rows = DB::connection('empresa')->table('asistencia_dispositivos')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('DS-K1T321MFWX20241227V030920ENGF6877196', $rows[0]->serial);
        $this->assertSame('mac:88de39375b0a', $rows[0]->physical_device_key);
    }

    public function test_two_sequences_for_same_company_create_two_devices(): void
    {
        $this->upsertHikvision('27001', 'SERIAL-1', '88de39375b0a');
        $this->upsertHikvision('27002', 'SERIAL-2', '88de39375b0b');

        $rows = DB::connection('empresa')->table('asistencia_dispositivos')->orderBy('source_device_id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['27001', '27002'], $rows->pluck('source_device_id')->all());
    }

    public function test_login_id_change_does_not_create_new_device(): void
    {
        $this->upsertHikvision('27001', 'SERIAL-A', '88de39375b0a', 1);
        $this->upsertHikvision('27001', 'SERIAL-A', '88de39375b0a', 2);

        $rows = DB::connection('empresa')->table('asistencia_dispositivos')->get();
        $metadata = json_decode($rows[0]->metadata, true);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $metadata['bridgeRaw']['loginId']);
    }

    public function test_absence_of_mac_does_not_break_upsert(): void
    {
        $this->upsertHikvision('27001', 'SERIAL-A');

        $this->assertSame(1, DB::connection('empresa')->table('asistencia_dispositivos')->count());
    }

    public function test_dedupe_command_merges_legacy_duplicates_and_references(): void
    {
        DB::connection('empresa')->table('asistencia_dispositivos')->insert([
            [
                'id' => 3,
                'empresa_codigo' => '27',
                'driver' => 'hikvision',
                'marca' => 'Hikvision',
                'nombre' => 'Hikvision 27',
                'source_table' => 'hikvision_device_info',
                'source_device_id' => '27',
                'bridge_device_id' => '27',
                'serial' => '27',
                'estado' => 'Activo',
                'is_active' => 1,
                'metadata' => json_encode(['bridgeRaw' => ['deviceId' => '27', 'loginId' => 1]]),
            ],
            [
                'id' => 4,
                'empresa_codigo' => '27',
                'driver' => 'hikvision',
                'marca' => 'Hikvision',
                'nombre' => 'Access Controller',
                'source_table' => 'hikvision_device_info',
                'source_device_id' => 'DS-K1T321MFWX20241227V030920ENGF6877195',
                'bridge_device_id' => '27',
                'serial' => 'DS-K1T321MFWX20241227V030920ENGF6877195',
                'estado' => 'Activo',
                'is_active' => 1,
                'metadata' => json_encode(['model' => 'DS-K1T321MFWX', 'firmware' => 'V3.9.20', 'bridgeRaw' => ['deviceId' => '27', 'loginId' => 1]]),
            ],
            [
                'id' => 5,
                'empresa_codigo' => '27',
                'driver' => 'hikvision',
                'marca' => 'Hikvision',
                'nombre' => 'Access Controller',
                'source_table' => 'hikvision_device_info',
                'source_device_id' => 'DS-K1T321MFWX20241227V030920ENGF6877196',
                'bridge_device_id' => '27',
                'serial' => 'DS-K1T321MFWX20241227V030920ENGF6877196',
                'estado' => 'Activo',
                'is_active' => 1,
                'metadata' => json_encode(['model' => 'DS-K1T321MFWX', 'firmware' => 'V3.9.20', 'bridgeRaw' => ['deviceId' => '27', 'loginId' => 2]]),
            ],
        ]);

        DB::connection('empresa')->table('personal_dispositivos')->insert([
            ['id' => 1, 'asistencia_dispositivo_id' => 3],
            ['id' => 2, 'asistencia_dispositivo_id' => 4],
            ['id' => 3, 'asistencia_dispositivo_id' => 4],
            ['id' => 4, 'asistencia_dispositivo_id' => 5],
        ]);

        Artisan::call('hikvision:dedupe-devices', ['--empresa' => '27', '--apply' => true]);

        $survivor = DB::connection('empresa')->table('asistencia_dispositivos')->where('id', 4)->first();
        $inactive = DB::connection('empresa')->table('asistencia_dispositivos')->whereIn('id', [3, 5])->pluck('is_active')->all();
        $references = DB::connection('empresa')->table('personal_dispositivos')->pluck('asistencia_dispositivo_id')->all();

        $this->assertSame('27001', $survivor->source_device_id);
        $this->assertSame('27001', $survivor->isup_device_id_canonical);
        $this->assertSame(1, (int) $survivor->device_sequence);
        $this->assertSame([0, 0], $inactive);
        $this->assertSame([4, 4, 4, 4], $references);
    }

    private function upsertHikvision(string $rawDeviceId, string $serial, ?string $macAddress = null, int $loginId = 1): void
    {
        $identity = HikvisionDeviceIdentityResolver::resolve($rawDeviceId);
        $mac = HikvisionDeviceIdentityResolver::normalizeMacAddress($macAddress);
        $physicalKey = HikvisionDeviceIdentityResolver::physicalDeviceKey($mac);

        AsistenciaDispositivoService::upsert(
            driver: AsistenciaDispositivo::DRIVER_HIKVISION,
            marca: 'Hikvision',
            row: [
                'source_table' => 'hikvision_device_info',
                'source_device_id' => $identity->canonicalDeviceId,
                'nombre' => 'Access Controller',
                'serial' => $serial,
                'bridge_device_id' => $identity->rawDeviceId,
                'isup_device_id_raw' => $identity->rawDeviceId,
                'isup_device_id_canonical' => $identity->canonicalDeviceId,
                'device_sequence' => $identity->deviceSequence,
                'physical_device_key' => $physicalKey,
                'mac_address' => $mac,
                'estado' => 'Activo',
                'is_active' => true,
            ],
            metadata: [
                'bridgeRaw' => ['deviceId' => $identity->rawDeviceId, 'loginId' => $loginId],
            ],
        );
    }

    private function createTenantTables(): void
    {
        Schema::connection('empresa')->create('asistencia_dispositivos', function ($table) {
            $table->id();
            $table->string('empresa_codigo', 30)->nullable();
            $table->unsignedBigInteger('id_proyecto')->nullable();
            $table->string('driver', 30);
            $table->string('marca', 80)->nullable();
            $table->string('nombre', 255);
            $table->string('source_table', 80);
            $table->string('source_device_id', 100);
            $table->string('bridge_device_id', 100)->nullable();
            $table->string('isup_device_id_raw', 100)->nullable();
            $table->string('isup_device_id_canonical', 100)->nullable();
            $table->unsignedSmallInteger('device_sequence')->nullable();
            $table->string('physical_device_key', 150)->nullable();
            $table->string('mac_address', 50)->nullable();
            $table->string('serial', 150)->nullable();
            $table->string('estado', 30)->default('Activo');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::connection('empresa')->create('hikvision_device_info', function ($table) {
            $table->increments('DEVICE_ID');
            $table->string('DEVICE_SERIAL', 100);
            $table->string('DEVICE_NAME', 255)->default('');
            $table->string('BRIDGE_DEVICE_ID', 50)->nullable();
            $table->string('ISUP_DEVICE_ID_RAW', 100)->nullable();
            $table->string('ISUP_DEVICE_ID_CANONICAL', 100)->nullable();
            $table->unsignedSmallInteger('DEVICE_SEQUENCE')->nullable();
            $table->string('MAC_ADDRESS', 50)->nullable();
            $table->string('PHYSICAL_DEVICE_KEY', 150)->nullable();
            $table->string('STATE', 30)->default('Online');
            $table->unsignedTinyInteger('IS_ACTIVE')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::connection('empresa')->create('personal_dispositivos', function ($table) {
            $table->id();
            $table->unsignedBigInteger('asistencia_dispositivo_id');
        });
    }
}
