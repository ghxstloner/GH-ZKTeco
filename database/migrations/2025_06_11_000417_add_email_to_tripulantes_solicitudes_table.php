<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Usamos Schema::table() para modificar una tabla existente.
        Schema::table('tripulantes_solicitudes', function (Blueprint $table) {
            // Añadimos la columna 'email'.
            // La hacemos 'nullable' para evitar errores en tablas con datos existentes.
            // Los valores NULL no se consideran duplicados en un índice único.
            // La colocamos después de la columna 'apellidos' para mantener un orden lógico.
            // Le agregamos un índice único para asegurar que no haya emails duplicados.
            $table->string('email')->nullable()->unique()->after('apellidos');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // El método down() revierte lo que hicimos en el método up().
        Schema::table('tripulantes_solicitudes', function (Blueprint $table) {
            // Eliminamos la columna 'email' si se necesita revertir la migración.
            // No es necesario eliminar primero el índice único, dropColumn se encarga de ello.
            $table->dropColumn('email');
        });
    }
};
