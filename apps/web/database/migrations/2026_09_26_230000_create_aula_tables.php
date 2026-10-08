<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avance de cada estudiante en cada tarea (una fila por tarea; se actualiza)
        Schema::create('task_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('tarea_uid', 64);
            $table->string('estado', 12)->default('en_progreso'); // en_progreso | completada
            $table->text('borrador_codigo')->nullable();          // autoguardado del editor
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->decimal('mejor_fraccion', 5, 4)->nullable();
            $table->unsignedTinyInteger('esfuerzo')->nullable();  // escala de Paas, 1–9
            $table->text('autoexplicacion')->nullable();
            $table->timestamp('iniciado_at')->nullable();
            $table->timestamp('completado_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'tarea_uid']);
        });

        // Cada envío con su resultado (historial completo: dato de investigación)
        Schema::create('task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('tarea_uid', 64);
            $table->foreignId('release_id')->constrained();
            $table->unsignedSmallInteger('numero'); // 1.º, 2.º… intento de esa tarea
            $table->text('codigo');
            $table->text('autoexplicacion')->nullable();
            $table->string('estado', 12)->default('en_cola'); // en_cola | calificado | error
            $table->jsonb('resultado')->nullable();
            $table->decimal('fraccion', 5, 4)->nullable();
            $table->timestamps();
            $table->index(['enrollment_id', 'tarea_uid']);
        });

        // Eventos de aprendizaje, al estilo xAPI: quién (inscripción), qué hizo (verbo), sobre qué (objeto)
        Schema::create('learning_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('verbo', 32);
            $table->string('objeto_tipo', 20)->nullable(); // clase, tarea, soporte, ayuda, medio, practica
            $table->string('objeto_uid', 64)->nullable();
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('resultado')->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->string('origen', 8)->default('cliente'); // cliente | servidor
            $table->timestamp('ocurrido_at');
            $table->timestamp('recibido_at')->useCurrent();
            $table->index(['enrollment_id', 'ocurrido_at']);
            $table->index(['objeto_uid', 'verbo']);
        });

        // Discusión por tarea dentro del grupo (memoria colectiva)
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('tarea_uid', 64);
            $table->foreignId('diff_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('texto');
            $table->boolean('oculto')->default(false); // moderación del instructor
            $table->timestamps();
            $table->index(['course_id', 'tarea_uid', 'diff_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('learning_events');
        Schema::dropIfExists('task_submissions');
        Schema::dropIfExists('task_progress');
    }
};
