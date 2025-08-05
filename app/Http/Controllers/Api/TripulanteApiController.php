<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Planificacion;
use App\Models\Marcacion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class TripulanteApiController extends Controller
{
    /**
     * Obtener planificaciones del tripulante autenticado
     */
    public function getPlanificaciones(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            if (!$tripulante) {
                \Log::error('CRÍTICO: No se pudo autenticar al usuario');
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido o expirado'
                ], 401);
            }


            if (!$tripulante->isApproved() || !$tripulante->activo) {
                \Log::warning('Usuario no autorizado - no aprobado o inactivo');
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');

            // Query con datos reales de tu tabla
            $query = Planificacion::with(['aerolinea', 'posicionModel'])
                ->where('crew_id', $tripulante->crew_id)
                ->orderBy('fecha_vuelo', 'desc')
                ->orderBy('hora_vuelo', 'desc');

            // Filtro de búsqueda solo en campos que existen
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('numero_vuelo', 'like', "%{$search}%")
                      ->orWhere('crew_id', 'like', "%{$search}%")
                      ->orWhere('iata_aerolinea', 'like', "%{$search}%");
                });
            }

            $planificaciones = $query->paginate($perPage, ['*'], 'page', $page);


            // Mapear SOLO los datos que realmente existen
            $transformedData = $planificaciones->getCollection()->map(function ($planificacion) {
                return [
                    'id_planificacion' => $planificacion->id,
                    'crew_id' => $planificacion->crew_id,
                    'fecha_vuelo' => $planificacion->fecha_vuelo ? $planificacion->fecha_vuelo->format('Y-m-d') : null,
                    'numero_vuelo' => $planificacion->numero_vuelo,
                    'hora_salida' => $planificacion->hora_vuelo, // Tu campo real
                    'estado' => $this->mapearEstatus($planificacion->estatus),
                    'iata_aerolinea' => $planificacion->iata_aerolinea,
                    'posicion' => $planificacion->posicionModel ? $planificacion->posicionModel->codigo_posicion : 'N/A',

                    // Campos que NO existen en tu tabla - devolver null
                    'origen' => null,
                    'destino' => null,
                    'hora_llegada' => null,
                    'aeronave' => null,
                    'observaciones' => null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Planificaciones obtenidas exitosamente',
                'data' => $transformedData,
                'pagination' => [
                    'current_page' => $planificaciones->currentPage(),
                    'total' => $planificaciones->total(),
                    'per_page' => $planificaciones->perPage(),
                    'last_page' => $planificaciones->lastPage(),
                    'from' => $planificaciones->firstItem(),
                    'to' => $planificaciones->lastItem(),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener planificaciones: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener planificaciones',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener información de marcación para una planificación procesada
     */
    public function getMarcacionInfo(Request $request, int $planificacionId): JsonResponse
    {
        try {
            $tripulante = $request->user();

            if (!$tripulante || !$tripulante->isApproved() || !$tripulante->activo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            // Verificar que la planificación pertenezca al tripulante
            $planificacion = Planificacion::where('id', $planificacionId)
                ->where('crew_id', $tripulante->crew_id)
                ->first();

            if (!$planificacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Planificación no encontrada'
                ], 404);
            }

            // Verificar que la planificación esté procesada
            if ($planificacion->estatus !== 'R') {
                return response()->json([
                    'success' => false,
                    'message' => 'La planificación aún no está procesada'
                ], 400);
            }

            // Buscar la marcación correspondiente - consulta simplificada
            $marcacion = Marcacion::where('id_planificacion', $planificacionId)
                ->where('crew_id', $tripulante->crew_id)
                ->first();

            \Log::info('Buscando marcación para planificación: ' . $planificacionId . ' y crew_id: ' . $tripulante->crew_id);

            if (!$marcacion) {
                \Log::warning('No se encontró marcación para planificación ' . $planificacionId);

                // También verificar si existe alguna marcación para esta planificación
                $anyMarcacion = Marcacion::where('id_planificacion', $planificacionId)->first();
                if ($anyMarcacion) {
                    \Log::info('Existe marcación para planificación pero con diferente crew_id: ' . $anyMarcacion->crew_id);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró información de marcación'
                ], 404);
            }

            \Log::info('Marcación encontrada: ', $marcacion->toArray());

            // Buscar información del punto de control y aeropuerto relacionado
            $puntoControl = [
                'id' => null,
                'descripcion' => 'N/A',
                'aeropuerto' => 'N/A',
            ];

            $lugarMarcacion = [
                'id' => null,
                'nombre' => 'N/A',
                'codigo' => 'N/A',
            ];

            if ($marcacion->punto_control) {
                // Obtener punto de control con su aeropuerto relacionado
                $punto = \DB::table('puntos_control')
                    ->leftJoin('aeropuertos', 'puntos_control.id_aeropuerto', '=', 'aeropuertos.id_aeropuerto')
                    ->where('puntos_control.id_punto', $marcacion->punto_control)
                    ->select(
                        'puntos_control.id_punto',
                        'puntos_control.descripcion_punto',
                        'puntos_control.id_aeropuerto',
                        'aeropuertos.descripcion_aeropuerto',
                        'aeropuertos.codigo_iata'
                    )
                    ->first();

                if ($punto) {
                    $puntoControl = [
                        'id' => $punto->id_punto,
                        'descripcion' => $punto->descripcion_punto ?? 'N/A',
                        'aeropuerto' => $punto->descripcion_aeropuerto ?? 'N/A',
                    ];

                    // El lugar de marcación es el aeropuerto del punto de control
                    $lugarMarcacion = [
                        'id' => $punto->id_aeropuerto,
                        'nombre' => $punto->descripcion_aeropuerto ?? 'N/A',
                        'codigo' => $punto->codigo_iata ?? 'N/A',
                    ];
                }
            }

            // Información básica del dispositivo (si existe)
            $dispositivoInfo = null;

            return response()->json([
                'success' => true,
                'message' => 'Información de marcación obtenida exitosamente',
                'data' => [
                    'id_marcacion' => $marcacion->id_marcacion,
                    'fecha_marcacion' => $marcacion->fecha_marcacion?->format('Y-m-d'),
                    'hora_marcacion' => $marcacion->hora_marcacion,
                    'lugar_marcacion' => $lugarMarcacion,
                    'punto_control' => $puntoControl,
                    'dispositivo' => $dispositivoInfo,
                    'procesado' => $marcacion->procesado === '1',
                    'tipo_marcacion' => $marcacion->tipo_marcacion,
                    'usuario_sistema' => $marcacion->usuario,
                    'planificacion' => [
                        'id' => $planificacion->id,
                        'numero_vuelo' => $planificacion->numero_vuelo,
                        'fecha_vuelo' => $planificacion->fecha_vuelo?->format('Y-m-d'),
                        'hora_vuelo' => $planificacion->hora_vuelo,
                        'iata_aerolinea' => $planificacion->iata_aerolinea,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener información de marcación: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información de marcación',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Mapear estatus real: P = Pendiente, R = Procesada
     */
    private function mapearEstatus($estatus)
    {
        switch ($estatus) {
            case 'P':
                return 'Pendiente';
            case 'R':
                return 'Procesada';
            default:
                return 'Desconocido';
        }
    }

    /**
     * Obtener una planificación específica
     */
    public function getPlanificacion(Request $request, int $id): JsonResponse
    {
        try {
            $tripulante = $request->user();

            // VALIDACIÓN ADICIONAL
            if (!$tripulante || !$tripulante->isApproved() || !$tripulante->activo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            $planificacion = Planificacion::with(['aerolinea', 'posicionModel'])
                ->where('id', $id)
                ->where('crew_id', $tripulante->crew_id)
                ->first();

            if (!$planificacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Planificación no encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Planificación obtenida exitosamente',
                'data' => [
                    'id_planificacion' => $planificacion->id,
                    'crew_id' => $planificacion->crew_id,
                    'fecha_vuelo' => $planificacion->fecha_vuelo ? $planificacion->fecha_vuelo->format('Y-m-d') : null,
                    'numero_vuelo' => $planificacion->numero_vuelo,
                    'hora_salida' => $planificacion->hora_vuelo,
                    'estado' => $this->mapearEstatus($planificacion->estatus),
                    'iata_aerolinea' => $planificacion->iata_aerolinea,
                    'posicion' => $planificacion->posicionModel ? $planificacion->posicionModel->codigo_posicion : 'N/A',

                    // Campos que NO existen
                    'origen' => null,
                    'destino' => null,
                    'hora_llegada' => null,
                    'aeronave' => null,
                    'observaciones' => null,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener planificación: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener planificación',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener perfil del tripulante
     */
    public function getProfile(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            // VALIDACIÓN ADICIONAL
            if (!$tripulante || !$tripulante->isApproved() || !$tripulante->activo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            $tripulante->load('posicionModel');

            return response()->json([
                'success' => true,
                'message' => 'Perfil obtenido exitosamente',
                'data' => [
                    'id_solicitud' => $tripulante->id_solicitud,
                    'crew_id' => $tripulante->crew_id,
                    'nombres' => $tripulante->nombres,
                    'apellidos' => $tripulante->apellidos,
                    'nombres_apellidos' => $tripulante->nombres_apellidos,
                    'pasaporte' => $tripulante->pasaporte,
                    'identidad' => $tripulante->identidad,
                    'iata_aerolinea' => $tripulante->iata_aerolinea,
                    'posicion' => [
                        'id_posicion' => $tripulante->posicionModel->id_posicion,
                        'codigo_posicion' => $tripulante->posicionModel->codigo_posicion,
                        'descripcion' => $tripulante->posicionModel->descripcion,
                    ],
                    'imagen_url' => $tripulante->imagen_url,
                    'activo' => $tripulante->activo,
                    'estado' => $tripulante->estado,
                    'fecha_solicitud' => $tripulante->fecha_solicitud->format('Y-m-d H:i:s'),
                    'fecha_aprobacion' => $tripulante->fecha_aprobacion?->format('Y-m-d H:i:s'),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener perfil: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener perfil',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Actualizar perfil del tripulante
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            // VALIDACIÓN ADICIONAL
            if (!$tripulante || !$tripulante->isApproved() || !$tripulante->activo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'nombres' => 'sometimes|required|string|max:50',
                'apellidos' => 'sometimes|required|string|max:50',
                'pasaporte' => 'sometimes|required|string|max:20|unique:tripulantes_solicitudes,pasaporte,' . $tripulante->id_solicitud . ',id_solicitud',
                'identidad' => 'nullable|string|max:20',
                'image' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Procesar imagen si existe
            if ($request->hasFile('image')) {
                try {
                    $archivo = $request->file('image');

                    if (!$archivo->isValid()) {
                        throw new \Exception('Archivo de imagen inválido');
                    }

                    $extension = $archivo->getClientOriginalExtension();
                    $nombreArchivo = 'foto.' . $extension;

                    // ✅ ESTRUCTURA UNIFICADA: iata_aerolinea/crew_id
                    $directorio = $tripulante->iata_aerolinea . '/' . $tripulante->crew_id;
                    $rutaCompleta = $directorio . '/' . $nombreArchivo;

                    $disk = Storage::disk('crew_images');
                    $disk->makeDirectory($directorio);

                    $contenidoArchivo = file_get_contents($archivo->getPathname());
                    $guardado = $disk->put($rutaCompleta, $contenidoArchivo);

                    if (!$guardado) {
                        throw new \Exception('Error al guardar imagen');
                    }

                    $tripulante->imagen = $nombreArchivo;

                } catch (\Exception $e) {
                    \Log::error('Error al procesar imagen: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Error al procesar la imagen: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Actualizar campos
            if ($request->has('nombres')) {
                $tripulante->nombres = $request->nombres;
            }
            if ($request->has('apellidos')) {
                $tripulante->apellidos = $request->apellidos;
            }
            if ($request->has('pasaporte')) {
                $oldPassport = $tripulante->pasaporte;
                $tripulante->pasaporte = $request->pasaporte;

                // También actualizar en la tabla tripulantes usando iata_aerolinea + crew_id
                \DB::table('tripulantes')
                    ->where('crew_id', $tripulante->crew_id)
                    ->where('iata_aerolinea', $tripulante->iata_aerolinea)
                    ->update(['pasaporte' => $request->pasaporte]);
            }
            if ($request->has('identidad')) {
                $tripulante->identidad = $request->identidad;
            }

            $tripulante->save();
            $tripulante->load('posicionModel');

            return response()->json([
                'success' => true,
                'message' => 'Perfil actualizado exitosamente',
                'data' => [
                    'id_solicitud' => $tripulante->id_solicitud,
                    'crew_id' => $tripulante->crew_id,
                    'nombres' => $tripulante->nombres,
                    'apellidos' => $tripulante->apellidos,
                    'nombres_apellidos' => $tripulante->nombres_apellidos,
                    'pasaporte' => $tripulante->pasaporte,
                    'identidad' => $tripulante->identidad,
                    'iata_aerolinea' => $tripulante->iata_aerolinea,
                    'posicion' => [
                        'id_posicion' => $tripulante->posicionModel->id_posicion,
                        'codigo_posicion' => $tripulante->posicionModel->codigo_posicion,
                        'descripcion' => $tripulante->posicionModel->descripcion,
                    ],
                    'imagen_url' => $tripulante->imagen_url,
                    'activo' => $tripulante->activo,
                    'estado' => $tripulante->estado,
                    'fecha_solicitud' => $tripulante->fecha_solicitud->format('Y-m-d H:i:s'),
                    'fecha_aprobacion' => $tripulante->fecha_aprobacion?->format('Y-m-d H:i:s'),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al actualizar perfil: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar perfil',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Cambiar contraseña del tripulante
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            // VALIDACIÓN ADICIONAL
            if (!$tripulante || !$tripulante->isApproved() || !$tripulante->activo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            if (!Hash::check($request->current_password, $tripulante->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La contraseña actual es incorrecta'
                ], 400);
            }

            $tripulante->password = Hash::make($request->new_password);
            $tripulante->save();

            $tripulante->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente. Por favor inicia sesión nuevamente.',
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al cambiar contraseña: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar contraseña',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    // ===== MÉTODOS PARA ADMINISTRACIÓN (CRUD) =====

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    public function show(Request $request, $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    public function update(Request $request, $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }
}
