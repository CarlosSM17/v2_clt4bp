<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Instrumentos tipo Likert (MSLQ, CS, IMMS, CIS, Paas) cargados desde JSON
        Schema::create('instruments', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 32);
            $table->string('version', 32);
            $table->string('nombre');
            $table->jsonb('definicion');
            $table->timestamps();
            $table->unique(['clave', 'version']);
        });

        // Aplicación de un instrumento en un curso y un momento
        Schema::create('instrument_administrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained();
            $table->string('momento', 16);               // pre | post | clase
            $table->string('clase_uid')->nullable();     // para la escala CS por clase de tareas (Etapa 5)
            $table->timestamp('abre_at')->nullable();
            $table->timestamp('cierra_at')->nullable();
            $table->timestamps();
        });

        Schema::create('instrument_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_administration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->jsonb('respuestas')->default('{}');
            $table->jsonb('puntajes')->nullable();
            $table->timestamp('completado_at')->nullable();
            $table->timestamps();
            $table->unique(['instrument_administration_id', 'enrollment_id']);
        });

        // Banco de ítems
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('objetivo', 32)->nullable();           // p. ej. OB-3
            $table->string('tipo', 24);                           // opcion_multiple | respuesta_corta | prediccion_salida | parsons | programacion
            $table->string('nivel', 16);                          // recall | comprension | practica
            $table->jsonb('enunciado');                           // {md, opciones?, lineas?, codigo_inicial?}
            $table->jsonb('clave')->nullable();                   // nunca se envía al estudiante
            $table->string('lenguaje', 16)->nullable();
            $table->jsonb('casos_prueba')->nullable();
            $table->text('solucion')->nullable();
            $table->string('autor_tipo', 16)->default('humano');  // humano | agente
            $table->string('estado', 16)->default('borrador');    // borrador | aprobado
            $table->timestamp('verificado_at')->nullable();       // la solución pasó sus casos
            $table->timestamps();
        });

        // Pruebas (pre/post, teórica/práctica, forma A/B)
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('nombre');
            $table->string('momento', 16);   // pre | post
            $table->string('tipo', 16);      // teorica | practica
            $table->string('forma', 1)->default('A');
            $table->unsignedSmallInteger('tiempo_limite_min')->nullable();
            $table->timestamp('abre_at')->nullable();
            $table->timestamp('cierra_at')->nullable();
            $table->timestamps();
        });

        Schema::create('assessment_items', function (Blueprint $table) {
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->decimal('puntos', 5, 2)->default(1);
            $table->primary(['assessment_id', 'item_id']);
        });

        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->timestamp('iniciado_at');
            $table->timestamp('enviado_at')->nullable();
            $table->timestamp('calificado_at')->nullable();
            $table->decimal('porcentaje', 5, 2)->nullable();
            $table->jsonb('subpuntajes')->nullable();   // {recall: %, comprension: %, practica: %}
            $table->timestamps();
            $table->unique(['assessment_id', 'enrollment_id']);
        });

        Schema::create('item_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->jsonb('respuesta')->nullable();
            $table->decimal('fraccion', 5, 4)->nullable();   // 0 a 1
            $table->jsonb('detalle')->nullable();            // resultado de casos de prueba
            $table->timestamps();
            $table->unique(['assessment_attempt_id', 'item_id']);
        });

        // Perfiles, análisis del grupo y grupos diferenciados
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->decimal('cp_recall', 5, 2);
            $table->decimal('cp_comprension', 5, 2);
            $table->decimal('cp_teorico', 5, 2);
            $table->decimal('cp_practico', 5, 2);
            $table->decimal('cp_global', 5, 2);
            $table->string('nivel', 16);
            $table->jsonb('mslq');
            $table->jsonb('indices');
            $table->jsonb('banderas');
            $table->timestamps();
            $table->unique(['enrollment_id', 'version']);
        });

        Schema::create('group_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->jsonb('resultado');                        // salida de AnalizadorGrupo
            $table->string('recomendacion', 16);
            $table->string('decision', 16)->nullable();        // homogeneo | heterogeneo
            $table->text('justificacion')->nullable();
            $table->foreignId('decidido_por')->nullable()->constrained('users');
            $table->timestamp('decidido_at')->nullable();
            $table->timestamps();
        });

        Schema::create('diff_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('clave', 8);           // G1, G2…
            $table->string('nombre');             // visible al estudiante: Ruta A
            $table->string('nivel', 16)->nullable();
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
            $table->unique(['course_id', 'clave']);
        });

        Schema::create('group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diff_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->timestamp('desde');
            $table->timestamp('hasta')->nullable();   // historial de reagrupaciones
            $table->string('motivo')->nullable();
            $table->timestamps();
            $table->index(['enrollment_id', 'hasta']);
        });
    }

    public function down(): void
    {
        foreach ([
            'group_memberships', 'diff_groups', 'group_analyses', 'student_profiles', 'item_responses',
            'assessment_attempts', 'assessment_items', 'assessments', 'items', 'instrument_responses',
            'instrument_administrations', 'instruments',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
