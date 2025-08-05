<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tripulantes_solicitudes', function (Blueprint $table) {
            $table->id('id_solicitud');
            $table->string('crew_id', 10);
            $table->string('nombres', 50);
            $table->string('apellidos', 50);
            $table->string('pasaporte', 20);
            $table->string('identidad', 20)->nullable();
            $table->integer('posicion'); // FK a posiciones
            $table->string('imagen', 250)->nullable();
            $table->enum('estado', ['Pendiente', 'Aprobado', 'Denegado'])->default('Pendiente');
            $table->boolean('activo')->default(true);
            $table->string('password'); // Hash de la contraseña
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token')->nullable();
            $table->datetime('fecha_solicitud')->default(now());
            $table->datetime('fecha_aprobacion')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->integer('aprobado_por')->nullable(); // FK a usuarios administradores
            $table->timestamps();

            // Índices
            $table->unique('crew_id');
            $table->unique('pasaporte');
            $table->index('estado');
            $table->index(['crew_id', 'estado']);

            // Foreign keys
            $table->foreign('posicion')->references('id_posicion')->on('posiciones');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tripulantes_solicitudes');
    }
};