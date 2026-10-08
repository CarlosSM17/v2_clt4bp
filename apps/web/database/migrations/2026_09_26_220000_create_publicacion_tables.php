<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una publicación congela el material aprobado: lo que ven los estudiantes no cambia mientras el instructor edita
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('numero');                     // 1, 2, 3… por curso
            $table->jsonb('manifiesto');                           // DisenoCurso con solo lo aprobado
            $table->char('huella', 64);                            // sha256 del manifiesto
            $table->text('nota')->nullable();                      // nota de versión
            $table->string('estado', 12)->default('publicada');    // publicada | revertida
            $table->foreignId('publicado_por')->constrained('users');
            $table->uuid('clave_idempotencia');
            $table->timestamps();
            $table->unique(['course_id', 'numero']);
            $table->unique(['course_id', 'clave_idempotencia']);
        });

        // Paso 8: cuándo abre y cierra cada clase de tareas para cada grupo (null = todo el curso)
        Schema::create('activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('clase_uid', 64);                       // uid estable: sobrevive a nuevas publicaciones
            $table->foreignId('diff_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('abre_at');
            $table->timestamp('cierra_at')->nullable();
            $table->boolean('requiere_anterior')->default(false);
            $table->timestamp('avisado_at')->nullable();           // ya se envió el aviso de apertura
            $table->timestamps();
            $table->index(['course_id', 'clase_uid']);
            $table->index(['abre_at', 'avisado_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activations');
        Schema::dropIfExists('releases');
    }
};
