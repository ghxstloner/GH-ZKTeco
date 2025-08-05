<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Verificar si la tabla existe
        if (!Schema::hasTable('personal_access_tokens')) {
            // Si no existe, crearla con el tipo correcto
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('tokenable_type');
                $table->string('tokenable_id'); // STRING en lugar de BIGINT
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['tokenable_type', 'tokenable_id']);
            });
        } else {
            // Si existe, modificar la columna
            try {
                // Eliminar índices existentes que puedan causar problemas
                DB::statement('DROP INDEX IF EXISTS personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens');
            } catch (\Exception $e) {
                // Ignorar si el índice no existe
            }

            // Modificar la columna tokenable_id
            DB::statement('ALTER TABLE personal_access_tokens MODIFY tokenable_id VARCHAR(255)');

            // Recrear el índice
            try {
                DB::statement('CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens (tokenable_type, tokenable_id)');
            } catch (\Exception $e) {
                // Ignorar si ya existe
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            // Eliminar datos que no sean enteros válidos
            DB::statement("DELETE FROM personal_access_tokens WHERE tokenable_id NOT REGEXP '^[0-9]+
");

            // Volver al tipo original
            DB::statement('ALTER TABLE personal_access_tokens MODIFY tokenable_id BIGINT UNSIGNED');
        }
    }
};