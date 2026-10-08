<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paso 10: cada revisión de resultados y su decisión (cerrar el ciclo o iterar)
        Schema::create('result_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');       // 1.ª, 2.ª... iteración del curso
            $table->string('decision', 8);                // cerrar | iterar
            $table->string('regresar_a', 8)->nullable();  // fase1 | fase2 (solo al iterar)
            $table->text('notas');
            $table->jsonb('resumen');                     // fotografía de los resultados al decidir
            $table->foreignId('agent_job_id')->nullable()->constrained()->nullOnDelete(); // informe del agente usado
            $table->foreignId('decidido_por')->constrained('users');
            $table->timestamps();
            $table->unique(['course_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_reviews');
    }
};
