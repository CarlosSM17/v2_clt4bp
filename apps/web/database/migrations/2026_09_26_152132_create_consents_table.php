<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo');              // privacidad | investigacion | tutor
            $table->string('version');           // versión del texto aceptado, p. ej. 2026-09
            $table->timestamp('otorgado_at');
            $table->timestamp('revocado_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
