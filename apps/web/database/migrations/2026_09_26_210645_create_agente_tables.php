<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una solicitud del instructor al agente y su ciclo de vida
        Schema::create('agent_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('solicitado_por')->constrained('users');
            $table->string('plantilla', 32);
            $table->unsignedTinyInteger('paso'); // paso de CLT4BP (1–10)
            $table->jsonb('parametros'); // alcance, indicaciones, calidad
            $table->string('estado', 12)->default('en_cola'); // en_cola, procesando, listo, error
            $table->jsonb('resultado')->nullable(); // ResultadoGeneracion tal como lo devuelve el agente
            $table->text('error')->nullable();
            $table->uuid('clave_idempotencia');
            $table->timestamps();
            $table->unique(['solicitado_por', 'clave_idempotencia']);
            $table->index(['course_id', 'created_at']);
        });

        // Registro de investigación: qué modelo, cuánto costó, cuánto tardó y qué decidió el instructor
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_job_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('modelo', 64);
            $table->string('version_prompt', 32);
            $table->char('hash_contexto', 64); // sha256 de lo enviado al agente
            $table->unsignedInteger('tokens_entrada');
            $table->unsignedInteger('tokens_salida');
            $table->unsignedInteger('tokens_cache_escritura');
            $table->unsignedInteger('tokens_cache_lectura');
            $table->decimal('costo_usd', 10, 6);
            $table->unsignedInteger('duracion_ms');
            $table->unsignedTinyInteger('intentos');
            $table->jsonb('validaciones');
            $table->string('decision', 12)->nullable(); // aceptado, parcial, descartado
            $table->jsonb('uids_aceptados')->nullable();
            $table->timestamp('decidido_at')->nullable();
            $table->timestamps();
        });

        // Presupuesto mensual por instructor, en dólares
        Schema::create('agent_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('periodo', 7); // AAAA-MM
            $table->decimal('limite_usd', 8, 2);
            $table->decimal('usado_usd', 12, 6)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'periodo']);
        });

        // La columna ya existía desde la Etapa 3; ahora apunta a su corrida
        Schema::table('design_elements', function (Blueprint $table) {
            $table->foreign('agent_run_id')->references('id')->on('agent_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('design_elements', fn (Blueprint $table) => $table->dropForeign(['agent_run_id']));
        Schema::dropIfExists('agent_quotas');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_jobs');
    }
};
