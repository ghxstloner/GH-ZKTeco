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
        Schema::table('tripulantes_solicitudes', function (Blueprint $table) {
            $table->string('iata_aerolinea', 10)->nullable()->after('identidad');

            // Crear índice para consultas más rápidas
            $table->index('iata_aerolinea');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tripulantes_solicitudes', function (Blueprint $table) {
            $table->dropIndex(['iata_aerolinea']);
            $table->dropColumn('iata_aerolinea');
        });
    }
};