<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('lenguaje', 16);                    // c | cpp | python
            $table->string('nivel_educativo', 32);             // secundaria | preparatoria | universidad
            $table->string('codigo_inscripcion', 12)->unique();
            $table->string('estado', 16)->default('diseno');
            $table->date('inicia_el')->nullable();
            $table->date('termina_el')->nullable();
            $table->jsonb('configuracion')->default('{}');     // umbrales y pesos del perfil (Etapa 2)
            $table->timestamps();
        });

        Schema::create('course_instructor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rol', 16)->default('responsable'); // responsable | colaborador
            $table->timestamps();
            $table->unique(['course_id', 'user_id']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('estado', 24);
            $table->string('seudonimo', 16)->unique();
            $table->timestamp('solicitado_at')->nullable();
            $table->timestamp('inscrito_at')->nullable();
            $table->timestamp('baja_at')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'user_id']);
            $table->index(['course_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('course_instructor');
        Schema::dropIfExists('courses');
    }
};
