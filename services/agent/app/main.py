from dataclasses import asdict
from functools import lru_cache
from typing import Any, Literal

import anthropic
import sentry_sdk
from fastapi import Depends, FastAPI, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from .config import Ajustes, ajustes
from .conocimiento import VERSION
from .ejecutor import EjecutorPiston
from .estadisticas import (
    Ancova, Correlacion, DosGrupos, PrePost, SolicitudAncova, SolicitudCorrelacion, SolicitudDosGrupos,
    SolicitudPrePost, ancova, correlacion, dos_grupos, pre_post,
)
from .material import ClienteEmbeddings, DocumentoIlegible, extraer, fragmentar
from .orquestador import Orquestador, PlantillaDesconocida
from .plantillas import PLANTILLAS
from .proveedor import ProveedorNoDisponible, crear_proveedor
from .seguridad import exigir_token
from .solicitud import ResultadoGeneracion, SolicitudGeneracion
from .trazador import ClienteTrazador
from .verificador.modelos import Informe, SolicitudVerificacion
from .verificador.reglas import REGLAS, verificar

# Errores al monitoreo (7.5), sin datos personales ni cuerpos de petición: ahí viajan diseños y resultados
if ajustes().sentry_dsn:
    sentry_sdk.init(dsn=ajustes().sentry_dsn, send_default_pii=False,
                     max_request_body_size="never", traces_sample_rate=0.1, environment="produccion")

app = FastAPI(title="Agente CLT4BP", version="0.4.0")


@lru_cache
def orquestador() -> Orquestador:
    a = ajustes()
    return Orquestador(crear_proveedor(a), EjecutorPiston(a.piston_url), a, ClienteTrazador(a.trazador_url))


@lru_cache
def cliente_trazador() -> ClienteTrazador:
    return ClienteTrazador(ajustes().trazador_url)


@lru_cache
def cliente_embeddings() -> ClienteEmbeddings:
    """Los embeddings del material siempre se calculan en local, sea cual sea el proveedor."""
    a = ajustes()
    return ClienteEmbeddings(a.ollama_url, a.modelo_embeddings, keep_alive=a.ollama_keep_alive)


@app.exception_handler(ProveedorNoDisponible)
async def proveedor_no_disponible(_: Request, e: ProveedorNoDisponible) -> JSONResponse:
    # 503: Laravel lo trata como transitorio y la cola reintenta con espera
    return JSONResponse(status_code=503, content={"detail": str(e)})


@app.get("/salud")
def salud() -> dict[str, str]:
    # La versión del prompt dice qué código atiende: en Windows, la recarga de «fastapi dev» puede quedarse colgada y
    # el proceso viejo sigue respondiendo (pasó con sistema-v9 cuando el código ya era v10)
    return {"estado": "ok", "proveedor": ajustes().proveedor, "prompt": VERSION}


@app.get("/v1/verificador/reglas", dependencies=[Depends(exigir_token)])
def reglas() -> list[dict[str, str]]:
    return [{"clave": k, "nivel": n, "descripcion": d} for k, (n, d, _) in REGLAS.items()]


@app.post("/v1/verificar", dependencies=[Depends(exigir_token)])
def verificar_diseno(solicitud: SolicitudVerificacion) -> Informe:
    return verificar(solicitud)


@app.get("/v1/plantillas", dependencies=[Depends(exigir_token)])
def plantillas() -> list[dict[str, str | int]]:
    return [{"clave": p.clave, "paso": p.paso, "titulo": p.titulo, "modelo": p.nivel_modelo} for p in PLANTILLAS.values()]


@app.post("/v1/generar", dependencies=[Depends(exigir_token)])
async def generar(solicitud: SolicitudGeneracion, orq: Orquestador = Depends(orquestador)) -> ResultadoGeneracion:
    try:
        return await orq.generar(solicitud)
    except PlantillaDesconocida as e:
        raise HTTPException(422, str(e)) from e
    except anthropic.RateLimitError as e:
        raise HTTPException(429, "Límite de uso de la API alcanzado; se reintentará más tarde.") from e
    except (anthropic.APIConnectionError, anthropic.InternalServerError) as e:
        raise HTTPException(503, f"Claude no está disponible: {e.__class__.__name__}") from e
    except anthropic.APIStatusError as e:
        raise HTTPException(502, f"Error de la API de Claude ({e.status_code}).") from e


# ---------- Material del curso (RAG): el agente fragmenta y calcula vectores; Laravel los guarda ----------


@app.post("/v1/documentos/procesar", dependencies=[Depends(exigir_token)])
async def procesar_documento(archivo: UploadFile, emb: ClienteEmbeddings = Depends(cliente_embeddings)) -> dict[str, Any]:
    try:
        fragmentos = fragmentar(extraer(archivo.filename or "", await archivo.read()))
    except DocumentoIlegible as e:
        raise HTTPException(422, str(e)) from e
    vectores = await emb.calcular([f.texto for f in fragmentos])
    return {
        "modelo": emb.modelo,
        "fragmentos": [{**asdict(f), "embedding": v} for f, v in zip(fragmentos, vectores, strict=True)],
    }


class SolicitudEmbeddings(BaseModel):
    textos: list[str] = Field(min_length=1, max_length=64)


@app.post("/v1/embeddings", dependencies=[Depends(exigir_token)])
async def embeddings(solicitud: SolicitudEmbeddings, emb: ClienteEmbeddings = Depends(cliente_embeddings)) -> dict[str, Any]:
    return {"modelo": emb.modelo, "embeddings": await emb.calcular(solicitud.textos)}


# ---------- Trazas de código: la consola pide los pasos reales de una traza que escribió el instructor ----------


class SolicitudTraza(BaseModel):
    lenguaje: Literal["c", "cpp", "python"]
    bloque: str = Field(max_length=30_000)  # el contenido del bloque ```traza (encabezado, ---, código)


@app.post("/v1/trazas/completar", dependencies=[Depends(exigir_token)])
async def completar_traza(solicitud: SolicitudTraza, trazador: ClienteTrazador = Depends(cliente_trazador)) -> dict[str, str]:
    bloque, error = await trazador.completar(solicitud.bloque, solicitud.lenguaje)
    if error:
        raise HTTPException(422, f"No se pudo trazar: {error}")
    return {"bloque": bloque}


# ---------- Estadísticos del paso 10 (Etapa 6): sin datos personales, solo números en orden ----------


@app.post("/v1/estadisticas/pre-post", dependencies=[Depends(exigir_token)])
def estadisticas_pre_post(solicitud: SolicitudPrePost) -> dict[str, PrePost]:
    return {clave: pre_post(serie) for clave, serie in solicitud.series.items()}


@app.post("/v1/estadisticas/dos-grupos", dependencies=[Depends(exigir_token)])
def estadisticas_dos_grupos(solicitud: SolicitudDosGrupos) -> DosGrupos:
    return dos_grupos(solicitud)


@app.post("/v1/estadisticas/ancova", dependencies=[Depends(exigir_token)])
def estadisticas_ancova(solicitud: SolicitudAncova) -> Ancova:
    return ancova(solicitud)


@app.post("/v1/estadisticas/correlacion", dependencies=[Depends(exigir_token)])
def estadisticas_correlacion(solicitud: SolicitudCorrelacion) -> Correlacion:
    return correlacion(solicitud)
