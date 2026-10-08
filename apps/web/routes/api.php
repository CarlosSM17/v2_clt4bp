<?php

use App\Http\Controllers\Api\V1\ActivacionController;
use App\Http\Controllers\Api\V1\Admin\InstructorController;
use App\Http\Controllers\Api\V1\AgenteController;
use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ComentarioModeracionController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\DiagnosticoController;
use App\Http\Controllers\Api\V1\DisenoController;
use App\Http\Controllers\Api\V1\DocumentoController;
use App\Http\Controllers\Api\V1\EfectoController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\EvaluacionController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\MedioController;
use App\Http\Controllers\Api\V1\PublicacionController;
use App\Http\Controllers\Api\V1\RelevoController;
use App\Http\Controllers\Api\V1\ResultadosController;
use App\Http\Controllers\Api\V1\TableroController;
use App\Http\Controllers\Api\V1\TrazaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login-consola');

    // Relevo hacia el agente local (ADR 0008): solo su conector, con AGENTE_TOKEN; 404 fuera del modo relevo
    Route::middleware('conector-agente')->prefix('relevo')->group(function () {
        Route::get('siguiente', [RelevoController::class, 'siguiente']);
        Route::get('{solicitud}/archivo', [RelevoController::class, 'archivo']);
        Route::post('{solicitud}/respuesta', [RelevoController::class, 'responder']);
    });

    Route::middleware(['auth:sanctum', 'ability:consola', 'no-suspendido', 'can:personal'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::apiResource('courses', CourseController::class)->except('destroy');
        Route::get('courses/{course}/enrollments', [EnrollmentController::class, 'index']);
        Route::post('courses/{course}/enrollments', [EnrollmentController::class, 'store']);
        Route::patch('enrollments/{enrollment}', [EnrollmentController::class, 'update']);

        // Banco de ítems y pruebas
        Route::get('courses/{course}/items', [ItemController::class, 'index']);
        Route::post('courses/{course}/items', [ItemController::class, 'store']);
        Route::put('items/{item}', [ItemController::class, 'update']);
        Route::post('items/{item}/verificar', [ItemController::class, 'verificar']);
        Route::post('items/{item}/aprobar', [ItemController::class, 'aprobar']);
        Route::get('courses/{course}/assessments', [AssessmentController::class, 'index']);
        Route::post('courses/{course}/assessments', [AssessmentController::class, 'store']);

        // Diagnóstico, homogeneidad y grupos
        Route::post('courses/{course}/diagnostico', [DiagnosticoController::class, 'preparar']);
        Route::get('courses/{course}/diagnostico', [DiagnosticoController::class, 'estado']);
        Route::get('courses/{course}/analisis', [DiagnosticoController::class, 'analisis']);
        Route::post('courses/{course}/analisis/{analisis}/decision', [DiagnosticoController::class, 'decidir']);
        Route::post('courses/{course}/grupos/propuesta', [DiagnosticoController::class, 'proponerGrupos']);
        Route::put('courses/{course}/grupos', [DiagnosticoController::class, 'guardarGrupos']);

        // Diseño 4C/ID: sincronización con la consola
        Route::get('courses/{course}/design', [DisenoController::class, 'index']);
        Route::post('courses/{course}/design/sync', [DisenoController::class, 'sincronizar']);
        Route::get('courses/{course}/design/{uid}/versions', [DisenoController::class, 'versiones']);
        Route::post('courses/{course}/design/verify', [DisenoController::class, 'verificar']);
        Route::post('courses/{course}/design/approve', [DisenoController::class, 'aprobar']);

        // Archivos multimedia (protocolos verbales, videos, audios, imágenes)
        Route::post('courses/{course}/media', [MedioController::class, 'store']);
        Route::get('courses/{course}/media/{uid}', [MedioController::class, 'show']);

        // Material del curso que consulta el agente (RAG local)
        Route::get('courses/{course}/documents', [DocumentoController::class, 'index']);
        Route::post('courses/{course}/documents', [DocumentoController::class, 'store']);
        Route::delete('courses/{course}/documents/{document}', [DocumentoController::class, 'destroy']);

        // Trazas de código: pasos reales calculados ejecutando el programa (agente → trazador)
        Route::post('courses/{course}/trazas', [TrazaController::class, 'completar'])->middleware('throttle:agente');

        // Catálogo de efectos de la TCC y preselección (paso 3)
        Route::get('effects', [EfectoController::class, 'catalogo']);
        Route::get('courses/{course}/preselection', [EfectoController::class, 'preseleccion']);

        // Etapa 4: asistente de diseño
        Route::get('agent/templates', [AgenteController::class, 'plantillas']);
        Route::get('agent/usage', [AgenteController::class, 'uso']);
        Route::get('courses/{course}/agent/jobs', [AgenteController::class, 'index']);
        Route::post('courses/{course}/agent/jobs', [AgenteController::class, 'store'])->middleware('throttle:agente');
        Route::get('agent/jobs/{job}', [AgenteController::class, 'show']);
        Route::post('agent/jobs/{job}/decision', [AgenteController::class, 'decision']);
        Route::delete('agent/jobs/{job}', [AgenteController::class, 'destroy']);

        // Etapa 5: publicación
        Route::get('courses/{course}/releases', [PublicacionController::class, 'index']);
        Route::get('courses/{course}/releases/preview', [PublicacionController::class, 'revisar']);
        Route::post('courses/{course}/releases', [PublicacionController::class, 'store']);
        Route::post('releases/{release}/rollback', [PublicacionController::class, 'rollback']);
        Route::get('courses/{course}/activations', [ActivacionController::class, 'index']);
        Route::put('courses/{course}/activations', [ActivacionController::class, 'update']);
        Route::get('courses/{course}/comments', [ComentarioModeracionController::class, 'index']);
        Route::patch('comments/{comment}', [ComentarioModeracionController::class, 'update']);

        // Etapa 6: evaluación final, dashboard del instructor, revisión de resultados y exportación
        Route::get('courses/{course}/evaluacion-final', [EvaluacionController::class, 'estado']);
        Route::post('courses/{course}/evaluacion-final', [EvaluacionController::class, 'preparar']);
        Route::get('courses/{course}/tablero', [TableroController::class, 'grupo']);
        Route::get('courses/{course}/estudiantes/{enrollment}', [TableroController::class, 'estudiante']);
        Route::get('courses/{course}/resultados', [ResultadosController::class, 'show']);
        Route::post('courses/{course}/resultados/decision', [ResultadosController::class, 'decidir']);
        Route::get('courses/{course}/exportacion', [ResultadosController::class, 'exportar'])->middleware('throttle:6,1');

        Route::prefix('admin')->middleware('can:admin')->group(function () {
            Route::get('instructors', [InstructorController::class, 'index']);
            Route::post('instructors', [InstructorController::class, 'store']);
            Route::patch('instructors/{user}', [InstructorController::class, 'update']);
            Route::post('instructors/{user}/enlace-invitacion', [InstructorController::class, 'enlace']);
            Route::get('effect-rules', [EfectoController::class, 'reglas']);
            Route::put('effect-rules/{regla}', [EfectoController::class, 'actualizarRegla']);
            Route::put('agent-quotas/{user}', [AgenteController::class, 'actualizarCuota']);
        });
    });
});
