<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiTokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        // Verificar que el token coincida con el definido en .env
        // Puedes definir tu token en el archivo .env como API_TOKEN=tu_token_secreto
        if (!$token || $token !== env('API_TOKEN')) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        return $next($request);
    }
}
