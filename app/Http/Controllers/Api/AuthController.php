<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TripulanteSolicitud;
use App\Models\Posicion;
use App\Models\Aerolinea;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmailVerificationMail;

class AuthController extends Controller
{
    /**
     * Login de tripulante - SOLO USUARIOS APROBADOS Y ACTIVOS
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar SOLO usuarios aprobados y activos
            $tripulante = TripulanteSolicitud::where('crew_id', $request->crew_id)
                ->where('estado', 'Aprobado')  // SOLO APROBADOS
                ->where('activo', true)        // SOLO ACTIVOS
                ->with('posicionModel')
                ->first();

            if (!$tripulante) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales inválidas o cuenta no autorizada'
                ], 401);
            }

            // Verificar contraseña
            if (!Hash::check($request->password, $tripulante->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales inválidas'
                ], 401);
            }

            // Revocar tokens anteriores para evitar problemas
            $tripulante->tokens()->delete();

            // Crear nuevo token
            $token = $tripulante->createToken('mobile-app')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'data' => [
                    'token' => $token,
                    'tripulante' => [
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
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en login: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Registro de nuevo tripulante
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crew_id' => [
                    'required',
                    'string',
                    Rule::unique('tripulantes_solicitudes')->where(function ($query) use ($request) {
                        return $query->where('iata_aerolinea', $request->iata_aerolinea);
                    })
                ],
                'nombres' => 'required|string|max:50',
                'apellidos' => 'required|string|max:50',
                'pasaporte' => 'required|string|max:20|unique:tripulantes_solicitudes,pasaporte',
                'email' => 'required|email|unique:tripulantes_solicitudes,email',
                'identidad' => 'nullable|string|max:20',
                'iata_aerolinea' => 'required|string|max:2',
                'posicion' => 'required|integer|exists:posiciones,id_posicion',
                'password' => 'required|string|min:6',
                'image' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:5120',
            ], [
                // Mensajes personalizados más claros
                'crew_id.required' => 'El Crew ID es obligatorio',
                'crew_id.unique' => 'Este Crew ID ya está registrado en la aerolínea seleccionada',
                'pasaporte.unique' => 'Este número de pasaporte ya está registrado en el sistema',
                'email.unique' => 'Este correo electrónico ya está registrado en el sistema',
                'email.email' => 'El formato del correo electrónico no es válido',
                'nombres.required' => 'El nombre es obligatorio',
                'apellidos.required' => 'Los apellidos son obligatorios',
                'posicion.exists' => 'La posición seleccionada no es válida',
                'iata_aerolinea.required' => 'La aerolínea es obligatoria',
                'password.min' => 'La contraseña debe tener al menos 6 caracteres',
            ]);

            if ($validator->fails()) {
                // Obtener el primer error más específico
                $errors = $validator->errors();
                $firstError = $errors->first();

                return response()->json([
                    'success' => false,
                    'message' => $firstError, // Mensaje específico del error
                    'errors' => $errors->toArray(),
                    'error_details' => $this->formatValidationErrors($errors)
                ], 422);
            }

            $nombreImagen = null;

            // Procesar imagen si existe
            if ($request->hasFile('image')) {
                try {
                    $archivo = $request->file('image');

                    if (!$archivo->isValid()) {
                        throw new \Exception('Archivo de imagen inválido');
                    }

                    $extension = $archivo->getClientOriginalExtension();
                    $nombreImagen = 'foto.' . $extension;

                    // ✅ ESTRUCTURA UNIFICADA: iata_aerolinea/crew_id
                    $directorio = $request->iata_aerolinea . '/' . $request->crew_id;
                    $rutaCompleta = $directorio . '/' . $nombreImagen;

                    $disk = Storage::disk('crew_images');
                    $disk->makeDirectory($directorio);

                    $contenidoArchivo = file_get_contents($archivo->getPathname());
                    $guardado = $disk->put($rutaCompleta, $contenidoArchivo);

                    if (!$guardado) {
                        throw new \Exception('Error al guardar imagen');
                    }

                } catch (\Exception $e) {
                    \Log::error('Error al procesar imagen: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Error al procesar la imagen: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Crear solicitud
            $solicitud = TripulanteSolicitud::create([
                'crew_id' => $request->crew_id,
                'nombres' => $request->nombres,
                'apellidos' => $request->apellidos,
                'pasaporte' => $request->pasaporte,
                'identidad' => $request->identidad,
                'iata_aerolinea' => $request->iata_aerolinea,
                'email' => $request->email,
                'posicion' => $request->posicion,
                'imagen' => $nombreImagen,
                'password' => Hash::make($request->password),
                'estado' => 'Pendiente',
                'activo' => false,
                'email_verified' => 0, // ✅ Por defecto NO verificado
                'email_verified_at' => null, // ✅ Sin timestamp inicial
                'fecha_solicitud' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud creada exitosamente',
                'data' => [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'crew_id' => $solicitud->crew_id,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'estado' => $solicitud->estado,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en registro: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el registro',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Formatear errores de validación para el frontend
     */
    private function formatValidationErrors($errors): array
    {
        $formatted = [];
        foreach ($errors->toArray() as $field => $messages) {
            $formatted[$field] = [
                'field' => $field,
                'messages' => $messages,
                'first_message' => $messages[0] ?? ''
            ];
        }
        return $formatted;
    }

