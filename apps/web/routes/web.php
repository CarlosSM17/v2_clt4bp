<?php

use App\Http\Controllers\Web\InicioController;
use App\Http\Controllers\Web\AulaController;
use App\Http\Controllers\Web\AvanceController;
use App\Http\Controllers\Web\AvisoController;
use App\Http\Controllers\Web\ComentarioController;
use App\Http\Controllers\Web\CursoController;
use App\Http\Controllers\Web\DiagnosticoController;
use App\Http\Controllers\Web\EventoController;
use App\Http\Controllers\Web\EvaluacionFinalController;
use App\Http\Controllers\Web\InvitacionController;
use App\Http\Controllers\Web\MisDatosController;
use App\Http\Controllers\Web\TareaController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Textos legales (públicos)
Route::inertia('legal/privacidad', 'legal/Privacidad')->name('legal.privacidad');
Route::inertia('legal/investigacion', 'legal/Investigacion')->name('legal.investigacion');

// Invitación de instructores: enlace firmado con vencimiento
Route::middleware(['guest', 'signed'])->group(function () {
    Route::get('invitacion/{user}', [InvitacionController::class, 'show'])->name('invitacion.show');
    Route::post('invitacion/{user}', [InvitacionController::class, 'store'])->name('invitacion.store');
});

Route::middleware(['auth', 'verified', 'no-suspendido', 'personal-2fa'])->group(function () {
    Route::get('dashboard', InicioController::class)->name('dashboard');

    Route::get('avisos', [AvisoController::class, 'index'])->name('avisos.index');
    Route::post('avisos/leidos', [AvisoController::class, 'leidos'])->name('avisos.leidos');

    // Etapa 7: derechos sobre los datos personales
    Route::get('mis-datos', [MisDatosController::class, 'index'])->name('mis-datos');
    Route::post('mis-datos/consentimientos/{consentimiento}/revocar', [MisDatosController::class, 'revocar'])->name('mis-datos.revocar');
    Route::get('mis-datos/descarga', [MisDatosController::class, 'descargar'])->middleware('throttle:3,60')->name('mis-datos.descarga');

    Route::get('cursos', [CursoController::class, 'index'])->name('cursos.index');
    Route::post('cursos/solicitar', [CursoController::class, 'solicitar'])
        ->middleware('throttle:10,1')->name('cursos.solicitar');
    Route::get('cursos/{curso}', [CursoController::class, 'show'])->name('cursos.show');

    // Diagnóstico inicial del estudiante
    Route::get('cursos/{curso}/diagnostico', [DiagnosticoController::class, 'index'])->name('diagnostico.index');
    Route::get('cursos/{curso}/cuestionarios/{aplicacion}', [DiagnosticoController::class, 'cuestionario'])
        ->name('diagnostico.cuestionario');
    Route::post('cursos/{curso}/cuestionarios/{aplicacion}/respuestas', [DiagnosticoController::class, 'guardarCuestionario']);
    Route::post('cursos/{curso}/cuestionarios/{aplicacion}/completar', [DiagnosticoController::class, 'completarCuestionario']);
    Route::get('cursos/{curso}/pruebas/{prueba}', [DiagnosticoController::class, 'prueba'])->name('diagnostico.prueba');
    Route::post('intentos/{intento}/respuestas', [DiagnosticoController::class, 'guardarRespuesta']);
    Route::post('intentos/{intento}/ejecutar', [DiagnosticoController::class, 'ejecutar'])->middleware('throttle:20,1');
    Route::post('intentos/{intento}/enviar', [DiagnosticoController::class, 'enviar']);

    // Etapa 5: reproductor 4C/ID
    Route::prefix('aula/{curso}')->middleware('can:acceder,curso')->group(function () {
        Route::get('/', [AulaController::class, 'mapa'])->name('aula.mapa');
        Route::get('avance', [AvanceController::class, 'show'])->name('aula.avance'); // Etapa 6
        Route::get('clases/{clase}', [AulaController::class, 'clase'])->name('aula.clase');
        Route::get('tareas/{tarea}', [AulaController::class, 'tarea'])->name('aula.tarea');
        Route::get('practica', [AulaController::class, 'practica'])->name('aula.practica');
        Route::post('eventos', [EventoController::class, 'store'])->middleware('throttle:60,1');
        Route::post('tareas/{tarea}/borrador', [TareaController::class, 'borrador']);
        Route::post('tareas/{tarea}/ejecutar', [TareaController::class, 'ejecutar'])->middleware('throttle:30,1');
        Route::post('tareas/{tarea}/envios', [TareaController::class, 'enviar'])->middleware('throttle:6,1');
        Route::post('tareas/{tarea}/completar', [TareaController::class, 'completar']);
        Route::post('tareas/{tarea}/esfuerzo', [TareaController::class, 'esfuerzo']);
        Route::post('practica/{practica}/{ejercicio}', [TareaController::class, 'comprobarPractica'])
            ->whereNumber('ejercicio')->middleware('throttle:20,1');
        Route::post('tareas/{tarea}/comentarios', [ComentarioController::class, 'store'])->middleware('throttle:10,1');
    });
    Route::get('aula/medios/{curso}/{uid}', [AulaController::class, 'medio'])->name('aula.medio')->middleware('signed');
    Route::get('aula/envios/{envio}', [TareaController::class, 'envio']);
    Route::delete('aula/comentarios/{comentario}', [ComentarioController::class, 'destroy']);

    // Etapa 6: evaluación final
    Route::get('cursos/{curso}/evaluacion-final', [EvaluacionFinalController::class, 'index'])
        ->middleware('can:acceder,curso')->name('evaluacion.final');
});

require __DIR__.'/settings.php';
