<?php

namespace App\Services;

use App\Models\Marcacion;
use App\Models\Planificacion;
use App\Models\Tripulante;
use App\Models\ZKTeco\ProFaceX\ProFxAttLog;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MarcacionesServices
{
    /**
     * Registra marcaciones y las asocia con planificaciones según reglas específicas.
     * Implementa lógica de entrada/salida y logs detallados para depuración.
     *
     * @param array $proFxAttLogList Lista de objetos ProFxAttLog a procesar
     * @return void
     */
    public function registrarMarcaciones(array $proFxAttLogList)
    {
        Log::info("--- INICIO DEL PROCESO DE MARCACIONES ---");
        Log::info("Se van a procesar " . count($proFxAttLogList) . " registros de ZKTeco.");

        foreach ($proFxAttLogList as $index => $proFxAttLog) {
            $id_tripulante_from_pin = (int) $proFxAttLog->USER_PIN;
            Log::info("--- Procesando registro " . ($index + 1) . " para USER_PIN: {$id_tripulante_from_pin} ---");

            try {
                // 1. Extracción y preparación de datos iniciales
                $tiempoMarcacionCompleto = Carbon::parse($proFxAttLog->VERIFY_TIME);
                $fechaMarcacionActual = $tiempoMarcacionCompleto->format('Y-m-d');
                $horaMarcacionActual = $tiempoMarcacionCompleto->format('H:i:s');
                Log::info("Datos de marcación: Fecha='{$fechaMarcacionActual}', Hora='{$horaMarcacionActual}'");

                // 2. Obtención de datos del tripulante
                $tripulante = Tripulante::where('id_tripulante', $id_tripulante_from_pin)->first();

                if (!$tripulante) {
                    Log::warning("Tripulante con USER_PIN: {$id_tripulante_from_pin} NO ENCONTRADO en la base de datos. Saltando registro.");
                    continue;
                }

                if (!$tripulante->crew_id) {
                    Log::warning("Tripulante con USER_PIN: {$id_tripulante_from_pin} tiene datos incompletos (crew_id). Saltando registro.");
                    continue;
                }

                $crew_id_del_tripulante = $tripulante->crew_id;
                Log::info("Tripulante encontrado: ID={$tripulante->id_tripulante}, CrewID='{$crew_id_del_tripulante}'");

                // 3. Verificar marcaciones existentes para hoy
                $marcacionExistente = Marcacion::where('id_tripulante', $id_tripulante_from_pin)
                    ->whereDate('fecha_marcacion', $fechaMarcacionActual)
                    ->first();

                // 4. Determinar tipo de marcación (Entrada o Salida)
                $tipoMarcacion = 1; // Por defecto es Entrada
                $procesado = '0';   // Por defecto no procesado

                if ($marcacionExistente) {
                    Log::info("Se encontró una marcación existente para hoy (ID: {$marcacionExistente->id_marcacion}).");
                    if ($marcacionExistente->hora_entrada && !$marcacionExistente->hora_salida) {
                        $tipoMarcacion = 2; // Ya tiene entrada, esta es Salida
                        $procesado = '1';
                        Log::info("La marcación existente tiene ENTRADA pero no SALIDA. Esta nueva marcación será de tipo SALIDA.");
                    } else {
                        $tipoMarcacion = 2; // Ya tiene ambas, se considera actualización de Salida
                        $procesado = '1';
                        Log::info("La marcación existente ya tiene ENTRADA y SALIDA. Se actualizará la SALIDA.");
                    }
                } else {
                    Log::info("No se encontró marcación previa para hoy. Esta será una marcación de tipo ENTRADA.");
                }

                // 5. Buscar planificación asociada
                $id_planificacion_para_esta_marcacion = 0;
                $planificacionAsociada = null;

                if ($marcacionExistente && $marcacionExistente->id_planificacion > 0) {
                    $id_planificacion_para_esta_marcacion = $marcacionExistente->id_planificacion;
                    $planificacionAsociada = Planificacion::find($id_planificacion_para_esta_marcacion);
                    Log::info("Reutilizando id_planificacion de la marcación existente: {$id_planificacion_para_esta_marcacion}");
                } else {
                    Log::info("Buscando planificación pendiente para CrewID='{$crew_id_del_tripulante}' en fecha '{$fechaMarcacionActual}'");
                    $planificacionPendiente = Planificacion::where('crew_id', $crew_id_del_tripulante)
                        ->whereDate('fecha_vuelo', $fechaMarcacionActual)
                        ->where('estatus', 'P')
                        ->first();

                    if ($planificacionPendiente) {
                        $planificacionAsociada = $planificacionPendiente;
                        $id_planificacion_para_esta_marcacion = $planificacionAsociada->id;
                        Log::info("Planificación PENDIENTE encontrada (ID: {$planificacionAsociada->id}).");
                    } else {
                        Log::warning("No se encontró ninguna planificación PENDIENTE para CrewID='{$crew_id_del_tripulante}' en la fecha '{$fechaMarcacionActual}'.");
                    }
                }

                // 6. OBTENCIÓN DE LUGAR Y PUNTO DE CONTROL
                $lugarMarcacionDeterminado = null;
                $punto_control = null;

                // 6.1. Determinar Lugar de Marcación desde el Evento de la Planificación
                if ($planificacionAsociada && $planificacionAsociada->id_evento) {
                    Log::info("Buscando evento ID: {$planificacionAsociada->id_evento} para obtener lugar de marcación.");
                    $evento = DB::table('eventos')->where('id_evento', $planificacionAsociada->id_evento)->first();

                    // ✅ CORRECCIÓN: Se busca la columna 'id_lugar' que sí existe en la tabla 'eventos'.
                    if ($evento && isset($evento->id_lugar)) {
                        $lugarMarcacionDeterminado = $evento->id_lugar;
                        Log::info("Lugar de marcación asignado desde el evento: '{$lugarMarcacionDeterminado}'");
                    } else {
                        Log::warning("Evento ID: {$planificacionAsociada->id_evento} no se encontró o no tiene la columna 'id_lugar'.");
                    }
                } else {
                    Log::warning("No hay planificación o evento asociado. No se puede determinar el lugar de marcación.");
                }

                // 6.2. Determinar Punto de Control desde el Dispositivo
                $deviceSn = $proFxAttLog->DEVICE_SN;
                $deviceInfo = ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();

                if ($deviceInfo && $deviceInfo->DEVICE_ID) {
                    $dispositivoPunto = DB::table('dispositivos_puntos')->where('id_device', $deviceInfo->DEVICE_ID)->first();
                    if ($dispositivoPunto) {
                        // Se asigna el ID del dispositivo como el punto de control, según solicitado.
                        $punto_control = $dispositivoPunto->id_device;
                        Log::info("Punto de control asignado: '{$punto_control}' (ID del dispositivo verificado en 'dispositivos_puntos').");
                    } else {
                        Log::warning("El dispositivo con ID '{$deviceInfo->DEVICE_ID}' (SN: {$deviceSn}) no está registrado en 'dispositivos_puntos'.");
                    }
                } else {
                    Log::warning("No se encontró información del dispositivo para SN: '{$deviceSn}'. No se puede determinar el punto de control.");
                }

                // 7. Guardar o Actualizar Marcación
                $datosMarcacion = [
                    'id_planificacion' => $id_planificacion_para_esta_marcacion,
                    'id_evento' => $planificacionAsociada ? $planificacionAsociada->id_evento : null,
                    'hora_marcacion' => $horaMarcacionActual,
                    'lugar_marcacion' => $lugarMarcacionDeterminado,
                    'punto_control' => $punto_control,
                    'procesado' => $procesado,
                    'tipo_marcacion' => $tipoMarcacion,
                    'usuario' => 'zkteco_system'
                ];

                if ($marcacionExistente) {
                    // --- ACTUALIZAR ---
                    $datosMarcacion['hora_salida'] = $tipoMarcacion == 2 ? $horaMarcacionActual : $marcacionExistente->hora_salida;

                    Log::info("Intentando ACTUALIZAR marcacion ID: {$marcacionExistente->id_marcacion} con los siguientes datos:", $datosMarcacion);
                    $resultado = $marcacionExistente->update($datosMarcacion);

                    if ($resultado) {
                        Log::info("¡ÉXITO! Marcación actualizada correctamente.");
                    } else {
                        Log::error("¡FALLO! La actualización de la marcación ID: {$marcacionExistente->id_marcacion} no tuvo éxito.");
                    }
                } else {
                    // --- CREAR ---
                    $datosMarcacion['id_tripulante'] = $id_tripulante_from_pin;
                    $datosMarcacion['crew_id'] = $crew_id_del_tripulante;
                    $datosMarcacion['fecha_marcacion'] = $fechaMarcacionActual;
                    $datosMarcacion['hora_entrada'] = $horaMarcacionActual;
                    $datosMarcacion['hora_salida'] = null;

                    Log::info("Intentando CREAR nueva marcación con los siguientes datos:", $datosMarcacion);
                    $marcacionNueva = new Marcacion($datosMarcacion);
                    $resultado = $marcacionNueva->save();

                    if ($resultado) {
                        Log::info("¡ÉXITO! Nueva marcación creada con ID: {$marcacionNueva->id_marcacion}");
                    } else {
                        Log::error("¡FALLO! La creación de la nueva marcación no tuvo éxito.");
                    }
                }

                // 8. Actualizar estatus de planificación si es marcación de salida y hay planificación
                if ($tipoMarcacion == 2 && $planificacionAsociada && $planificacionAsociada->estatus == 'P') {
                    $planificacionAsociada->estatus = 'R'; // Realizado
                    $planificacionAsociada->save();
                    Log::info("Estatus de planificación {$planificacionAsociada->id} actualizado a 'R'.");
                }

            } catch (\Exception $e) {
                Log::error("--- ERROR GRAVE al procesar marcación para USER_PIN: {$id_tripulante_from_pin} ---", [
                    'mensaje' => $e->getMessage(),
                    'archivo' => $e->getFile(),
                    'linea' => $e->getLine(),
                    'stacktrace' => $e->getTraceAsString()
                ]);
                continue; // Saltar al siguiente registro en caso de error
            }
        }
        Log::info("--- FIN DEL PROCESO DE MARCACIONES ---");
    }
}
