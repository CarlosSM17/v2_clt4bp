<?php

namespace App\Services\Agente;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Única puerta hacia el servicio del agente (FastAPI). Solo Laravel lo llama: directo (AGENTE_URL) o, si el agente
 * corre en el equipo del instructor y la plataforma en la nube, por el relevo (AGENTE_MODO=relevo, ADR 0008).
 */
class ClienteAgente
{
    public function __construct(private readonly Relevo $relevo) {}

    private function http(int $timeout = 30): PendingRequest
    {
        return Http::baseUrl(config('services.agente.url'))
            ->withHeaders(['X-Agente-Token' => config('services.agente.token')])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout);
    }

    /**
     * Una llamada al agente por el camino configurado. `$interactivo`: alguien espera en pantalla; por el relevo, sin
     * conector falla en el acto en lugar de esperar todo el plazo.
     *
     * @param  array<string, mixed>|null  $cuerpo
     * @param  array{0: resource, 1: string}|null  $archivo
     */
    private function pedir(string $metodo, string $ruta, ?array $cuerpo = null, int $timeout = 30, bool $interactivo = true,
        int $reintentos = 0, ?array $archivo = null): Response
    {
        if (config('services.agente.modo') === 'relevo') {
            return $this->relevo->enviar($metodo, $ruta, $cuerpo, $timeout, $archivo, $interactivo);
        }
        $http = $this->http($timeout);
        if ($reintentos) {
            $http = $http->retry($reintentos, 500, throw: false);
        }
        if ($archivo) {
            $http = $http->attach('archivo', $archivo[0], $archivo[1]);
        }

        return $metodo === 'GET' ? $http->get($ruta) : $http->post($ruta, $cuerpo ?? []);
    }

    /**
     * @param  array<string, mixed>  $diseno  fotografía DisenoCurso
     * @param  array<string, array{aprobados: int, total: int, error_compilacion: ?string}>  $codigo
     * @return array{hallazgos: list<array>, errores: int, advertencias: int, semaforo: array<string, string>}
     */
    public function verificar(array $diseno, array $codigo): array
    {
        return $this->pedir('POST', '/v1/verificar', ['diseno' => $diseno, 'codigo' => (object) $codigo], reintentos: 2)
            ->throw()
            ->json();
    }

    /** @return list<array{clave: string, paso: int, titulo: string, modelo: string}> */
    public function plantillas(): array
    {
        return $this->pedir('GET', '/v1/plantillas')->throw()->json();
    }

    /**
     * Generación: puede tardar varios minutos. Sin reintentos aquí: los maneja la cola. Por el relevo, espera a que el
     * conector la recoja (si el agente local se enciende después, la propuesta sigue su curso).
     * Devuelve objetos (no arreglos) para que {} y [] lleguen intactos a la consola.
     */
    public function generar(array $solicitud): object
    {
        return $this->pedir('POST', '/v1/generar', $solicitud, (int) config('services.agente.timeout', 280), interactivo: false)
            ->throw()->object();
    }

    /**
     * Material del curso (RAG): el agente extrae el texto, lo fragmenta y calcula los embeddings en local.
     *
     * @param  resource  $archivo
     * @return array{modelo: string, fragmentos: list<array{orden: int, pagina: ?int, texto: string, embedding: list<float>}>}
     */
    public function procesarDocumento($archivo, string $nombre): array
    {
        return $this->pedir('POST', '/v1/documentos/procesar', timeout: 600, interactivo: false, archivo: [$archivo, $nombre])
            ->throw()->json();
    }

    /**
     * Pasos reales de una traza de código: el agente la ejecuta con el trazador (gdb) y devuelve el bloque completo.
     *
     * @param  string  $bloque  contenido del bloque ```traza (encabezado, ---, código)
     */
    public function completarTraza(string $lenguaje, string $bloque): string
    {
        return $this->pedir('POST', '/v1/trazas/completar', ['lenguaje' => $lenguaje, 'bloque' => $bloque], 90)
            ->throw()->json('bloque');
    }

    /**
     * @param  list<string>  $textos
     * @return list<list<float>>
     */
    public function embeddings(array $textos): array
    {
        // Parte de una generación (el material para el agente): espera al conector como la generación misma
        return $this->pedir('POST', '/v1/embeddings', ['textos' => $textos], 60, interactivo: false, reintentos: 2)
            ->throw()->json('embeddings');
    }

    /**
     * Estadísticos del paso 10 (Etapa 6). $prueba: pre-post, dos-grupos, ancova o correlacion.
     * Solo viajan números en orden: ni nombres ni seudónimos.
     */
    public function estadisticas(string $prueba, array $datos): array
    {
        return $this->pedir('POST', "/v1/estadisticas/{$prueba}", $datos, reintentos: 2)->throw()->json();
    }
}
