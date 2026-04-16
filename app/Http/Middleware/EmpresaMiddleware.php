<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\DatabaseSwitchService;

class EmpresaMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Obtener el código de empresa de la URL
        $codEmpresa = $request->route('codigo');

        // Verificar si el primer segmento es un número (código de empresa)
        if (is_numeric($codEmpresa)) {
            try {
                // Configurar la base de datos de la empresa
                DatabaseSwitchService::setBdEmpresa($codEmpresa);

                // Agregar el código de empresa al request para uso posterior
                $request->merge(['cod_empresa' => $codEmpresa]);

            } catch (\Exception $e) {
                return response('error', 400)->header('Content-Type', 'text/plain');
            }
        }

        return $next($request);
    }
}
