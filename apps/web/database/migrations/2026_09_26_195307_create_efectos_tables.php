<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cle_effects', function (Blueprint $table) {
            $table->string('id', 40)->primary();   // mismo identificador que EfectoId en contracts
            $table->string('nombre');
            $table->string('grupo', 24);           // nuevos_conocimientos, reforzamiento, ambos
            $table->text('definicion');
            $table->text('materializacion');
            $table->text('cuando_usar');
            $table->text('cuando_no');
            $table->timestamps();
        });

        Schema::create('effect_rules', function (Blueprint $table) {
            $table->string('id', 16)->primary();   // R1, R2…
            $table->string('nombre');
            $table->jsonb('condicion');
            $table->jsonb('efectos');
            $table->jsonb('recomendaciones');
            $table->text('fundamento');
            $table->boolean('activa')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('effect_rules');
        Schema::dropIfExists('cle_effects');
    }
};
