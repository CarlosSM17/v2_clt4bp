<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El archivo. Sus metadatos (título, segmentos, transcripción) son un elemento de diseño tipo «medio».
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 64);
            $table->string('ruta');
            $table->string('mime', 100);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['course_id', 'uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
