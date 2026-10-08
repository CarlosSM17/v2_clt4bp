<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentRelayRequest;
use App\Services\Agente\Relevo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El conector del agente local viene aquí por su trabajo (ADR 0008). Lo protege EsConectorAgente: sin el secreto del
 * agente, 403. Ningún usuario de la plataforma usa estas rutas.
 */
class RelevoController extends Controller
{
    /** GET /relevo/siguiente?espera=25 → la solicitud más antigua (200) o nada en ese plazo (204) */
    public function siguiente(Request $request, Relevo $relevo): JsonResponse|Response
    {
        $espera = min(max((int) $request->query('espera', 25), 0), 25);
        $solicitud = $relevo->reclamar($espera);
        if (! $solicitud) {
            return response()->noContent();
        }

        return response()->json([
            'id' => $solicitud->id,
            'metodo' => $solicitud->metodo,
            'ruta' => $solicitud->ruta,
            'cuerpo' => $solicitud->cuerpo, // texto JSON tal cual: el conector lo reenvía sin decodificarlo
            'archivo' => $solicitud->archivo ? $solicitud->archivo_nombre : null,
            'segundos' => max(1, (int) now()->diffInSeconds($solicitud->vence_at, false)),
        ]);
    }

    /** GET /relevo/{solicitud}/archivo → el documento de una solicitud que el conector ya reclamó */
    public function archivo(AgentRelayRequest $solicitud): StreamedResponse
    {
        abort_unless($solicitud->estado === 'reclamada' && $solicitud->archivo, 404);

        return Storage::disk('local')->download($solicitud->archivo, $solicitud->archivo_nombre);
    }

    /** POST /relevo/{solicitud}/respuesta {estado, cuerpo} → lo que respondió el agente, tal cual */
    public function responder(Request $request, AgentRelayRequest $solicitud, Relevo $relevo): Response
    {
        $datos = $request->validate([
            'estado' => ['required', 'integer', 'between:100,599'],
            'cuerpo' => ['present', 'nullable', 'string'],
        ]);
        abort_unless($solicitud->estado === 'reclamada', 409, 'La solicitud ya no espera respuesta.');
        $relevo->responder($solicitud, $datos['estado'], $datos['cuerpo'] ?? '');

        return response()->noContent();
    }
}
