<?php

namespace App\Services\Hikvision;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HikvisionDeviceIdentityResolver
{
    public const ALIASES_TABLE = 'hikvision_device_aliases';

    public static function resolve(string $rawDeviceId, ?string $empresaHint = null): ?HikvisionDeviceIdentity
    {
        $raw = trim($rawDeviceId);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $canonical = static::canonicalizeNumericId($raw);
        if (strlen($canonical) >= 4) {
            return static::fromCanonical($raw, $canonical, $empresaHint);
        }

        return static::resolveLegacyAlias($raw, $empresaHint);
    }

    public static function normalizeMacAddress(?string $macAddress): ?string
    {
        if ($macAddress === null) {
            return null;
        }

        $normalized = strtolower(preg_replace('/[^0-9a-f]/i', '', $macAddress) ?? '');

        return strlen($normalized) === 12 ? $normalized : null;
    }

    public static function extractMacAddress(array $deviceInfo): ?string
    {
        $direct = static::normalizeMacAddress($deviceInfo['macAddress'] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        $rawResponse = (string) ($deviceInfo['rawResponse'] ?? '');
        if ($rawResponse === '') {
            return null;
        }

        if (preg_match('/<macAddress>\s*([^<]+)\s*<\/macAddress>/i', $rawResponse, $matches) !== 1) {
            return null;
        }

        return static::normalizeMacAddress($matches[1] ?? null);
    }

    public static function physicalDeviceKey(?string $macAddress): ?string
    {
        $mac = static::normalizeMacAddress($macAddress);

        return $mac === null ? null : 'mac:'.$mac;
    }

    private static function fromCanonical(
        string $raw,
        string $canonical,
        ?string $empresaHint,
        bool $legacyAlias = false,
    ): ?HikvisionDeviceIdentity {
        if (strlen($canonical) < 4) {
            return null;
        }

        $sequence = (int) substr($canonical, -3);
        if ($sequence < 1 || $sequence > 999) {
            return null;
        }

        $empresaCodigo = ltrim(substr($canonical, 0, -3), '0');
        if ($empresaCodigo === '') {
            return null;
        }

        if ($empresaHint !== null && $empresaHint !== '' && $empresaCodigo !== (string) ((int) $empresaHint)) {
            return null;
        }

        return new HikvisionDeviceIdentity(
            rawDeviceId: $raw,
            canonicalDeviceId: $canonical,
            empresaCodigo: $empresaCodigo,
            deviceSequence: $sequence,
            legacyAlias: $legacyAlias,
        );
    }

    private static function resolveLegacyAlias(string $raw, ?string $empresaHint): ?HikvisionDeviceIdentity
    {
        $alias = static::findAlias($raw);
        if ($alias !== null) {
            $identity = static::fromCanonical(
                raw: $raw,
                canonical: (string) $alias->canonical_device_id,
                empresaHint: $empresaHint,
                legacyAlias: true,
            );

            if ($identity !== null) {
                return $identity;
            }
        }

        return static::createDefaultLegacyAlias($raw, $empresaHint);
    }

    private static function findAlias(string $raw): ?object
    {
        if (!static::aliasesTableExists()) {
            return null;
        }

        return DB::connection('mysql')
            ->table(static::ALIASES_TABLE)
            ->where('alias_device_id', $raw)
            ->where('is_active', 1)
            ->first();
    }

    private static function createDefaultLegacyAlias(string $raw, ?string $empresaHint): ?HikvisionDeviceIdentity
    {
        $empresaCodigo = (string) ((int) $raw);
        if ($empresaCodigo === '0') {
            return null;
        }

        if ($empresaHint !== null && $empresaHint !== '' && $empresaCodigo !== (string) ((int) $empresaHint)) {
            return null;
        }

        if (!static::activeEmpresaExists($empresaCodigo)) {
            return null;
        }

        $canonical = (string) (((int) $empresaCodigo * 1000) + 1);

        if (static::aliasesTableExists()) {
            DB::connection('mysql')->table(static::ALIASES_TABLE)->updateOrInsert(
                ['alias_device_id' => $raw],
                [
                    'empresa_codigo' => $empresaCodigo,
                    'canonical_device_id' => $canonical,
                    'device_sequence' => 1,
                    'is_active' => 1,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return static::fromCanonical($raw, $canonical, $empresaHint, true);
    }

    private static function activeEmpresaExists(string $empresaCodigo): bool
    {
        return DB::connection('mysql')
            ->table('nomempresa')
            ->where('codigo', $empresaCodigo)
            ->where('nomina_activo', 1)
            ->exists();
    }

    private static function aliasesTableExists(): bool
    {
        try {
            return Schema::connection('mysql')->hasTable(static::ALIASES_TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function canonicalizeNumericId(string $raw): string
    {
        $canonical = ltrim($raw, '0');

        return $canonical === '' ? '0' : $canonical;
    }
}
