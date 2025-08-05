<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TripulanteSolicitud;
use App\Models\Tripulante;
use App\Models\Posicion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TripulanteController extends Controller
{
    /**
     * Listar solicitudes de tripulantes con filtros opcionales.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function solicitudes(Request $request): JsonResponse
    {
        try {
            // Validar parámetros de consulta
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:100',
                'search' => 'string|max:255',
                'estado' => 'string|in:Pendiente,Aprobado,Denegado',
                'posicion' => 'integer|exists:posiciones,id_posicion',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros de consulta inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Inicializar consulta
            $query = TripulanteSolicitud::with('posicionModel');

            // Aplicar filtros
            if ($request->filled('search')) {
                $query->buscarPorNombre($request->search);
            }

            if ($request->filled('estado')) {
                $query->where('estado', $request->estado);
            }

            if ($request->filled('posicion')) {
                $query->where('posicion', $request->posicion);
            }

            // Ordenar por fecha de solicitud descendente
            $query->orderBy('fecha_solicitud', 'desc');

            // Paginación
            $perPage = $request->get('per_page', 15);
            $solicitudes = $query->paginate($perPage);

            // Transformar datos
            $data = $solicitudes->getCollection()->map(function ($solicitud) {
                return [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'crew_id' => $solicitud->crew_id,
                    'nombres' => $solicitud->nombres,
                    'apellidos' => $solicitud->apellidos,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'pasaporte' => $solicitud->pasaporte,
                    'identidad' => $solicitud->identidad,
                    'iata_aerolinea' => $solicitud->iata_aerolinea,
                    'email' => $solicitud->email,
                    'posicion_info' => [
                        'id_posicion' => $solicitud->posicionModel->id_posicion,
                        'codigo_posicion' => $solicitud->posicionModel->codigo_posicion,
                        'descripcion' => $solicitud->posicionModel->descripcion,
                    ],
                    'imagen_url' => $solicitud->imagen_url,
                    'estado' => $solicitud->estado,
                    'activo' => $solicitud->activo,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                    'fecha_aprobacion' => $solicitud->fecha_aprobacion?->format('Y-m-d H:i:s'),
                    'motivo_rechazo' => $solicitud->motivo_rechazo,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Solicitudes obtenidas exitosamente',
                'data' => $data,
                'pagination' => [
                    'current_page' => $solicitudes->currentPage(),
                    'last_page' => $solicitudes->lastPage(),
                    'per_page' => $solicitudes->perPage(),
                    'total' => $solicitudes->total(),
                    'from' => $solicitudes->firstItem(),
                    'to' => $solicitudes->lastItem(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener solicitudes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Mostrar una solicitud específica.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function showSolicitud(int $id): JsonResponse
    {
        try {
            $solicitud = TripulanteSolicitud::with('posicionModel')->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud obtenida exitosamente',
                'data' => [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'crew_id' => $solicitud->crew_id,
                    'nombres' => $solicitud->nombres,
                    'apellidos' => $solicitud->apellidos,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'pasaporte' => $solicitud->pasaporte,
                    'identidad' => $solicitud->identidad,
                    'iata_aerolinea' => $solicitud->iata_aerolinea,
                    'email' => $solicitud->email,
                    'posicion_info' => [
                        'id_posicion' => $solicitud->posicionModel->id_posicion,
                        'codigo_posicion' => $solicitud->posicionModel->codigo_posicion,
                        'descripcion' => $solicitud->posicionModel->descripcion,
                    ],
                    'imagen_url' => $solicitud->imagen_url,
                    'estado' => $solicitud->estado,
                    'activo' => $solicitud->activo,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                    'fecha_aprobacion' => $solicitud->fecha_aprobacion?->format('Y-m-d H:i:s'),
                    'motivo_rechazo' => $solicitud->motivo_rechazo,
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener solicitud',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Aprobar una solicitud de tripulante.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function aprobarSolicitud(Request $request, int $id): JsonResponse
    {
        try {
            $solicitud = TripulanteSolicitud::findOrFail($id);

            if (!$solicitud->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden aprobar solicitudes pendientes'
                ], 400);
            }

            DB::beginTransaction();

            // Aprobar la solicitud
            $solicitud->approve($request->user()->id ?? null);

            // Crear registro en la tabla tripulantes
            $tripulante = Tripulante::create([
                'id_aerolinea' => null, // Campo legacy, ya no se usa
                'iata_aerolinea' => $solicitud->iata_aerolinea, // ← CORREGIDO: usar el de la solicitud
                'crew_id' => $solicitud->crew_id,
                'nombres' => $solicitud->nombres,
                'apellidos' => $solicitud->apellidos,
                'pasaporte' => $solicitud->pasaporte,
                'identidad' => $solicitud->identidad,
                'email' => $solicitud->email,
                'posicion' => $solicitud->posicion,
                'imagen' => $solicitud->imagen,
                'fecha_creacion' => now(),
                'estatus' => 1, // Activo
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Solicitud aprobada exitosamente',
                'data' => [
                    'solicitud' => [
                        'id_solicitud' => $solicitud->id_solicitud,
                        'estado' => $solicitud->estado,
                        'fecha_aprobacion' => $solicitud->fecha_aprobacion->format('Y-m-d H:i:s'),
                    ],
                    'tripulante_id' => $tripulante->id_tripulante,
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al aprobar solicitud',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Denegar una solicitud de tripulante.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function denegarSolicitud(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'motivo_rechazo' => 'required|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Motivo de rechazo es requerido',
                    'errors' => $validator->errors()
                ], 422);
            }

            $solicitud = TripulanteSolicitud::findOrFail($id);

            if (!$solicitud->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden denegar solicitudes pendientes'
                ], 400);
            }

            // Denegar la solicitud
            $solicitud->deny($request->motivo_rechazo, $request->user()->id ?? null);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud denegada exitosamente',
                'data' => [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'estado' => $solicitud->estado,
                    'motivo_rechazo' => $solicitud->motivo_rechazo,
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al denegar solicitud',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de solicitudes.
     *
     * @return JsonResponse
     */
    public function estadisticas(): JsonResponse
    {
        try {
            $stats = [
                'total' => TripulanteSolicitud::count(),
                'pendientes' => TripulanteSolicitud::pendientes()->count(),
                'aprobadas' => TripulanteSolicitud::aprobados()->count(),
                'denegadas' => TripulanteSolicitud::denegados()->count(),
                'activos' => TripulanteSolicitud::aprobados()->activos()->count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Estadísticas obtenidas exitosamente',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener todas las posiciones disponibles.
     *
     * @return JsonResponse
     */
    public function posiciones(): JsonResponse
    {
        try {
            $posiciones = Posicion::orderBy('descripcion')->get();

            return response()->json([
                'success' => true,
                'message' => 'Posiciones obtenidas exitosamente',
                'data' => $posiciones->map(function ($posicion) {
                    return [
                        'id_posicion' => $posicion->id_posicion,
                        'codigo_posicion' => $posicion->codigo_posicion,
                        'descripcion' => $posicion->descripcion,
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener posiciones',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Activar/Desactivar una solicitud aprobada.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        try {
            $solicitud = TripulanteSolicitud::findOrFail($id);

            if (!$solicitud->isApproved()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se puede cambiar el estado de solicitudes aprobadas'
                ], 400);
            }

            $solicitud->update([
                'activo' => !$solicitud->activo
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado exitosamente',
                'data' => [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'activo' => $solicitud->activo,
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }
}