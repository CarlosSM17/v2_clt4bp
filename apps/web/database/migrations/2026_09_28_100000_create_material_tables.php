<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Material del curso para el agente (RAG local): documentos que sube el instructor y sus fragmentos con
 * embedding. Laravel es dueño del índice; el agente solo fragmenta y calcula vectores (ADR 0006).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('course_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('titulo', 200);
            $table->string('nombre_original', 255);
            $table->string('ruta');
            $table->string('mime', 100);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->string('estado', 12)->default('procesando'); // procesando | listo | error
            $table->text('error')->nullable();
            $table->unsignedInteger('fragmentos')->default(0);
            $table->string('modelo_embeddings', 64)->nullable();
            $table->foreignId('subido_por')->constrained('users');
            $table->timestamps();
            $table->unique(['course_id', 'sha256']); // el mismo archivo no se indexa dos veces en un curso
        });

        Schema::create('document_fragments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete(); // la búsqueda filtra por curso
            $table->unsignedInteger('orden');
            $table->unsignedInteger('pagina')->nullable();
            $table->text('texto');
            // bge-m3: 1024 dimensiones. Cambiar de modelo de embeddings exige una migración nueva y reprocesar
            $table->vector('embedding', 1024);
            $table->timestamps();
            $table->index(['course_id', 'course_document_id']);
            $table->vectorIndex('embedding'); // HNSW con distancia coseno
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_fragments');
        Schema::dropIfExists('course_documents');
    }
};
