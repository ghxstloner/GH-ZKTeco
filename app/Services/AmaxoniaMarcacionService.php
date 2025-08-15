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
            if (!DatabaseSwitchService::hayEmpresaConfigurada()) {
                throw new \Exception('No hay empresa configurada');
            }

            $empresa = DatabaseSwitchService::getEmpresaActual();
            $db = DatabaseSwitchService::getConexionEmpresa();

            // Validación mínima
            if (empty($datos['pin']) || empty($datos['fecha_hora'])) {
                throw new \Exception('Datos de marcación incompletos');
            }

            $pinIngresado = (string)$datos['pin'];
            $fechaHoraOriginal = Carbon::parse($datos['fecha_hora']);
            $fecha = $fechaHoraOriginal->format('Y-m-d');
            $hora = $fechaHoraOriginal->format('H:i:s');

            // 1) Tipo de marcación de la empresa
            $tipoEmpresa = (string)($db->table('nomempresa')->value('tipo_empresa') ?? '0');

            // 2) Buscar colaborador por ficha (fallback por cédula)
            $empleado = $db->table('nompersonal as n')
                ->leftJoin('proyectos as p', 'p.idProyecto', '=', 'n.proyecto')
                ->select(
                    'n.personal_id',
                    'n.ficha',
                    'n.cedula',
                    'n.apenom',
                    'n.estado',
                    'n.proyecto',
                    'n.tipnom',
                    'n.codnivel1',
                    'p.idDispositivo',
                    'p.lat',
                    'p.lng',
                    'p.descripcionCorta as puestotrabajo'
                )
                ->where(function ($q) use ($pinIngresado) {
                    $q->where('n.ficha', $pinIngresado)
                      ->orWhere('n.cedula', $pinIngresado);
                })
                ->first();

            if (!$empleado) {
                throw new \Exception("Colaborador no encontrado para PIN/Documento {$pinIngresado}");
            }

            if (!in_array($empleado->estado, ['Activo', 'REGULAR'])) {
                throw new \Exception('El colaborador se encuentra en estado: ' . $empleado->estado);
            }

            $ficha = $empleado->ficha;
            $turno = $db->table('nomcalendarios_personal')
                ->where('ficha', $ficha)
                ->where('fecha', $fecha)
                ->value('turno_id');

            // 3) Encabezado del día (reloj_encabezado)
            $codEncabezado = $db->table('reloj_encabezado')
                ->whereRaw('? between fecha_ini and fecha_fin', [$fecha])
                ->value('cod_enca');

            if (empty($codEncabezado)) {
                $codEncabezado = $db->table('reloj_encabezado')->insertGetId([
                    'fecha_reg' => $fecha,
                    'fecha_ini' => $fecha,
                    'fecha_fin' => $fecha,
                    'status' => 'Pendiente',
                    'usuario_creacion' => 'admin',
                    'usuario_edicion' => 'admin',
                    'fecha_edicion' => now(),
                    'usuario_preaprobacion' => null,
                    'fecha_preaprobacion' => null,
                    'usuario_aprobacion' => '',
                    'fecha_aprobacion' => '0000-00-00 00:00:00',
                    'tipo_nomina' => $empleado->tipnom,
                ]);
            }

            // 4) Buscar detalle del día (reloj_detalle)
            $detalle = $db->table('reloj_detalle')
                ->select('id', 'entrada', 'salmuerzo', 'ealmuerzo', 'salida')
                ->where('ficha', $ficha)
                ->where('fecha', $fecha)
                ->first();

            // 5) Insertar en reloj_marcaciones (log simple)
            $urlGmap = null;
            if (!empty($empleado->lat) && !empty($empleado->lng)) {
                $urlGmap = 'https://www.google.es/maps/place/' . $empleado->lat . ',' . $empleado->lng;
            }

            $db->beginTransaction();

            $db->table('reloj_marcaciones')->insert([
                'id_empleado' => $empleado->personal_id,
                'ficha_empleado' => $ficha,
                'fecha' => $fecha,
                'hora' => $hora,
                'dispositivo' => $empleado->idDispositivo ?? $datos['dispositivo'] ?? 'ZKTECO',
                'tipo' => $tipoEmpresa,
                'estatus' => 0,
                'lat' => $empleado->lat,
                'lng' => $empleado->lng,
                'url_gmap' => $urlGmap,
            ]);

            // 6) Insertar/Actualizar reloj_detalle aplicando la lógica REAL
            if (!$detalle) {
                // Insertar entrada
                $db->table('reloj_detalle')->insert([
                    'id_encabezado' => $codEncabezado,
                    'ficha' => $ficha,
                    'fecha' => $fecha,
                    'entrada' => $hora,
                    'salmuerzo' => '00:00',
                    'ealmuerzo' => '00:00',
                    'salida' => '00:00',
                    'ordinaria' => '00:00',
                    'extra' => '00:00',
                    'extraext' => '00:00',
                    'extranoc' => '00:00',
                    'extraextnoc' => '00:00',
                    'domingo' => '00:00',
                    'tardanza' => '00:00',
                    'nacional' => '00:00',
                    'extranac' => '00:00',
                    'extranocnac' => '00:00',
                    'descextra1' => '00:00',
                    'mixtodiurna' => '00:00',
                    'mixtonoc' => '00:00',
                    'mixtoextdiurna' => '00:00',
                    'mixtoextnoc' => '00:00',
                    'dialibre' => '00:00',
                    'emergencia' => '00:00',
                    'descansoincompleto' => '00:00',
                    'marcacion_disp_id' => $empleado->idDispositivo ?? null,
                    'ent_emer' => '00:00',
                    'sal_emer' => '00:00',
                    'salida_diasiguiente' => '',
                    'observacion' => '',
                    'hora_inicio' => '00:00',
                    'estatus' => 0,
                    'tarea' => '00:00',
                    'lluvia' => '00:00',
                    'paralizacion_lluvia' => '00:00',
                    'altura_menor' => '00:00',
                    'altura_mayor' => '00:00',
                    'profundidad' => '00:00',
                    'tunel' => '00:00',
                    'martillo' => '00:00',
                    'rastrilleo' => '00:00',
                    'otras' => '00:00',
                    'descanso_contrato' => '00:00',
                    'capataz' => 0,
                    'turno' => $turno,
                    'lat' => $empleado->lat,
                    'lng' => $empleado->lng,
                    'horas_reales' => '00:00',
                    'horas_teoricas' => '00:00',
                    'mixtoextnocnac' => '00:00',
                    'extra_apr' => '00:00',
                    'extraext_apr' => '00:00',
                    'extranoc_apr' => '00:00',
                    'extraextnoc_apr' => '00:00',
                    'extramixdiurna_apr' => '00:00',
                    'extraextmixdiurna_apr' => '00:00',
                    'extramixnoc_apr' => '00:00',
                    'extraextmixnoc_apr' => '00:00',
                    'sobretiempo_real' => '00:00',
                    'sobretiempo_aprobado' => '00:00',
                    'id_incidencia' => 0,
                    'cantidad_incidencia' => '00:00',
                    'agregado' => 0,
                    'mixtoextnocnac_apr' => '00:00',
                    'horas_acumuladas' => '00:00',
                    'turno_libre_id' => 0,
                ]);

                // Bitácora
                $db->table('ca_reloj_registros')->insert([
                    'ficha' => $ficha,
                    'fecha_registro' => $fecha,
                    'fecha' => $fecha,
                    'hora' => $hora,
                    'tipo_registro' => 'act_marc',
                    'turno_id' => $turno,
                    'id_proyecto' => $empleado->proyecto,
                    'latitud' => $empleado->lat,
                    'longitud' => $empleado->lng,
                    'fecha_creacion' => now(),
                ]);
            } else {
                // Actualizar el campo correspondiente
                $campoActualizar = null;
                if ($tipoEmpresa === '0') {
                    $campoActualizar = empty($detalle->entrada) || $detalle->entrada === '00:00:00' ? 'entrada' : 'salida';
                } else {
                    if (empty($detalle->entrada) || $detalle->entrada === '00:00:00') {
                        $campoActualizar = 'entrada';
                    } elseif (empty($detalle->salmuerzo) || $detalle->salmuerzo === '00:00:00') {
                        $campoActualizar = 'salmuerzo';
                    } elseif (empty($detalle->ealmuerzo) || $detalle->ealmuerzo === '00:00:00') {
                        $campoActualizar = 'ealmuerzo';
                    } else {
                        $campoActualizar = 'salida';
                    }
                }

                $db->table('reloj_detalle')
                    ->where('id', $detalle->id)
                    ->update([$campoActualizar => $hora]);

                // Bitácora
                $db->table('ca_reloj_registros')->insert([
                    'ficha' => $ficha,
                    'fecha_registro' => $fecha,
                    'fecha' => $fecha,
                    'hora' => $hora,
                    'tipo_registro' => 'act_marc',
                    'turno_id' => $turno,
                    'id_proyecto' => $empleado->proyecto,
                    'latitud' => $empleado->lat,
                    'longitud' => $empleado->lng,
                    'fecha_creacion' => now(),
                ]);
            }

            $db->commit();

            Log::info("Marcación registrada para {$empresa['nombre']} | ficha={$ficha} | fecha={$fecha} | hora={$hora}");
            return ['success' => true];
        } catch (\Exception $e) {
            try {
                DatabaseSwitchService::getConexionEmpresa()->rollBack();
            } catch (\Throwable $t) {
                // ignorar si no hay transacción abierta
            }
            Log::error('Error procesando marcación: ' . $e->getMessage());
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
