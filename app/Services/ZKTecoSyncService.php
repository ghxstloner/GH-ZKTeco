<?php
// app/Services/ZKTecoSyncService.php

namespace App\Services;

use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo;
use App\Models\ZKTeco\ProFaceX\ProFxUserInfo;
use App\Services\ZKTeco\ProFaceX\Manager\ManagerFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ZKTecoSyncService
{
    /**
     * Envía un tripulante específico a todos los dispositivos ZKTeco activos.
     *
     * @param object $tripulante El tripulante a enviar
     * @return array Resultado de la sincronización
     */
    public function enviarTripulante($tripulante): array
    {
        try {
            // Obtener dispositivos activos
            $dispositivosActivos = ProFxDeviceInfo::activos()->get();

            if ($dispositivosActivos->isEmpty()) {
                return [
                    'success' => true,
                    'warning' => 'No hay dispositivos activos disponibles',
                    'id_tripulante_procesado' => $tripulante->id_tripulante ?? $tripulante->crew_id
                ];
            }

            // Obtener la imagen del tripulante si existe
            $fotoData = $this->obtenerImagenTripulante($tripulante);

            $resultados = [];

            foreach ($dispositivosActivos as $dispositivo) {
                try {
                    $resultado = $this->enviarTripulanteADispositivo($tripulante, $dispositivo, $fotoData);
                    $resultados[] = $resultado;
                } catch (\Exception $e) {
                    Log::error("Error enviando tripulante {$tripulante->id_tripulante} a dispositivo {$dispositivo->DEVICE_SN}: " . $e->getMessage());

                    $resultados[] = [
                        'device_id' => $dispositivo->DEVICE_ID,
                        'device_sn' => $dispositivo->DEVICE_SN,
                        'resultado' => 'Error: ' . $e->getMessage()
                    ];
                }
            }

            return [
                'success' => true,
                'message' => 'Tripulante procesado en dispositivos',
                'id_tripulante_procesado' => $tripulante->id_tripulante ?? $tripulante->crew_id,
                'resultados_dispositivos' => $resultados
            ];

        } catch (\Exception $e) {
            Log::error('Error general enviando tripulante a ZKTeco: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => 'Error al procesar tripulante para ZKTeco',
                'details' => $e->getMessage()
            ];
        }
    }

    /**
     * Envía un tripulante a un dispositivo específico.
     *
     * @param object $tripulante
     * @param object $dispositivo
     * @param array|null $fotoData
     * @return array
     */
    private function enviarTripulanteADispositivo($tripulante, $dispositivo, $fotoData = null): array
    {
        // Obtener el DEV_FUNS actual del dispositivo
        $devFuns = $dispositivo->DEV_FUNS;

        // Buscar o crear el usuario en el dispositivo
        $userInfo = ProFxUserInfo::firstOrNew([
            'USER_PIN' => $tripulante->id_tripulante ?? $tripulante->crew_id,
            'DEVICE_SN' => $dispositivo->DEVICE_SN
        ]);

        // Rellenar la información del usuario
        $nombreCompleto = ($tripulante->nombres ?? '') . ' ' . ($tripulante->apellidos ?? '');
        $userInfo->NAME = trim($nombreCompleto);
        $userInfo->MAIN_CARD = $tripulante->id_tripulante ?? $tripulante->crew_id;

        // Agregar foto si existe
        if ($fotoData) {
            $userInfo->PHOTO_ID_NAME = $fotoData['nombre'];
            $userInfo->PHOTO_ID_SIZE = $fotoData['size'];
            $userInfo->PHOTO_ID_CONTENT = $fotoData['base64'];
        }

        // Valores predeterminados
        $userInfo->PASSWORD = "";
        $userInfo->FACE_GROUP_ID = 0;
        $userInfo->ACC_GROUP_ID = 0;
        $userInfo->DEPT_ID = 0;
        $userInfo->IS_GROUP_TZ = 0;
        $userInfo->VERIFY_TYPE = 0;
        $userInfo->category = 0;
        $userInfo->PRIVILEGE = 0;

        $userInfo->save();

        // Crear comando para el dispositivo
        ManagerFactory::getCommandManager()->createUpdateUserInfosCommandByIds(
            $userInfo,
            $devFuns
        );

        // Ejecutar comandos inmediatamente
        $this->executeDeviceCommands($dispositivo->DEVICE_SN);

        return [
            'device_id' => $dispositivo->DEVICE_ID,
            'device_sn' => $dispositivo->DEVICE_SN,
            'resultado' => 'Comandos enviados correctamente'
        ];
    }

    /**
     * Obtiene la imagen de un tripulante desde el FTP.
     *
     * @param object $tripulante
     * @return array|null
     */
    private function obtenerImagenTripulante($tripulante): ?array
    {
        $tripulanteId = $tripulante->id_tripulante ?? $tripulante->crew_id;
        Log::info("======================================================");
        Log::info("Iniciando obtención de imagen para tripulante ID: {$tripulanteId}");

        // LOG 1: Verificar datos iniciales del tripulante
        if (empty($tripulante->imagen) || empty($tripulante->crew_id)) {
            Log::warning("Tripulante ID: {$tripulanteId} tiene datos incompletos. Imagen: '{$tripulante->imagen}', IATA: '{$tripulante->iata_aerolinea}', CrewID: '{$tripulante->crew_id}'.");
            return null;
        }

        try {
            // LOG 2: Esta es la ruta que se está construyendo. Aquí sabrás de dónde intenta sacar la foto.
            $rutaImagen = $tripulante->crew_id . '/' . $tripulante->imagen;
            Log::info("Tripulante ID: {$tripulanteId}. Ruta de imagen construida: '{$rutaImagen}'");

            // Obtener el disco de imágenes configurado en filesystems.php (crew_images)
            $disk = Storage::disk('crew_images');

            // LOG 3: Verificar si la imagen existe en el FTP
            if (!$disk->exists($rutaImagen)) {
                Log::warning("Tripulante ID: {$tripulanteId}. La imagen NO fue encontrada en el FTP en la ruta: {$rutaImagen}");
                return null;
            }

            Log::info("Tripulante ID: {$tripulanteId}. Imagen SÍ encontrada. Descargando contenido...");
            $fotoContent = $disk->get($rutaImagen);

            if (empty($fotoContent)) {
                throw new \Exception("Contenido de imagen vacío después de la descarga.");
            }

            Log::info("Tripulante ID: {$tripulanteId}. Contenido descargado, tamaño: " . strlen($fotoContent) . " bytes. Comprimiendo...");

            // ... (el resto de la función para comprimir y retornar la imagen no cambia)
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($fotoContent);

            if (strpos($mimeType, 'image/') !== 0) {
                throw new \Exception("El archivo no es una imagen válida, tipo MIME detectado: {$mimeType}");
            }

            $compressedImage = $this->compressImage($fotoContent);

            Log::info("Tripulante ID: {$tripulanteId}. Imagen procesada y comprimida exitosamente.");
            Log::info("======================================================");

            return [
                'base64' => base64_encode($compressedImage),
                'size' => strlen($compressedImage),
                'nombre' => ($tripulante->id_tripulante ?? $tripulante->crew_id) . ".jpg"
            ];

        } catch (\Exception $e) {
            Log::error("Tripulante ID: {$tripulanteId}. EXCEPCIÓN al obtener imagen: " . $e->getMessage());
            Log::info("======================================================");
            return null;
        }
    }

    /**
     * Procesa múltiples tripulantes y los envía a dispositivos específicos.
     *
     * @param array $deviceIds IDs de dispositivos destino
     * @return array
     */
    public function sincronizarTripulantesADispositivos(array $deviceIds): array
    {
        try {
            // Validar que existan los dispositivos
            $dispositivos = ProFxDeviceInfo::whereIn('DEVICE_ID', $deviceIds)->get();

            if ($dispositivos->isEmpty()) {
                return [
                    'success' => false,
                    'error' => 'No se encontraron dispositivos válidos para sincronizar.'
                ];
            }

            // Obtener todos los tripulantes que tienen imagen
            $tripulantes = \DB::table('tripulantes')
                ->whereNotNull('imagen')
                ->whereNotNull('iata_aerolinea')
                ->whereNotNull('crew_id')
                ->select('id_tripulante', 'nombres', 'apellidos', 'imagen', 'iata_aerolinea', 'crew_id')
                ->get();

            if ($tripulantes->isEmpty()) {
                return [
                    'success' => true,
                    'message' => 'No hay tripulantes con imágenes para sincronizar.'
                ];
            }

            $resultadosSincronizacion = [];

            // Para cada dispositivo
            foreach ($dispositivos as $dispositivo) {
                $dispositivoResult = [
                    'device_id' => $dispositivo->DEVICE_ID,
                    'device_sn' => $dispositivo->DEVICE_SN,
                    'usuarios_sincronizados' => 0,
                    'usuarios_fallidos' => 0,
                    'detalles' => []
                ];

                // Sincronizar cada tripulante con este dispositivo
                foreach ($tripulantes as $tripulante) {
                    try {
                        $fotoData = $this->obtenerImagenTripulante($tripulante);

                        if ($fotoData) {
                            $this->enviarTripulanteADispositivo($tripulante, $dispositivo, $fotoData);
                            $dispositivoResult['usuarios_sincronizados']++;
                        } else {
                            throw new \Exception("No se pudo obtener la imagen");
                        }

                    } catch (\Exception $e) {
                        $dispositivoResult['usuarios_fallidos']++;
                        $dispositivoResult['detalles'][] = [
                            'tripulante_id' => $tripulante->id_tripulante,
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                $resultadosSincronizacion[] = $dispositivoResult;
            }

            return [
                'success' => true,
                'message' => 'Proceso de sincronización completado.',
                'results' => $resultadosSincronizacion
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error durante la sincronización.',
                'details' => $e->getMessage()
            ];
        }
    }

    /**
     * Ejecutar los comandos pendientes en el dispositivo.
     *
     * @param string $deviceSn
     */
    private function executeDeviceCommands(string $deviceSn): void
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
     * Comprime una imagen antes de convertirla a base64.
     *
     * @param string $imageData
     * @param int $quality
     * @return string
     */
    private function compressImage($imageData, $quality = 75): string
    {
        $image = imagecreatefromstring($imageData);

        if ($image === false) {
            return $imageData;
        }

        // Determinar tamaño original y ajustar calidad
        $originalSize = strlen($imageData);
        $targetQuality = 75;

        if ($originalSize > 500000) { // >500KB
            $targetQuality = 30;
        } elseif ($originalSize > 200000) { // >200KB
            $targetQuality = 40;
        } elseif ($originalSize > 100000) { // >100KB
            $targetQuality = 50;
        }

        // Redimensionar si es muy grande
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > 800 || $height > 800) {
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
}
