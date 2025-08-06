<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AmaxoniaMarcacionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MarcacionController extends Controller
{
    /**
     * Procesa una marcación desde aplicación móvil
     */
    public function procesarMarcacion(Request $request): JsonResponse
    {
        try {
            // Validar datos de entrada
            $validator = Validator::make($request->all(), [
                'pin' => 'required|string|max:50',
                'fecha_hora' => 'required|date',
                'tipo' => 'nullable|string|in:E,S',
                'dispositivo' => 'nullable|string|max:100',
                'latitud' => 'nullable|numeric',
                'longitud' => 'nullable|numeric'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Procesar marcación
            $resultado = AmaxoniaMarcacionService::procesarMarcacion($request->all());

            return response()->json($resultado, 200);

        } catch (\Exception $e) {
            Log::error("Error procesando marcación: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error procesando marcación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene las marcaciones de un empleado
     */
    public function obtenerMarcaciones(Request $request): JsonResponse
    {
        try {
            // Validar datos de entrada
            $validator = Validator::make($request->all(), [
                'pin' => 'required|string|max:50',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Obtener marcaciones
            $resultado = AmaxoniaMarcacionService::obtenerMarcaciones(
                $request->pin,
                $request->fecha_inicio,
                $request->fecha_fin
            );

            return response()->json([
                'success' => true,
                'data' => $resultado
            ], 200);

        } catch (\Exception $e) {
            Log::error("Error obteniendo marcaciones: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo marcaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verifica el estado de un empleado
     */
    public function verificarEstado(Request $request): JsonResponse
    {
        try {
            // Validar datos de entrada
            $validator = Validator::make($request->all(), [
                'pin' => 'required|string|max:50'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Verificar estado
            $resultado = AmaxoniaMarcacionService::verificarEstadoEmpleado($request->pin);

            return response()->json([
                'success' => true,
                'data' => $resultado
            ], 200);

        } catch (\Exception $e) {
            Log::error("Error verificando estado: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error verificando estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene información de la empresa actual
     */
    public function obtenerEmpresaInfo(Request $request): JsonResponse
    {
        try {
            $empresa = \App\Services\DatabaseSwitchService::getEmpresaActual();
            
            if (!$empresa) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay empresa configurada'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'codigo' => $empresa['codigo'],
                    'nombre' => $empresa['nombre'],
                    'bd_nomina' => $empresa['bd_nomina']
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Error obteniendo información de empresa: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo información de empresa: ' . $e->getMessage()
            ], 500);
        }
    }
}
