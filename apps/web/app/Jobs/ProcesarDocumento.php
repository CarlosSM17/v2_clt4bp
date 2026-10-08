<?php

namespace App\Jobs;

use App\Models\CourseDocument;
use App\Models\DocumentFragment;
use App\Services\Agente\ClienteAgente;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Indexa un documento del curso: el agente lo fragmenta y calcula los vectores; aquí se guardan en pgvector. */
class ProcesarDocumento implements ShouldQueue
{
    use Queueable;

    public int $timeout = 660; // > timeout HTTP del procesamiento (600 s)

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $documentoId)
    {
        $this->onQueue('agente');
    }

    public function handle(ClienteAgente $agente): void
    {
        $documento = CourseDocument::findOrFail($this->documentoId);
        $archivo = Storage::disk('local')->readStream($documento->ruta);

        try {
            $r = $agente->procesarDocumento($archivo, $documento->nombre_original);
        } catch (RequestException $e) {
            if ($e->response->status() === 422) { // sin texto extraíble, PDF dañado: reintentar no lo arregla
                $documento->update(['estado' => 'error', 'error' => $e->response->json('detail') ?? 'Documento ilegible.']);

                return;
            }
            throw $e; // 503 (Ollama apagado o sin el modelo): la cola reintenta con espera
        } finally {
            is_resource($archivo) && fclose($archivo);
        }

        DB::transaction(function () use ($documento, $r) {
            DocumentFragment::where('course_document_id', $documento->id)->delete(); // reintento: sin duplicados
            $ahora = now();
            foreach (array_chunk($r['fragmentos'], 200) as $lote) {
                DocumentFragment::insert(array_map(fn (array $f) => [
                    'course_document_id' => $documento->id,
                    'course_id' => $documento->course_id,
                    'orden' => $f['orden'],
                    'pagina' => $f['pagina'],
                    'texto' => $f['texto'],
                    'embedding' => json_encode($f['embedding']), // «[x, y, …]»: el formato de texto de pgvector
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ], $lote));
            }
            $documento->update([
                'estado' => 'listo', 'error' => null,
                'fragmentos' => count($r['fragmentos']), 'modelo_embeddings' => $r['modelo'],
            ]);
        });
    }

    public function failed(?Throwable $e): void
    {
        CourseDocument::whereKey($this->documentoId)->update([
            'estado' => 'error',
            'error' => 'No se pudo procesar: el agente o Ollama no respondieron. Elimínalo y súbelo de nuevo.',
        ]);
    }
}