    /**
     * Verificar estado de solicitud
     */
    public function checkStatus(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Crew ID es requerido',
                    'errors' => $validator->errors()
                ], 422);
            }

            $solicitud = TripulanteSolicitud::where('crew_id', $request->crew_id)->first();

            if (!$solicitud) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró ninguna solicitud con ese Crew ID'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Estado de solicitud obtenido exitosamente',
                'data' => [
                    'crew_id' => $solicitud->crew_id,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'estado' => $solicitud->estado,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                    'fecha_aprobacion' => $solicitud->fecha_aprobacion?->format('Y-m-d H:i:s'),
                    'motivo_rechazo' => $solicitud->motivo_rechazo,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al verificar estado: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al verificar estado',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener información del usuario autenticado
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user()->load('posicionModel');

            // Verificar que el usuario siga siendo válido
            if (!$tripulante->isApproved() || !$tripulante->activo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'message' => 'Información de usuario obtenida exitosamente',
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
            \Log::error('Error al obtener información del usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del usuario',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout exitoso'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en logout: ' . $e->getMessage());

            return response()->json([
                'success' => true,
                'message' => 'Logout completado'
            ]);
        }
    }

    /**
     * Obtener todas las posiciones disponibles
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
            \Log::error('Error al obtener posiciones: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener posiciones',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener todas las aerolíneas disponibles
     */
    public function aerolineas(): JsonResponse
    {
        try {
            $aerolineas = Aerolinea::orderBy('descripcion')->get();

            return response()->json([
                'success' => true,
                'message' => 'Aerolíneas obtenidas exitosamente',
                'data' => $aerolineas->map(function ($aerolinea) {
                    return [
                        'id_aerolinea' => $aerolinea->id_aerolinea,
                        'descripcion' => $aerolinea->descripcion,
                        'siglas' => $aerolinea->siglas,
                    ];
                })
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener aerolíneas: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener aerolíneas',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Iniciar el proceso de registro con verificación de email
     */
    public function initiateRegister(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crew_id' => [
                    'required',
                    'string',
                    'max:10',
                    Rule::unique('tripulantes_solicitudes')->where(function ($query) use ($request) {
                        return $query->where('iata_aerolinea', $request->iata_aerolinea);
                    }),
                ],
                'nombres' => 'required|string|max:50',
                'apellidos' => 'required|string|max:50',
                'email' => 'required|email|max:100|unique:tripulantes_solicitudes,email',
                'pasaporte' => 'required|string|max:20|unique:tripulantes_solicitudes,pasaporte',
                'identidad' => 'nullable|string|max:20',
                'iata_aerolinea' => 'required|string|size:2|exists:aerolineas,siglas',
                'posicion' => 'required|integer|exists:posiciones,id_posicion',
                'password' => 'required|string|min:6',
                'image' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:5120',
            ], [
                // Mensajes personalizados en español
                'crew_id.required' => 'El Crew ID es obligatorio',
                'crew_id.unique' => 'Este Crew ID ya está registrado en esta aerolínea',
                'crew_id.max' => 'El Crew ID no puede tener más de 10 caracteres',
                'nombres.required' => 'Los nombres son obligatorios',
                'nombres.max' => 'Los nombres no pueden tener más de 50 caracteres',
                'apellidos.required' => 'Los apellidos son obligatorios',
                'apellidos.max' => 'Los apellidos no pueden tener más de 50 caracteres',
                'email.required' => 'El email es obligatorio',
                'email.email' => 'El email debe tener un formato válido',
                'email.unique' => 'Este email ya está registrado',
                'email.max' => 'El email no puede tener más de 100 caracteres',
                'pasaporte.required' => 'El número de pasaporte es obligatorio',
                'pasaporte.unique' => 'Este número de pasaporte ya está registrado',
                'pasaporte.max' => 'El número de pasaporte no puede tener más de 20 caracteres',
                'iata_aerolinea.required' => 'La aerolínea es obligatoria',
                'iata_aerolinea.size' => 'El código de aerolínea debe tener exactamente 2 caracteres',
                'iata_aerolinea.exists' => 'La aerolínea seleccionada no es válida',
                'posicion.required' => 'La posición es obligatoria',
                'posicion.integer' => 'La posición debe ser un número válido',
                'posicion.exists' => 'La posición seleccionada no es válida',
                'password.required' => 'La contraseña es obligatoria',
                'password.min' => 'La contraseña debe tener al menos 6 caracteres',
                'image.image' => 'El archivo debe ser una imagen válida',
                'image.mimes' => 'La imagen debe ser de tipo: jpeg, jpg, png o gif',
                'image.max' => 'La imagen no puede ser mayor a 5MB',
            ]);

            if ($validator->fails()) {
                $errors = $validator->errors();

                // Obtener el primer error más específico
                $firstError = $errors->first();

                $errorDetails = [];

                // Manejar errores específicos
                if ($errors->has('crew_id')) {
                    $errorDetails['crew_id'] = [
                        'field' => 'crew_id',
                        'message' => 'Este Crew ID ya está registrado en esta aerolínea',
                        'suggestion' => 'Verifica tu Crew ID o contacta al administrador'
                    ];
                }

                if ($errors->has('email')) {
                    $errorDetails['email'] = [
                        'field' => 'email',
                        'message' => 'Este email ya está registrado',
                        'suggestion' => 'Usa un email diferente o recupera tu cuenta existente'
                    ];
                }

                if ($errors->has('pasaporte')) {
                    $errorDetails['pasaporte'] = [
                        'field' => 'pasaporte',
                        'message' => 'Este número de pasaporte ya está registrado',
                        'suggestion' => 'Verifica el número de pasaporte'
                    ];
                }

                return response()->json([
                    'success' => false,
                    'message' => $firstError, // Usar el mensaje personalizado en español
                    'errors' => $errors,
                    'error_details' => $errorDetails
                ], 422);
            }

            // Generar PIN de 6 dígitos
            $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Crear clave única para este registro
            $verificationKey = 'email_verification_' . $request->crew_id . '_' . time();

            // Procesar imagen si existe
            $imagenNombre = null;
            if ($request->hasFile('image')) {
                try {
                    $archivo = $request->file('image');
                    if (!$archivo->isValid()) {
                        throw new \Exception('Archivo de imagen inválido');
                    }

                    $extension = $archivo->getClientOriginalExtension();
                    $imagenNombre = 'foto.' . $extension;
                    $directorio = $request->iata_aerolinea . '/' . $request->crew_id;
                    $rutaCompleta = $directorio . '/' . $imagenNombre;

                    $disk = Storage::disk('crew_images');
                    $disk->makeDirectory($directorio);

                    $contenidoArchivo = file_get_contents($archivo->getPathname());
                    $guardado = $disk->put($rutaCompleta, $contenidoArchivo);

                    if (!$guardado) {
                        throw new \Exception('Error al guardar imagen');
                    }
                } catch (\Exception $e) {
                    \Log::error('Error al procesar imagen: ' . $e->getMessage());
                    $imagenNombre = null;
                }
            }

            // Guardar datos temporales en cache (20 minutos)
            $tempData = [
                'crew_id' => $request->crew_id,
                'nombres' => $request->nombres,
                'apellidos' => $request->apellidos,
                'email' => $request->email,
                'pasaporte' => $request->pasaporte,
                'identidad' => $request->identidad,
                'iata_aerolinea' => $request->iata_aerolinea,
                'posicion' => $request->posicion,
                'password_hash' => Hash::make($request->password),
                'imagen' => $imagenNombre,
                'pin' => $pin,
                'pin_expires_at' => now()->addMinutes(15)->timestamp,
            ];

            cache()->put($verificationKey, $tempData, now()->addMinutes(20));

            // Enviar email de verificación
            try {
                Mail::to($request->email)->send(
                    new EmailVerificationMail($pin, $request->crew_id)
                );
            } catch (\Exception $e) {
                \Log::error('Error enviando email de verificación: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al enviar el email de verificación. Verifica que tu dirección de correo sea válida.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Email de verificación enviado exitosamente',
                'data' => [
                    'verification_key' => $verificationKey,
                    'email' => $request->email,
                    'crew_id' => $request->crew_id,
                    'expires_in_minutes' => 15
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en initiate register: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud de registro',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Verificar PIN y completar registro
     */
    public function verifyEmailAndRegister(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'verification_key' => 'required|string',
                'pin' => 'required|string|size:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de verificación inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Recuperar datos del cache
            $tempData = cache()->get($request->verification_key);

            if (!$tempData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código de verificación expirado o inválido. Reinicia el proceso de registro.'
                ], 400);
            }

            // Verificar si el PIN ha expirado
            if (time() > $tempData['pin_expires_at']) {
                cache()->forget($request->verification_key);
                return response()->json([
                    'success' => false,
                    'message' => 'El código de verificación ha expirado'
                ], 400);
            }

            // Verificar PIN
            if ($tempData['pin'] !== $request->pin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código de verificación incorrecto'
                ], 400);
            }

            // PIN válido, crear la solicitud de tripulante con email verificado
            $solicitud = TripulanteSolicitud::create([
                'crew_id' => $tempData['crew_id'],
                'nombres' => $tempData['nombres'],
                'apellidos' => $tempData['apellidos'],
                'email' => $tempData['email'],
                'pasaporte' => $tempData['pasaporte'],
                'identidad' => $tempData['identidad'],
                'iata_aerolinea' => $tempData['iata_aerolinea'],
                'posicion' => $tempData['posicion'],
                'password' => $tempData['password_hash'],
                'imagen' => $tempData['imagen'],
                'estado' => 'Pendiente',
                'activo' => true,
                'email_verified' => 1, // ✅ Marcar email como verificado
                'email_verified_at' => now(), // ✅ Timestamp de verificación
                'fecha_solicitud' => now(),
            ]);

            // Limpiar datos temporales
            cache()->forget($request->verification_key);

            return response()->json([
                'success' => true,
                'message' => 'Registro completado exitosamente. Tu solicitud está pendiente de aprobación.',
                'data' => [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'crew_id' => $solicitud->crew_id,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'estado' => $solicitud->estado,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                    'email_verified' => true,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en verify email and register: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al completar el registro',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Reenviar PIN de verificación
     */
    public function resendVerificationPin(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'verification_key' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Clave de verificación requerida',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Recuperar datos del cache
            $tempData = cache()->get($request->verification_key);

            if (!$tempData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sesión de verificación expirada. Reinicia el proceso de registro.'
                ], 400);
            }

            // Generar nuevo PIN
            $newPin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Actualizar datos en cache con nuevo PIN
            $tempData['pin'] = $newPin;
            $tempData['pin_expires_at'] = now()->addMinutes(15)->timestamp;

            cache()->put($request->verification_key, $tempData, now()->addMinutes(20));

            // Enviar nuevo email
            try {
                Mail::to($tempData['email'])->send(
                    new EmailVerificationMail($newPin, $tempData['crew_id'])
                );
            } catch (\Exception $e) {
                \Log::error('Error enviando email de verificación: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al enviar el email de verificación',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Nuevo código de verificación enviado',
                'data' => [
                    'verification_key' => $request->verification_key,
                    'expires_in_minutes' => 15
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en resend verification pin: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al reenviar código de verificación',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }
}
