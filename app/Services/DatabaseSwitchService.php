<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class DatabaseSwitchService
{
    private static $empresaActual = null;
    private static $conexionConfigurada = false;

    /**
     * Obtiene la información de una empresa por su código
     */
    public static function obtenerEmpresa($codEmpresa)
    {
        try {
            // Usar la conexión principal para buscar la empresa
            $empresa = DB::connection('mysql')
                ->table('nomempresa')
                ->where('codigo', $codEmpresa)
                ->where('nomina_activo', 1)
                ->first();

            if (!$empresa) {
                throw new \Exception("Empresa con código {$codEmpresa} no encontrada o nómina inactiva");
            }

            return [
                'codigo' => $empresa->codigo,
                'nombre' => $empresa->nombre,
                'bd' => $empresa->bd_nomina,
                'bd_contabilidad' => $empresa->bd_contabilidad,
                'bd_nomina' => $empresa->bd_nomina
            ];

        } catch (\Exception $e) {
            Log::error("Error obteniendo empresa: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Configura la conexión a la base de datos de una empresa específica
     */
    public static function setBdEmpresa($codEmpresa)
    {
        try {
            $empresa = self::obtenerEmpresa($codEmpresa);

            // Configurar conexión dinámica para la empresa
            $config = Config::get('database.connections.empresa_template');
            $config['database'] = $empresa['bd'];

            Config::set('database.connections.empresa', $config);

            // Purgar la conexión existente si existe
            DB::purge('empresa');

            // Probar la conexión
            DB::connection('empresa')->getPdo();

            self::$empresaActual = $empresa;
            self::$conexionConfigurada = true;

            Log::info("Conexión configurada para empresa: {$empresa['nombre']} - BD: {$empresa['bd']}");

            return $empresa;

        } catch (\Exception $e) {
            Log::error("Error configurando base de datos de empresa: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtiene la conexión de base de datos para la empresa actual
     */
    public static function getConexionEmpresa()
    {
        if (!self::$conexionConfigurada) {
            throw new \Exception("No se ha configurado conexión de empresa");
        }

        return DB::connection('empresa');
    }

    /**
     * Obtiene información de la empresa actual
     */
    public static function getEmpresaActual()
    {
        return self::$empresaActual;
    }

    /**
     * Verifica si hay una empresa configurada
     */
    public static function hayEmpresaConfigurada()
    {
        return self::$conexionConfigurada && self::$empresaActual !== null;
    }
}
