<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('institucion')->nullable()->after('email');
            $table->foreignId('invitado_por')->nullable()->after('institucion')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('invitacion_aceptada_at')->nullable()->after('invitado_por');
            $table->timestamp('suspendido_at')->nullable()->after('invitacion_aceptada_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invitado_por');
            $table->dropColumn(['institucion', 'invitacion_aceptada_at', 'suspendido_at']);
        });
    }
};
