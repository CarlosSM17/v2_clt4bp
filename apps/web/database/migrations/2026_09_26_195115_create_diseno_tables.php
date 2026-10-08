<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Todos los elementos del diseño 4C/ID en una tabla: el contenido sigue su esquema de contracts
        Schema::create('design_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 64);                            // estable entre versiones y publicaciones
            $table->string('tipo', 20);                           // objetivo, clase, tarea, soporte, procedimental, practica_parcial, variante, medio
            $table->string('padre_uid', 64)->nullable();
            $table->unsignedInteger('orden')->default(1);
            $table->jsonb('contenido');
            $table->string('estado', 16)->default('borrador');    // propuesta, borrador, aprobado, publicado, archivado
            $table->unsignedInteger('version')->default(1);
            $table->string('autor_tipo', 8)->default('humano');   // humano | agente
            $table->unsignedBigInteger('agent_run_id')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('seq');                    // posición en el registro de cambios del curso
            $table->timestamp('eliminado_at')->nullable();        // lápida: la consola necesita enterarse de las bajas
            $table->timestamps();
            $table->unique(['course_id', 'uid']);
            $table->index(['course_id', 'seq']);
            $table->index(['course_id', 'tipo', 'padre_uid']);
        });
        // IF NOT EXISTS: migrate:fresh (usado por RefreshDatabase en las pruebas) borra las tablas con SQL
        // directo y nunca llama a down(), así que una secuencia sin dueño (no ligada a una columna) sobrevive.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS design_seq');

        // Historial: cada versión de cada elemento (dato de investigación y red de seguridad)
        Schema::create('design_element_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_element_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('contenido');
            $table->string('estado', 16);
            $table->string('autor_tipo', 8);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('eliminado')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['design_element_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_element_versions');
        Schema::dropIfExists('design_elements');
        DB::statement('DROP SEQUENCE IF EXISTS design_seq');
    }
};
