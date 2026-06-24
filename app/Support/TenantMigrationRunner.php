<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Itera sobre las bases de datos tenant listadas en `nomempresa.bd_nomina`
 * y ejecuta un callback por cada una, con la conexión `empresa` ya apuntando
 * a la BD correcta.
 *
 * Reglas (definidas por contrato con el usuario):
 *  1. Lee `nomempresa WHERE nomina_activo = 1`, columna `bd_nomina`, por la
 *     conexión central `mysql` (planilla_configuracion).
 *  2. Por cada tenant, clona `database.connections.empresa_template`,
 *     sobreescribe `database`, lo registra como `database.connections.empresa`
 *     y purga la conexión previa (mismo baile que
 *     `App\Services\DatabaseSwitchService::setBdEmpresa`).
 *  3. Si la BD tenant NO EXISTE (SQLSTATE HY000 / Unknown database / code
 *     1049), se OMITE con un warning y se continúa. Sin excepción.
 *  4. Si la BD existe pero ocurre cualquier OTRO error (permisos, SQL,
 *     estructura, conexión rechazada, timeout) -> la excepción se RELANZA
 *     y la migración/comando FALLA. NUNCA engullir excepciones reales.
 *  5. La conexión central `mysql` nunca se toca.
 *
 * Uso típico dentro de un migration:
 *   TenantMigrationRunner::foreachTenant(function (string $conn, object $t) {
 *       Schema::connection($conn)->create('hikvision_device_info', ...);
 *   });
 */
class TenantMigrationRunner
{
    /**
     * @param  callable(string, object): void  $fn  Recibe (nombreConexion='empresa', fila nomempresa con codigo/bd_nomina/nombre).
     * @param  string|null  $onlyCodigo  Si se pasa, ejecuta solo esa empresa (filtro por codigo).
     */
    public static function foreachTenant(callable $fn, ?string $onlyCodigo = null): void
    {
        $query = DB::connection('mysql')
            ->table('nomempresa')
            ->where('nomina_activo', 1)
            ->select(['codigo', 'bd_nomina', 'nombre']);

        if ($onlyCodigo !== null && $onlyCodigo !== '') {
            $query->where('codigo', $onlyCodigo);
        }

        $tenants = $query->get();

        foreach ($tenants as $tenant) {
            $bd = $tenant->bd_nomina ?? null;

            if ($bd === null || $bd === '') {
                Log::warning('[TenantMigration] empresa {cod} sin bd_nomina, se omite', [
                    'cod' => $tenant->codigo,
                ]);
                continue;
            }

            // Construir conexión tenant con el mismo formato que DatabaseSwitchService.
            $config = Config::get('database.connections.empresa_template');
            $config['database'] = $bd;
            Config::set('database.connections.empresa', $config);
            DB::purge('empresa');

            // Si la BD no existe, omitir: cualquier otro error -> relanzar.
            if (!self::databaseExists($bd)) {
                Log::warning('[TenantMigration] BD inexistente, se omite: {bd} (empresa {cod})', [
                    'bd' => $bd,
                    'cod' => $tenant->codigo,
                ]);
                continue;
            }

            Log::info('[TenantMigration] aplicando migración en tenant {cod} -> bd={bd}', [
                'cod' => $tenant->codigo,
                'bd' => $bd,
            ]);

            // Si $fn lanza, la excepción se propaga y la migración falla.
            $fn('empresa', $tenant);
        }
    }

    /**
     * Verifica si una base de datos existe en el servidor MySQL configurado
     * en `empresa_template`. Usa una conexión PDO nueva (sin tocar la
     * conexión `mysql` central) para hacer un SCHEMATA lookup.
     *
     * Devuelve true si existe; false si explícitamente no existe.
     * Para cualquier OTRO error (permisos, host caído, timeout) se RELANZA.
     */
    private static function databaseExists(string $database): bool
    {
        $template = Config::get('database.connections.empresa_template');

        try {
            // Conexión probe fresca contra el mismo host, sin 'database'.
            $probeConfig = $template;
            $probeConfig['database'] = null;
            Config::set('database.connections.empresa_probe', $probeConfig);
            DB::purge('empresa_probe');

            $exists = DB::connection('empresa_probe')
                ->selectOne(
                    'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                    [$database],
                );

            return $exists !== null;
        } catch (\Throwable $e) {
            // Distinción crítica:
            //  - "Unknown database" / SQLSTATE HY000 code 1049 -> la BD NO existe, omitir.
            //  - Cualquier otra cosa (access denied, host caído, timeout) -> propagar.
            if (self::isDatabaseNotFoundError($e)) {
                return false;
            }
            throw $e;
        } finally {
            try {
                DB::purge('empresa_probe');
            } catch (\Throwable $_) {
                // silencioso
            }
        }
    }

    /**
     * Determina si el error indica EXPLÍCITAMENTE que la BD no existe.
     * Solo cuenta: SQLSTATE HY000 code 1049 / mensaje "unknown database".
     * Todo lo demás (access denied, connection refused, host caído, timeout,
     * unknown column, etc.) NO es "no existe" y debe propagar.
     */
    private static function isDatabaseNotFoundError(\Throwable $e): bool
    {
        $msg = mb_strtolower((string) $e->getMessage());
        $code = (string) $e->getCode();
        $prev = $e->getPrevious();
        $prevMsg = $prev ? mb_strtolower((string) $prev->getMessage()) : '';

        // PDO/SQLSTATE canónico: SQLSTATE[HY000] [1049] Unknown database 'x'
        if ($code === '1049' || str_contains($msg, '1049]') || str_contains($prevMsg, '1049]')) {
            return true;
        }

        return str_contains($msg, 'unknown database')
            || str_contains($prevMsg, 'unknown database');
    }
}
