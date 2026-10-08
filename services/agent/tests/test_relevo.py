"""Conector del agente local con la plataforma en la nube (ADR 0008), con la plataforma y el agente simulados."""

import asyncio
import json

import httpx

from app.relevo import AjustesRelevo, atender, hilo

AJUSTES = AjustesRelevo(relevo_url="https://plataforma.test", agente_token="secreto", agente_local_url="http://agente.test",
                        relevo_hilos=1, relevo_espera=0)


def clientes(plataforma, agente) -> tuple[httpx.AsyncClient, httpx.AsyncClient]:
    return (httpx.AsyncClient(base_url="https://plataforma.test", headers={"X-Agente-Token": "secreto"},
                              transport=httpx.MockTransport(plataforma)),
            httpx.AsyncClient(transport=httpx.MockTransport(agente)))


async def test_reenvia_el_cuerpo_tal_cual_con_el_token_y_devuelve_la_respuesta():
    recibidas = []

    def agente(r: httpx.Request) -> httpx.Response:
        recibidas.append(r)
        return httpx.Response(200, text='{"errores":0,"semaforo":{}}')

    plataforma, local = clientes(lambda r: httpx.Response(500), agente)
    cuerpo = '{"diseno":{"tareas":[]},"codigo":{}}'
    estado, texto = await atender(plataforma, local, AJUSTES, {"id": "a1", "metodo": "POST", "ruta": "/v1/verificar", "cuerpo": cuerpo, "segundos": 60})

    assert (estado, texto) == (200, '{"errores":0,"semaforo":{}}')
    (r,) = recibidas
    assert str(r.url) == "http://agente.test/v1/verificar" and r.headers["X-Agente-Token"] == "secreto"
    assert r.content.decode() == cuerpo  # {} sigue siendo {}


async def test_el_documento_se_descarga_de_la_plataforma_y_sube_al_agente():
    def plataforma(r: httpx.Request) -> httpx.Response:
        assert r.url.path == "/api/v1/relevo/d1/archivo" and r.headers["X-Agente-Token"] == "secreto"
        return httpx.Response(200, content=b"%PDF-1.7 apuntes")

    def agente(r: httpx.Request) -> httpx.Response:
        assert r.url.path == "/v1/documentos/procesar" and b"%PDF-1.7 apuntes" in r.content and b'filename="apuntes.pdf"' in r.content
        return httpx.Response(200, json={"modelo": "bge-m3", "fragmentos": []})

    estado, texto = await atender(*clientes(plataforma, agente), AJUSTES,
                                  {"id": "d1", "metodo": "POST", "ruta": "/v1/documentos/procesar", "cuerpo": None, "archivo": "apuntes.pdf"})
    assert estado == 200 and json.loads(texto)["modelo"] == "bge-m3"


async def test_solo_reenvia_rutas_del_agente_y_explica_si_el_agente_esta_apagado():
    def apagado(_r: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("rechazada")

    plataforma, local = clientes(lambda r: httpx.Response(500), apagado)
    for ruta in ("/admin", "/v1/../salud", "http://otro.test/v1/generar"):
        assert (await atender(plataforma, local, AJUSTES, {"id": "x", "metodo": "POST", "ruta": ruta}))[0] == 400
    assert (await atender(plataforma, local, AJUSTES, {"id": "x", "metodo": "DELETE", "ruta": "/v1/generar"}))[0] == 400

    estado, texto = await atender(plataforma, local, AJUSTES, {"id": "x", "metodo": "GET", "ruta": "/v1/plantillas"})
    assert estado == 503 and "¿está encendido?" in json.loads(texto)["detail"]


async def test_un_ciclo_reclama_reenvia_y_responde():
    detener = asyncio.Event()
    respuestas = []
    pendientes = [{"id": "g1", "metodo": "GET", "ruta": "/v1/plantillas", "cuerpo": None, "archivo": None, "segundos": 20}]

    def plataforma(r: httpx.Request) -> httpx.Response:
        if r.url.path == "/api/v1/relevo/siguiente":
            return httpx.Response(200, json=pendientes.pop()) if pendientes else httpx.Response(204)
        respuestas.append((r.url.path, json.loads(r.content)))
        detener.set()  # tras la primera respuesta, el hilo termina
        return httpx.Response(204)

    plataforma_cliente, local = clientes(plataforma, lambda r: httpx.Response(200, json=[{"clave": "objetivos"}]))
    await asyncio.wait_for(hilo(1, plataforma_cliente, local, AJUSTES, detener), timeout=5)
    assert respuestas == [("/api/v1/relevo/g1/respuesta", {"estado": 200, "cuerpo": '[{"clave":"objetivos"}]'})]
