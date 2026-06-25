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

            // --- Consultar salida_ds de la tabla nomturnos ---
            $salidaDiaSiguiente = 'NO';
            if (!empty($turno)) {
                $salidaDs = $db->table('nomturnos')
                    ->where('turno_id', $turno)
                    ->value('salida_ds');

                if ($salidaDs == 1) {
                    $salidaDiaSiguiente = 'SI';
                }
            }
            // -------------------------------------------------

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
                    'usuario_despreaprobacion' => '',
                    'fecha_despreaprobacion' => '0000-00-00 00:00:00',
                    'usuario_desaprobacion' => '',
                    'fecha_desaprobacion' => '0000-00-00 00:00:00',
                ]);
            }

            // 4) Buscar detalle del día (reloj_detalle)
            $detalle = $db->table('reloj_detalle')
                ->select('id', 'entrada', 'salmuerzo', 'ealmuerzo', 'salida')
                ->where('ficha', $ficha)
                ->where('fecha', $fecha)
                ->first();

            // 5) Insertar en reloj_marcaciones (log simple)
            $urlGmap = '';
            if (!empty($empleado->lat) && !empty($empleado->lng)) {
                $urlGmap = 'https://www.google.es/maps/place/' . $empleado->lat . ',' . $empleado->lng;
            }

            $db->beginTransaction();

            $db->table('reloj_marcaciones')->insert([
                'id_empleado' => $empleado->personal_id,
                'ficha_empleado' => $ficha,
                'fecha' => $fecha,
                'hora' => $hora,
                'dispositivo' => $empleado->idDispositivo ?? $datos['dispositivo'] ?? 1,
                'tipo' => (int)$tipoEmpresa,
                'estatus' => 0,
                'lat' => (string)($empleado->lat ?? '0.0'),
                'lng' => (string)($empleado->lng ?? '0.0'),
                'url_gmap' => $urlGmap,
            ]);

            // 6) Contar marcaciones del día DESPUÉS de insertar la nueva marcación
            $marcacionesDelDia = $db->table('reloj_marcaciones')
                ->where('ficha_empleado', $ficha)
                ->where('fecha', $fecha)
                ->count();

            // 7) Insertar/Actualizar reloj_detalle aplicando la lógica REAL
            if (!$detalle) {
                // Insertar entrada
                $db->table('reloj_detalle')->insert([
                    'id_encabezado' => $codEncabezado,
                    'ficha' => $ficha,
                    'fecha' => $fecha,
                    'entrada' => $hora,
                    'salmuerzo' => '',
                    'ealmuerzo' => '',
                    'salida' => '',
                    'ordinaria' => '',
                    'extra' => '',
                    'extraext' => '',
                    'extranoc' => '',
                    'extraextnoc' => '',
                    'domingo' => '',
                    'tardanza' => '',
                    'nacional' => '',
                    'extranac' => '',
                    'extranocnac' => '',
                    'descextra1' => '',
                    'mixtodiurna' => '',
                    'mixtonoc' => '',
                    'mixtoextdiurna' => '',
                    'mixtoextnoc' => '',
                    'dialibre' => '',
                    'emergencia' => '',
                    'descansoincompleto' => '',
                    'marcacion_disp_id' => $empleado->idDispositivo ?? null,
                    'ent_emer' => '',
                    'sal_emer' => '',
                    'salida_diasiguiente' => $salidaDiaSiguiente,
                    'observacion' => null,
                    'hora_inicio' => '',
                    'estatus' => 0,
                    'tarea' => '',
                    'lluvia' => '',
                    'paralizacion_lluvia' => '',
                    'altura_menor' => '',
                    'altura_mayor' => '',
                    'profundidad' => '',
                    'tunel' => '',
                    'martillo' => '',
                    'rastrilleo' => '',
                    'otras' => '',
                    'descanso_contrato' => '',
                    'capataz' => 0,
                    'turno' => $turno,
                    'lat' => $empleado->lat ?? '0.0',
                    'lng' => $empleado->lng ?? '0.0',
                    'horas_reales' => '',
                    'horas_teoricas' => '',
                    'mixtoextnocnac' => '',
                    'extra_apr' => '',
                    'extraext_apr' => '',
                    'extranoc_apr' => '',
                    'extraextnoc_apr' => '',
                    'extramixdiurna_apr' => '',
                    'extraextmixdiurna_apr' => '',
                    'extramixnoc_apr' => '',
                    'extraextmixnoc_apr' => '',
                    'sobretiempo_real' => '',
                    'sobretiempo_aprobado' => '',
                    'id_incidencia' => 0,
                    'cantidad_incidencia' => '',
                    'agregado' => 0,
                    'mixtoextnocnac_apr' => '',
                    'horas_acumuladas' => '',
                    'turno_libre_id' => 0,
                ]);
            } else {
                // Actualizar el campo correspondiente basado en el número de marcaciones
                $campoActualizar = null;
                if ($tipoEmpresa === '0') {
                    // 2 marcaciones: entrada y salida
                    if ($marcacionesDelDia <= 2) {
                        // Dentro del ciclo normal (1-2 marcaciones)
                        $campoActualizar = ($marcacionesDelDia % 2 == 1) ? 'entrada' : 'salida';
                    } else {
                        // Marcaciones adicionales (3, 4, 5...): siempre salida
                        $campoActualizar = 'salida';
                    }
                } else {
                    // 4 marcaciones: entrada, salmuerzo, ealmuerzo, salida
                    if ($marcacionesDelDia <= 4) {
                        // Dentro del ciclo normal (1-4 marcaciones)
                        switch ($marcacionesDelDia % 4) {
                            case 1:
                                $campoActualizar = 'entrada';
                                break;
                            case 2:
                                $campoActualizar = 'salmuerzo';
                                break;
                            case 3:
                                $campoActualizar = 'ealmuerzo';
                                break;
                            case 0:
                                $campoActualizar = 'salida';
                                break;
                        }
                    } else {
                        // Marcaciones adicionales (5, 6, 7...): siempre salida
                        $campoActualizar = 'salida';
                    }
                }

                $db->table('reloj_detalle')
                    ->where('id', $detalle->id)
                    ->update([
                        $campoActualizar => $hora,
                        'salida_diasiguiente' => $salidaDiaSiguiente
                    ]);
            }

            $db->commit();

            return ['success' => true];
        } catch (\Exception $e) {
            try {
                DatabaseSwitchService::getConexionEmpresa()->rollBack();
            } catch (\Throwable $t) {
                // ignorar si no hay transacción abierta
            }
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

            // Buscar en 'nompersonal' para mantener consistencia
            $empleado = $conexion->table('nompersonal')
                ->where(function ($q) use ($pin) {
                    $q->where('ficha', $pin)->orWhere('cedula', $pin);
                })
                ->whereIn('estado', ['Activo', 'REGULAR'])
                ->first();

            if (!$empleado) {
                throw new \Exception("Empleado con PIN/Documento {$pin} no encontrado o inactivo");
            }

            // Obtener marcaciones (reloj_marcaciones es el log real)
            $marcaciones = $conexion->table('reloj_marcaciones')
                ->where('ficha_empleado', $empleado->ficha)
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->orderBy('fecha', 'asc')
                ->orderBy('hora', 'asc')
                ->get();

            return [
                'empleado' => [
                    'codigo' => $empleado->personal_id,
                    'nombre' => $empleado->apenom,
                    'pin' => $empleado->ficha
                ],
                'marcaciones' => $marcaciones
            ];

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Procesa registros pendientes de profacex_att_log
     */
    public static function procesarRegistrosPendientes()
    {
        try {
            if (!DatabaseSwitchService::hayEmpresaConfigurada()) {
                throw new \Exception('No hay empresa configurada');
            }

            $db = DatabaseSwitchService::getConexionEmpresa();

            // Obtener registros no procesados
            $registrosPendientes = $db->table('profacex_att_log')
                ->where('procesado', 0)
                ->orderBy('VERIFY_TIME', 'asc')
                ->get();

            $procesados = 0;
            $errores = 0;

            foreach ($registrosPendientes as $registro) {
                try {
                    // Procesar la marcación
                    self::procesarMarcacion([
                        'pin' => $registro->USER_PIN,
                        'fecha_hora' => $registro->VERIFY_TIME,
                    ]);

                    // Marcar como procesado
                    $db->table('profacex_att_log')
                        ->where('ATT_LOG_ID', $registro->ATT_LOG_ID)
                        ->update(['procesado' => 1]);

                    $procesados++;

                } catch (\Exception $e) {
                    $errores++;
                }
            }

            return [
                'success' => true,
                'procesados' => $procesados,
                'errores' => $errores,
                'total' => count($registrosPendientes)
            ];

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Drena `hikvision_event_log.PROCESSED=0` hacia `procesarMarcacion()` para
     * TODOS los tenants activos de `nomempresa`.
     *
     * Es el espejo de `procesarRegistrosPendientes()` (que drena
     * `profacex_att_log.procesado=0` para ZKTeco). El mapeo de PIN es directo:
     * el `employeeNo` Hikvision que guardamos al sincronizar proviene de
     * `n.ficha` en `DispositivosController`, y `procesarMarcacion` ya resuelve
     * ficha|cedula, por lo que son equivalentes.
     *
     * Resiliencia:
     *  - Una empresa que falle por completo NO aborta a las demás.
     *  - Un evento que falle se marca `PROCESSED=1` con `SYNC_ERROR` poblado,
     *    para no reintentar el mismo evento infinitamente (mismo criterio que
     *    `profacex_att_log` cuando falla un solo usuario).
     *  - El tenant-driven loop reutiliza `TenantMigrationRunner::foreachTenant`
     *    para garantizar el mismo set de empresas que las migraciones.
     *
     * Invocado por el scheduler `hikvision:process-events` cada minuto.
     *
     * @return array{empresas:int, procesados:int, errores:int, total:int}
     */
    public static function procesarEventosHikvisionPendientes(): array
    {
        $stats = ['empresas' => 0, 'procesados' => 0, 'errores' => 0, 'total' => 0];

        // foreachTenant conmuta la conexión 'empresa' por cada activa.
        \App\Support\TenantMigrationRunner::foreachTenant(function (string $conn, object $tenant) use (&$stats) {
            $stats['empresas']++;

            // foreachTenant configura DB::connection('empresa') via Config::set,
            // PERO no toca el estado interno de DatabaseSwitchService (el flag
            // estatico $conexionConfigurada). Sin esta llamada, getConexionEmpresa()
            // y procesarMarcacion() (que la usa) revientan con
            // "No se ha configurado conexión de empresa". Por eso re-invocamos
            // setBdEmpresa con el codigo del tenant que ya sabemos activo.
            try {
                DatabaseSwitchService::setBdEmpresa((string) $tenant->codigo);
                $db = DatabaseSwitchService::getConexionEmpresa();
            } catch (\Throwable $e) {
                Log::warning('[HikvisionDrain] empresa {cod} sin conexion empresa: {err}', [
                    'cod' => $tenant->codigo,
                    'err' => $e->getMessage(),
                ]);

                return;
            }

            // Solo drenar si la tabla existe en este tenant (migraciones
            // Hikvision aplicadas). Si no, skip silencioso.
            if (!\Illuminate\Support\Facades\Schema::connection('empresa')->hasTable('hikvision_event_log')) {
                return;
            }

            $pendientes = $db->table('hikvision_event_log')
                ->where('PROCESSED', 0)
                ->orderBy('EVENT_TIME', 'asc')
                ->limit(500)
                ->get();

            if ($pendientes->isEmpty()) {
                return;
            }

            $stats['total'] += $pendientes->count();

            foreach ($pendientes as $evento) {
                try {
                    // El PIN de Hikvision es el employeeNo que guardamos
                    // durante sync (= ficha del colaborador).
                    $pin = (string) ($evento->EMPLOYEE_NO_STRING !== null && $evento->EMPLOYEE_NO_STRING !== ''
                        ? $evento->EMPLOYEE_NO_STRING
                        : ($evento->EMPLOYEE_NO ?? ''));

                    if ($pin === '') {
                        throw new \Exception('Evento sin EMPLOYEE_NO');
                    }

                    self::procesarMarcacion([
                        'pin'        => $pin,
                        'fecha_hora' => $evento->EVENT_TIME,
                    ]);

                    $db->table('hikvision_event_log')
                        ->where('EVENT_ID', $evento->EVENT_ID)
                        ->update(['PROCESSED' => 1, 'updated_at' => now()]);

                    $stats['procesados']++;
                } catch (\Throwable $e) {
                    // Marcamos procesado para no reintentar eternamente; el
                    // detalle queda en SYNC_ERROR para diagnóstico.
                    $db->table('hikvision_event_log')
                        ->where('EVENT_ID', $evento->EVENT_ID)
                        ->update([
                            'PROCESSED'  => 1,
                            'SYNC_ERROR' => \Illuminate\Support\Str::limit($e->getMessage(), 480),
                            'updated_at' => now(),
                        ]);

                    $stats['errores']++;

                    Log::warning('[HikvisionDrain] evento {id} fallo: {err}', [
                        'id'  => $evento->EVENT_ID,
                        'err' => $e->getMessage(),
                    ]);
                }
            }
        });

        if ($stats['total'] > 0) {
            Log::info('[HikvisionDrain] drenado completado', $stats);
        }

        return $stats;
    }

    /**
     * Procesa TODOS los registros existentes en profacex_att_log (para migración inicial)
     * Este método debe ejecutarse UNA SOLA VEZ después de agregar la columna 'procesado'
     */
    public static function procesarRegistrosExistentesCompleto()
    {
        try {
            if (!DatabaseSwitchService::hayEmpresaConfigurada()) {
                throw new \Exception('No hay empresa configurada');
            }

            $db = DatabaseSwitchService::getConexionEmpresa();

            // Obtener TODOS los registros (sin filtrar por procesado)
            $todosLosRegistros = $db->table('profacex_att_log')
                ->orderBy('VERIFY_TIME', 'asc')
                ->get();

            $procesados = 0;
            $errores = 0;
            $yaExistian = 0;

            foreach ($todosLosRegistros as $registro) {
                try {
                    // Verificar si ya existe en reloj_detalle para este empleado y fecha
                    $ficha = $registro->USER_PIN;
                    $fecha = date('Y-m-d', strtotime($registro->VERIFY_TIME));

                    $existeEnDetalle = $db->table('reloj_detalle')
                        ->where('ficha', $ficha)
                        ->where('fecha', $fecha)
                        ->exists();

                    if ($existeEnDetalle) {
                        // Solo marcar como procesado, no volver a procesar
                        $db->table('profacex_att_log')
                            ->where('ATT_LOG_ID', $registro->ATT_LOG_ID)
                            ->update(['procesado' => 1]);
                        $yaExistian++;
                    } else {
                        // Procesar la marcación normalmente
                        self::procesarMarcacion([
                            'pin' => $registro->USER_PIN,
                            'fecha_hora' => $registro->VERIFY_TIME,
                        ]);

                        // Marcar como procesado
                        $db->table('profacex_att_log')
                            ->where('ATT_LOG_ID', $registro->ATT_LOG_ID)
                            ->update(['procesado' => 1]);

                        $procesados++;
                    }

                } catch (\Exception $e) {
                    $errores++;
                }
            }

            return [
                'success' => true,
                'procesados' => $procesados,
                'yaExistian' => $yaExistian,
                'errores' => $errores,
                'total' => count($todosLosRegistros)
            ];

        } catch (\Exception $e) {
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

            // Buscar en 'nompersonal' para mantener consistencia
            $empleado = $conexion->table('nompersonal')
                ->where(function ($q) use ($pin) {
                    $q->where('ficha', $pin)->orWhere('cedula', $pin);
                })
                ->whereIn('estado', ['Activo', 'REGULAR'])
                ->first();

            if (!$empleado) {
                throw new \Exception("Empleado con PIN/Documento {$pin} no encontrado o inactivo");
            }

            // Obtener la última marcación del día (reloj_marcaciones)
            $hoy = Carbon::now()->format('Y-m-d');
            $ultimaMarcacion = $conexion->table('reloj_marcaciones')
                ->where('ficha_empleado', $empleado->ficha)
                ->where('fecha', $hoy)
                ->orderBy('hora', 'desc')
                ->first();

            $estado = 'SIN_MARCAR';
            if ($ultimaMarcacion) {
                $estado = $ultimaMarcacion->tipo == 0 ? 'EN_TURNO' : 'FUERA_TURNO';
            }

            return [
                'empleado' => [
                    'codigo' => $empleado->personal_id,
                    'nombre' => $empleado->apenom,
                    'pin' => $empleado->ficha
                ],
                'estado' => $estado,
                'ultima_marcacion' => $ultimaMarcacion
            ];

        } catch (\Exception $e) {
            throw $e;
        }
    }
}
