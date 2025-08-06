<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AmaxoniaMarcacionService
{
    /**
     * Procesa una marcación para Amaxonia
     */
    public static function procesarMarcacion($datos)
    {
        try {
            // Verificar si hay empresa configurada
            if (!DatabaseSwitchService::hayEmpresaConfigurada()) {
                throw new \Exception("No hay empresa configurada");
            }

            $empresa = DatabaseSwitchService::getEmpresaActual();
            $conexion = DatabaseSwitchService::getConexionEmpresa();

            Log::info("Procesando marcación para empresa: {$empresa['nombre']} - BD: {$empresa['bd']}");

            // Validar datos requeridos
            if (empty($datos['pin']) || empty($datos['fecha_hora'])) {
                throw new \Exception("Datos de marcación incompletos");
            }

            $pin = $datos['pin'];
            $fechaHora = $datos['fecha_hora'];
            $tipo = $datos['tipo'] ?? 'E'; // E=Entrada, S=Salida
            $dispositivo = $datos['dispositivo'] ?? 'APP_MOVIL';

            // Convertir fecha a formato de Amaxonia
            $fecha = Carbon::parse($fechaHora)->format('Y-m-d');
            $hora = Carbon::parse($fechaHora)->format('H:i:s');

            // Verificar si el empleado existe
            $empleado = $conexion->table('empleados')
                ->where('pin', $pin)
                ->where('activo', 1)
                ->first();

            if (!$empleado) {
                throw new \Exception("Empleado con PIN {$pin} no encontrado o inactivo");
            }

            // Insertar marcación en la tabla correspondiente
            $marcacionId = $conexion->table('marcaciones')->insertGetId([
                'cod_empleado' => $empleado->codigo,
                'fecha' => $fecha,
                'hora' => $hora,
                'tipo' => $tipo,
                'dispositivo' => $dispositivo,
                'fecha_creacion' => now(),
                'ip_origen' => request()->ip()
            ]);

            Log::info("Marcación procesada exitosamente - ID: {$marcacionId}, Empleado: {$empleado->codigo}, Fecha: {$fecha}, Hora: {$hora}");

            return [
                'success' => true,
                'message' => 'Marcación procesada exitosamente',
                'marcacion_id' => $marcacionId,
                'empleado' => [
                    'codigo' => $empleado->codigo,
                    'nombre' => $empleado->nombre,
                    'pin' => $empleado->pin
                ],
                'marcacion' => [
                    'fecha' => $fecha,
                    'hora' => $hora,
                    'tipo' => $tipo,
                    'dispositivo' => $dispositivo
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Error procesando marcación: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtiene las marcaciones de un empleado en un rango de fechas
     */
    public static function obtenerMarcaciones($pin, $fechaInicio, $fechaFin)
    {
        try {
            if (!DatabaseSwitchService::hayEmpresaConfigurada()) {
                throw new \Exception("No hay empresa configurada");
            }

            $conexion = DatabaseSwitchService::getConexionEmpresa();

            // Verificar si el empleado existe
            $empleado = $conexion->table('empleados')
                ->where('pin', $pin)
                ->where('activo', 1)
                ->first();

            if (!$empleado) {
                throw new \Exception("Empleado con PIN {$pin} no encontrado o inactivo");
            }

            // Obtener marcaciones
            $marcaciones = $conexion->table('marcaciones')
                ->where('cod_empleado', $empleado->codigo)
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->orderBy('fecha', 'asc')
                ->orderBy('hora', 'asc')
                ->get();

            return [
                'empleado' => [
                    'codigo' => $empleado->codigo,
                    'nombre' => $empleado->nombre,
                    'pin' => $empleado->pin
                ],
                'marcaciones' => $marcaciones
            ];

        } catch (\Exception $e) {
            Log::error("Error obteniendo marcaciones: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verifica el estado de un empleado (si está en turno o no)
     */
    public static function verificarEstadoEmpleado($pin)
    {
        try {
            if (!DatabaseSwitchService::hayEmpresaConfigurada()) {
                throw new \Exception("No hay empresa configurada");
            }

            $conexion = DatabaseSwitchService::getConexionEmpresa();

            // Verificar si el empleado existe
            $empleado = $conexion->table('empleados')
                ->where('pin', $pin)
                ->where('activo', 1)
                ->first();

            if (!$empleado) {
                throw new \Exception("Empleado con PIN {$pin} no encontrado o inactivo");
            }

            // Obtener la última marcación del día
            $hoy = Carbon::now()->format('Y-m-d');
            $ultimaMarcacion = $conexion->table('marcaciones')
                ->where('cod_empleado', $empleado->codigo)
                ->where('fecha', $hoy)
                ->orderBy('hora', 'desc')
                ->first();

            $estado = 'SIN_MARCAR';
            if ($ultimaMarcacion) {
                $estado = $ultimaMarcacion->tipo === 'E' ? 'EN_TURNO' : 'FUERA_TURNO';
            }

            return [
                'empleado' => [
                    'codigo' => $empleado->codigo,
                    'nombre' => $empleado->nombre,
                    'pin' => $empleado->pin
                ],
                'estado' => $estado,
                'ultima_marcacion' => $ultimaMarcacion
            ];

        } catch (\Exception $e) {
            Log::error("Error verificando estado de empleado: " . $e->getMessage());
            throw $e;
        }
    }
}
