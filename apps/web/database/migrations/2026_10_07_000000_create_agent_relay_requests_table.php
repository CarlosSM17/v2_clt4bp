<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relevo hacia un agente local (ADR 0008): cada llamada de Laravel al agente queda aquí como solicitud pendiente; el
 * conector del equipo del agente la reclama, la reenvía al agente y devuelve su respuesta. Se borran a las 24 horas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_relay_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('metodo', 6);                       // GET | POST
            $table->string('ruta', 120);                       // /v1/generar, /v1/verificar…
            $table->longText('cuerpo')->nullable();             // JSON tal cual se envía: {} sigue siendo {}
            $table->string('archivo')->nullable();             // ruta en el disco local (documentos del material)
            $table->string('archivo_nombre')->nullable();
            $table->string('estado', 12)->default('pendiente'); // pendiente, reclamada, respondida
            $table->unsignedSmallInteger('respuesta_estado')->nullable();
            $table->longText('respuesta')->nullable();          // el cuerpo tal como lo devolvió el agente
            $table->timestamp('vence_at');                     // después de esto, nadie la espera
            $table->timestamp('reclamada_at')->nullable();
            $table->timestamp('respondida_at')->nullable();
            $table->timestamps();
            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_relay_requests');
    }
};
