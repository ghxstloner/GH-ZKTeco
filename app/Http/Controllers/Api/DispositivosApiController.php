<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo;
use App\Models\ZKTeco\ProFaceX\ProFxUserInfo;
use App\Models\ZKTeco\ProFaceX\ProFxAdvInfo;
use App\Models\ZKTeco\ProFaceX\ProFxAttLog;
use App\Models\ZKTeco\ProFaceX\ProFxAttPhoto;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceAttrs;
use App\Models\ZKTeco\ProFaceX\ProFxMeetInfo;
use App\Models\ZKTeco\ProFaceX\ProFxMessage;
use App\Models\ZKTeco\ProFaceX\ProFxPersBioTemplate;
use App\Services\ZKTeco\ProFaceX\Manager\ManagerFactory;
use App\Services\ZKTeco\ProFaceX\DevCmdUtil;
use App\Services\ZKTecoSyncService; // ✅ NUEVO
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class DispositivosApiController extends Controller
{
    /**
     * Recibe datos de una persona y los envía a los dispositivos ZKTeco.
     * ✅ REFACTORIZADO: Ahora usa ZKTecoSyncService para mayor consistencia
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validar los datos de entrada
        $validator = Validator::make($request->all(), [
            'id_tripulante' => 'required|string|max:255', // ID único para el dispositivo
            'nombres' => 'required|string|max:100',
            'apellidos' => 'required|string|max:100',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg|max:2048', // Foto como archivo, opcional
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            // Si hay foto como archivo, procesarla directamente (método original)
            if ($request->hasFile('foto')) {
                return $this->procesarImagenDirecta($request);
            }

            // Si no hay foto, crear objeto tripulante temporal y usar el servicio
            $tripulanteTemp = (object) [
                'id_tripulante' => $request->id_tripulante,
                'crew_id' => $request->id_tripulante,
                'nombres' => $request->nombres,
                'apellidos' => $request->apellidos,
                'imagen' => null, // No tiene imagen en FTP
                'iata_aerolinea' => null,
            ];

            $zktecoService = new ZKTecoSyncService();
            $resultado = $zktecoService->enviarTripulante($tripulanteTemp);

            return response()->json($resultado);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al procesar y enviar datos de persona',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Procesar imagen directa cuando viene como archivo en la request.
     * ✅ Mantiene la lógica original para cuando la imagen viene como archivo
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function procesarImagenDirecta(Request $request)
    {
        try {
            $fotoBase64 = null;
            $fotoSize = null;
            $fotoNombreParaDispositivo = null;

            $foto = $request->file('foto');
            $fotoContent = file_get_contents($foto->getRealPath());

            // Comprimir la imagen antes de convertirla a base64
            $compressedImage = $this->compressImage($fotoContent);

            $fotoBase64 = base64_encode($compressedImage);
            $fotoSize = strlen($compressedImage);
            $fotoNombreParaDispositivo = $request->id_tripulante . ".jpg";

            $nombreCompleto = $request->nombres . ' ' . $request->apellidos;

            // Obtener dispositivos activos
            $dispositivosActivos = ProFxDeviceInfo::activos()->get();

            if ($dispositivosActivos->isEmpty()) {
                return response()->json([
                    'warning' => 'No hay dispositivos activos disponibles',
                    'id_tripulante_procesado' => $request->id_tripulante
                ], 200);
            }

            $resultados = [];

            foreach ($dispositivosActivos as $dispositivo) {
                // Obtener el DEV_FUNS actual del dispositivo
                $devFuns = $dispositivo->DEV_FUNS;

                // Buscar o crear el usuario en el dispositivo
                $userInfo = ProFxUserInfo::firstOrNew([
                    'USER_PIN' => $request->id_tripulante,
                    'DEVICE_SN' => $dispositivo->DEVICE_SN
                ]);

                // Rellenar la información del usuario
                $userInfo->NAME = $nombreCompleto;
                $userInfo->MAIN_CARD = $request->id_tripulante;

                if ($fotoBase64 && $fotoSize) {
                    $userInfo->PHOTO_ID_NAME = $fotoNombreParaDispositivo;
                    $userInfo->PHOTO_ID_SIZE = $fotoSize;
                    $userInfo->PHOTO_ID_CONTENT = $fotoBase64;
                }

                // Valores predeterminados según el código original
                $userInfo->PASSWORD = "";
                $userInfo->FACE_GROUP_ID = 0;
                $userInfo->ACC_GROUP_ID = 0;
                $userInfo->DEPT_ID = 0;
                $userInfo->IS_GROUP_TZ = 0;
                $userInfo->VERIFY_TYPE = 0;
                $userInfo->category = 0;
                $userInfo->PRIVILEGE = 0;

                $userInfo->save();

                ManagerFactory::getCommandManager()->createUpdateUserInfosCommandByIds(
                    $userInfo,
                    $devFuns
                );

                // Ejecutar comandos para CADA dispositivo INMEDIATAMENTE
                $this->executeDeviceCommands($dispositivo->DEVICE_SN);

                $resultados[] = [
                    'device_id' => $dispositivo->DEVICE_ID,
                    'device_sn' => $dispositivo->DEVICE_SN,
                    'resultado' => 'Comandos enviados correctamente'
                ];
            }

            return response()->json([
                'message' => 'Datos de persona enviados a dispositivos exitosamente',
                'id_tripulante_procesado' => $request->id_tripulante,
                'resultados_dispositivos' => $resultados
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al procesar imagen directa',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sincronizar todos los tripulantes con los dispositivos seleccionados.
     * ✅ REFACTORIZADO: Ahora usa ZKTecoSyncService
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncDevices(Request $request)
    {
        try {
            // Validar los dispositivos seleccionados
            $validator = Validator::make($request->all(), [
                'device_ids' => 'required|array',
                'device_ids.*' => [
                    'required',
                    'integer',
                    function ($attribute, $value, $fail) {
                        if (!ProFxDeviceInfo::where('DEVICE_ID', $value)->exists()) {
                            $fail('El device_id no existe.');
                        }
                    }
                ],
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $deviceIds = $request->device_ids;

            if (empty($deviceIds)) {
                return response()->json([
                    'error' => 'No se seleccionaron dispositivos para sincronizar.'
                ], 400);
            }

            // Usar el servicio ZKTecoSyncService
            $zktecoService = new ZKTecoSyncService();
            $resultado = $zktecoService->sincronizarTripulantesADispositivos($deviceIds);

            return response()->json($resultado);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Ha ocurrido un error durante la sincronización.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ejecutar los comandos pendientes en el dispositivo por su número de serie.
     *
     * @param string $deviceSn
     */
    private function executeDeviceCommands(string $deviceSn)
    {
        try {
            $commandManager = ManagerFactory::getCommandManager();
            $commands = $commandManager->getDeviceCommandListToDevice($deviceSn);

            if ($commands->isEmpty()) {
                return;
            }

            foreach ($commands as $command) {
                $command->CMD_TRANS_TIMES = now();
                $commandManager->updateDeviceCommand([$command]);

                // $result = $this->simulateCommandExecution($command);

                // $command->CMD_RETURN = $result['status'];
                // $command->CMD_RETURN_INFO = $result['info'];
                $command->CMD_OVER_TIME = now();
                $commandManager->updateDeviceCommand([$command]);
            }

            ManagerFactory::getDeviceManager()->updateDeviceState($deviceSn, 'Online', now());
            ManagerFactory::getCommandManager()->createINFOCommand($deviceSn);
        } catch (\Exception $e) {
            Log::error("Error ejecutando comandos para dispositivo SN: {$deviceSn}. Error: {$e->getMessage()}");
        }
    }

    /**
     * Simular la ejecución de un comando en un dispositivo.
     *
     * @param object $command
     * @return array
     */
    private function simulateCommandExecution($command)
    {
        return [
            'status' => 'OK',
            'info' => "Simulación de ejecución para comando ID: {$command->DEV_CMD_ID}"
        ];
    }

    /**
     * Comprime una imagen antes de convertirla a base64.
     *
     * @param string $imageData Los datos binarios de la imagen
     * @param int $quality La calidad de compresión (0-100)
     * @return string Los datos binarios de la imagen comprimida
     */
    private function compressImage($imageData, $quality = 75)
    {
        // Ajustar a una calidad más baja para archivos grandes
        $image = imagecreatefromstring($imageData);

        if ($image === false) {
            return $imageData;
        }

        // Determinar tamaño original
        $originalSize = strlen($imageData);
        $targetQuality = 75; // Calidad predeterminada

        // Reducir calidad proporcionalmente para imágenes más grandes
        if ($originalSize > 500000) { // >500KB
            $targetQuality = 30;
        } elseif ($originalSize > 200000) { // >200KB
            $targetQuality = 40;
        } elseif ($originalSize > 100000) { // >100KB
            $targetQuality = 50;
        }

        // También podemos redimensionar la imagen si es muy grande
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > 800 || $height > 800) {
            // Redimensionar manteniendo la proporción
            if ($width > $height) {
                $newWidth = 800;
                $newHeight = ($height / $width) * 800;
            } else {
                $newHeight = 800;
                $newWidth = ($width / $height) * 800;
            }

            $tempImage = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($tempImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $tempImage;
        }

        ob_start();
        imagejpeg($image, null, $targetQuality);
        $compressedData = ob_get_contents();
        ob_end_clean();

        imagedestroy($image);

        return $compressedData;
    }

    /**
     * Limpiar los datos de los dispositivos seleccionados.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearDevices(Request $request)
    {
        try {
            // Validar los dispositivos seleccionados
            $validator = Validator::make($request->all(), [
                'device_ids' => 'required|array',
                'device_ids.*' => [
                    'required',
                    'integer',
                    function ($attribute, $value, $fail) {
                        if (!ProFxDeviceInfo::where('DEVICE_ID', $value)->exists()) {
                            $fail('El device_id no existe.');
                        }
                    }
                ],
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $deviceIds = $request->device_ids;
            $resultadosLimpieza = [];

            // Usar DEVICE_ID en lugar de ID
            $dispositivosInfo = ProFxDeviceInfo::whereIn('DEVICE_ID', $deviceIds)->get();

            if ($dispositivosInfo->isEmpty()) {
                return response()->json(['error' => 'No se encontraron dispositivos válidos para limpiar.'], 400);
            }

            foreach ($dispositivosInfo as $dispositivo) {
                $deviceSn = $dispositivo->DEVICE_SN;
                $tablasBorradas = 0;
                $tablasFallidas = 0;

                // Mapeo de modelos a nombres de tabla explícitos
                $modelosTablas = [
                    'ProFxAdvInfo' => null,
                    'ProFxAttLog' => null,
                    'ProFxAttPhoto' => null,
                    'ProFxDeviceAttrs' => null,
                    'ProFxMeetInfo' => 'proface_x_meet_info', // Forzar este nombre específico
                    'ProFxMessage' => null,
                    'ProFxUserInfo' => null,
                    'ProFxPersBioTemplate' => null,
                ];

                $errores = [];

                // Optimizacion: Ejecutar en transacción para mejorar rendimiento
                DB::beginTransaction();

                try {
                    // Procesar cada modelo con manejo de errores individual
                    foreach ($modelosTablas as $nombreModelo => $nombreTablaExplicito) {
                        try {
                            $nombreClaseCompleto = 'App\\Models\\ZKTeco\\ProFaceX\\' . $nombreModelo;

                            if (!class_exists($nombreClaseCompleto)) {
                                $errores[$nombreModelo] = "Clase {$nombreClaseCompleto} no existe";
                                $tablasFallidas++;
                                continue;
                            }

                            $instanciaModelo = new $nombreClaseCompleto();

                            // Usar nombre explícito si se proporcionó, o el del modelo
                            $nombreTabla = $nombreTablaExplicito ?: $instanciaModelo->getTable();

                            // Usar Query Builder para más control
                            $numFilasBorradas = DB::table($nombreTabla)
                                ->where('DEVICE_SN', '=', $deviceSn)
                                ->delete();

                            $tablasBorradas++;

                        } catch (\Exception $e) {
                            $errores[$nombreModelo] = $e->getMessage();
                            $tablasFallidas++;
                        }
                    }

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }

                // Crear comando para limpiar todos los datos en el dispositivo físico
                ManagerFactory::getCommandManager()->createClearAllDataCommand($deviceSn);

                // Ejecutar comandos para este dispositivo
                $this->executeDeviceCommands($deviceSn);

                $resultadosLimpieza[] = [
                    'device_id' => $dispositivo->DEVICE_ID,
                    'device_sn' => $deviceSn,
                    'status' => 'clear_commands_executed',
                    'tablas_borradas' => $tablasBorradas,
                    'tablas_con_error' => $tablasFallidas
                ];
            }

            return response()->json([
                'message' => 'Datos locales eliminados y comandos de limpieza ejecutados en los dispositivos.',
                'results' => $resultadosLimpieza
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Ha ocurrido un error al limpiar los dispositivos.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}