<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AssessmentAttempt;
use App\Models\Consent;
use App\Models\Enrollment;
use App\Models\InstrumentResponse;
use App\Models\ItemResponse;
use App\Models\LearningEvent;
use App\Models\StudentProfile;
use App\Models\TaskComment;
use App\Models\TaskProgress;
use App\Models\TaskSubmission;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * «Mis datos» (ASVS V14): la persona ve sus consentimientos, revoca el de investigación y descarga una copia
 * de todo lo que la plataforma guarda de ella. Revocar la saca de las exportaciones siguientes (Exportador, 6.5).
 */
class MisDatosController extends Controller
{
    /** El de privacidad no se revoca aquí: retirarlo equivale a pedir la baja de la cuenta. */
    private const REVOCABLES = ['investigacion'];

    public function index(Request $request): Response
    {
        return Inertia::render('cuenta/MisDatos', [
            'consentimientos' => $request->user()->consents()->latest('otorgado_at')->get()
                ->map(fn (Consent $c) => [
                    'id' => $c->id, 'tipo' => $c->tipo, 'version' => $c->version,
                    'otorgado_at' => $c->otorgado_at, 'revocado_at' => $c->revocado_at,
                    'revocable' => $c->revocado_at === null && in_array($c->tipo, self::REVOCABLES, true),
                ]),
            'contacto' => config('clt4bp.contacto_privacidad'),
        ]);
    }

    public function revocar(Request $request, Consent $consentimiento): RedirectResponse
    {
        abort_unless($consentimiento->user_id === $request->user()->id, 403);
        abort_unless($consentimiento->revocado_at === null && in_array($consentimiento->tipo, self::REVOCABLES, true), 422);

        $consentimiento->update(['revocado_at' => now()]);
        Auditoria::registrar('privacidad.revocar', $consentimiento, ['tipo' => $consentimiento->tipo]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Revocaste tu consentimiento: tus datos ya no se incluirán en las exportaciones.']);

        return back();
    }

    /** Copia en JSON de los datos de la persona, dentro de un ZIP. */
    public function descargar(Request $request): BinaryFileResponse
    {
        $usuario = $request->user();
        $inscripciones = Enrollment::with('course:id,titulo')->where('user_id', $usuario->id)->get();
        $ids = $inscripciones->pluck('id');
        $intentos = AssessmentAttempt::whereIn('enrollment_id', $ids)->get();

        $archivos = [
            'cuenta.json' => $usuario->only('name', 'email', 'institucion', 'created_at', 'email_verified_at'),
            'consentimientos.json' => $usuario->consents()->get(['tipo', 'version', 'otorgado_at', 'revocado_at']),
            'inscripciones.json' => $inscripciones->map(fn (Enrollment $e) => [
                'curso' => $e->course->titulo, 'seudonimo' => $e->seudonimo,
                'estado' => $e->estado, 'inscrito_at' => $e->inscrito_at,
            ]),
            'perfiles.json' => StudentProfile::whereIn('enrollment_id', $ids)->get(),
            'cuestionarios.json' => InstrumentResponse::whereIn('enrollment_id', $ids)->get(),
            'pruebas.json' => $intentos,
            // Sin «detalle»: puede traer salidas esperadas de casos de prueba que siguen en uso
            'respuestas_pruebas.json' => ItemResponse::whereIn('assessment_attempt_id', $intentos->pluck('id'))
                ->get(['assessment_attempt_id', 'item_id', 'respuesta', 'fraccion', 'created_at']),
            'avance_tareas.json' => TaskProgress::whereIn('enrollment_id', $ids)->get(),
            'envios.json' => TaskSubmission::whereIn('enrollment_id', $ids)->get(),
            'eventos.json' => LearningEvent::whereIn('enrollment_id', $ids)->orderBy('ocurrido_at')->get(),
            'comentarios.json' => TaskComment::where('user_id', $usuario->id)->get(['course_id', 'tarea_uid', 'texto', 'oculto', 'created_at']),
        ];

        $ruta = tempnam(sys_get_temp_dir(), 'misdatos');
        $zip = new ZipArchive();
        if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo.');
        }
        foreach ($archivos as $nombre => $datos) {
            $zip->addFromString($nombre, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $zip->addFromString('LEEME.txt', 'Copia de tus datos en la plataforma CLT4BP, generada el '.now()->toIso8601String().".\n"
            .'Cada archivo .json es una tabla. Para corregir o cancelar tus datos escribe a: '.config('clt4bp.contacto_privacidad')."\n");
        $zip->close();
        Auditoria::registrar('privacidad.descarga', $usuario);

        return response()->download($ruta, 'mis-datos-clt4bp.zip', ['ContentType' => 'application/zip'])->deleteFileAfterSend();
    }
}
