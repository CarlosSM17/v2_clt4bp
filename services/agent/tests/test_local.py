"""Agente local: proveedor Ollama, material del curso (RAG) y sus endpoints. Sin Ollama real."""

import json
from pathlib import Path

import httpx
import pytest
from fastapi.testclient import TestClient
from pydantic import BaseModel

from app.config import Ajustes, ajustes
from app.ejecutor import Ejecucion, explicar_falla
from app.main import app, cliente_embeddings
from app.material import DocumentoIlegible, extraer, fragmentar
from app.plantillas import MAX_DISENO, PLANTILLAS, alcance_con_objetivos, mensaje_usuario, resumen_diseno, tema_clase
from app.proveedor import crear_proveedor
from app.proveedor.base import ProveedorNoDisponible, SalidaInvalida
from app.proveedor.ollama import ProveedorOllama
from app.solicitud import SolicitudGeneracion
from app.validacion import no_es_programa

EJEMPLO = Path(__file__).parents[3] / "packages/contracts/examples/diseno-curso/clase-recorridos.json"
TOKEN = {"X-Agente-Token": "secreto-de-prueba"}
SISTEMA = [{"type": "text", "text": "Rol"}, {"type": "text", "text": "Catálogo", "cache_control": {"type": "ephemeral"}}]


class Salida(BaseModel):
    titulo: str


def ollama(respuesta: dict | None = None, estado: int = 200, peticiones: list | None = None) -> ProveedorOllama:
    def manejar(peticion: httpx.Request) -> httpx.Response:
        if peticiones is not None:
            peticiones.append(json.loads(peticion.content))
        return httpx.Response(estado, json=respuesta or {"error": "model 'x' not found"})

    return ProveedorOllama("http://ollama", num_ctx=8192, transporte=httpx.MockTransport(manejar))


def chat(contenido: str, razon: str = "stop") -> dict:
    return {"model": "qwen3:8b", "message": {"role": "assistant", "content": contenido}, "done": True,
            "done_reason": razon, "prompt_eval_count": 1200, "eval_count": 300}


def pdf(paginas: list[str]) -> bytes:
    """PDF mínimo con una línea de texto por página (Helvetica, solo ASCII)."""
    objetos = {
        1: "<< /Type /Catalog /Pages 2 0 R >>",
        2: f"<< /Type /Pages /Kids [{' '.join(f'{4 + 2 * i} 0 R' for i in range(len(paginas)))}] /Count {len(paginas)} >>",
        3: "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    }
    for i, texto in enumerate(paginas):
        flujo = f"BT /F1 12 Tf 72 720 Td ({texto}) Tj ET"
        objetos[4 + 2 * i] = (f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
                              f"/Resources << /Font << /F1 3 0 R >> >> /Contents {5 + 2 * i} 0 R >>")
        objetos[5 + 2 * i] = f"<< /Length {len(flujo)} >>\nstream\n{flujo}\nendstream"
    salida, posiciones = b"%PDF-1.4\n", {}
    for k in sorted(objetos):
        posiciones[k] = len(salida)
        salida += f"{k} 0 obj\n{objetos[k]}\nendobj\n".encode("latin-1")
    xref, total = len(salida), max(objetos) + 1
    salida += f"xref\n0 {total}\n0000000000 65535 f \n".encode()
    salida += "".join(f"{posiciones[k]:010d} 00000 n \n" for k in range(1, total)).encode()
    return salida + f"trailer\n<< /Size {total} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()


# ---------- Proveedor Ollama ----------


async def test_ollama_restringe_la_salida_al_esquema_y_registra_el_uso():
    peticiones: list = []
    r = await ollama(chat('{"titulo": "Recorridos"}'), peticiones=peticiones).generar(
        SISTEMA, [{"role": "user", "content": "Propón"}], Salida, "qwen3:8b", 4000
    )

    assert r.objeto.titulo == "Recorridos"
    assert r.modelo == "ollama:qwen3:8b"
    assert (r.uso.entrada, r.uso.salida, r.uso.costo_usd) == (1200, 300, 0.0)
    cuerpo = peticiones[0]
    assert cuerpo["format"]["properties"]["titulo"]["type"] == "string"
    assert cuerpo["messages"][0] == {"role": "system", "content": "Rol\n\nCatálogo"}  # sin cache_control
    assert cuerpo["think"] is False and cuerpo["stream"] is False and cuerpo["keep_alive"] == "30m"
    assert cuerpo["options"] == {"num_ctx": 8192, "temperature": 0.3, "num_predict": 4000}


async def test_ollama_json_invalido_o_cortado_permite_corregir():
    with pytest.raises(SalidaInvalida) as e:
        await ollama(chat('{"otro": 1}')).generar(SISTEMA, [], Salida, "qwen3:8b", 100)
    assert "titulo" in e.value.detalle and e.value.texto == '{"otro": 1}'

    with pytest.raises(SalidaInvalida, match="longitud"):
        await ollama(chat('{"titulo": "Recor', razon="length")).generar(SISTEMA, [], Salida, "qwen3:8b", 100)

    # El modelo se quedó repitiendo y Ollama cortó: se reintenta como una respuesta cortada, no es un Ollama caído
    with pytest.raises(SalidaInvalida) as e:
        await ollama({"error": "prediction aborted, token repeat limit reached"}, estado=500).generar(SISTEMA, [], Salida, "qwen3:8b", 100)
    assert e.value.cortada


async def test_ollama_sin_modelo_o_apagado_no_esta_disponible():
    with pytest.raises(ProveedorNoDisponible, match="ollama pull qwen3:8b"):
        await ollama(estado=404).generar(SISTEMA, [], Salida, "qwen3:8b", 100)

    def caido(_: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("rechazada")

    apagado = ProveedorOllama("http://ollama", transporte=httpx.MockTransport(caido))
    with pytest.raises(ProveedorNoDisponible, match="no responde"):
        await apagado.generar(SISTEMA, [], Salida, "qwen3:8b", 100)

    def lento(_: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("sin respuesta")

    saturado = ProveedorOllama("http://ollama", timeout=900, transporte=httpx.MockTransport(lento))
    with pytest.raises(ProveedorNoDisponible, match="más de 900 s .*100% GPU"):
        await saturado.generar(SISTEMA, [], Salida, "qwen3:8b", 100)


async def test_la_salida_se_limita_a_lo_que_deja_libre_el_prompt():
    peticiones: list = []
    # 8 192 de contexto; ~3 000 tokens de prompt estimados (9 000 caracteres / 3); la plantilla pide 32 000
    await ollama(chat('{"titulo": "x"}'), peticiones=peticiones).generar(
        SISTEMA, [{"role": "user", "content": "a" * 9000}], Salida, "qwen3:8b", 32000
    )
    assert 8192 - 3100 < peticiones[0]["options"]["num_predict"] < 8192 - 3000

    p = ollama()
    assert p.presupuesto(["corto"], 4000) == 4000  # si cabe, se respeta el límite de la plantilla
    assert p.presupuesto(["x" * 60000], 4000) == 512  # prompt enorme: un mínimo, y Ollama corta con «length»


async def test_modelos_sin_razonamiento_no_reciben_el_campo_think():
    peticiones: list = []
    p = ollama(chat('{"titulo": "x"}'), peticiones=peticiones)
    p.pensar = None
    await p.generar(SISTEMA, [], Salida, "llama3.1:8b", 100)
    assert "think" not in peticiones[0]


def test_el_proveedor_decide_la_familia_de_modelos():
    local = Ajustes(proveedor="local", ollama_pensar="omitir")
    assert local.modelo("normal", "normal") == "qwen3:4b"
    assert local.modelo("ligero", "normal") == "qwen3:4b"  # el mismo: nunca se recarga entre plantillas
    assert local.modelo("normal", "alta") == "qwen3:8b"
    assert isinstance(crear_proveedor(local), ProveedorOllama) and local.pensar() is None

    claude = Ajustes(proveedor="claude", anthropic_api_key="")
    assert claude.modelo("ligero", "normal") == "claude-haiku-4-5"
    with pytest.raises(ProveedorNoDisponible, match="ANTHROPIC_API_KEY"):
        crear_proveedor(claude)


def test_una_explicacion_en_lugar_del_programa_se_senala_con_un_mensaje_claro():
    assert no_es_programa('#include <stdio.h>\nint main(void) { puts("hola"); }', "c", "t1") is None
    assert no_es_programa("n = int(input())\nprint(n * 2)", "python", "t1") is None

    v = no_es_programa("El acumulador empieza en cero para no arrastrar valores previos.", "c", "Promedio", "tc1-t1")
    assert v and v.bloqueante and v.elemento_uid == "tc1-t1"
    assert "programa completo en C" in v.detalle and "main" in v.detalle and "no una explicación" in v.detalle
    assert no_es_programa("Se recorre la lista y se suma.", "python", "ítem 2") is not None


async def test_la_falla_de_un_caso_se_explica_con_lo_que_imprimio_el_programa():
    class PistonFalso:
        async def ejecutar(self, _lenguaje, _codigo, entrada=""):
            return Ejecucion(True, "Total: 3\n" if entrada == "1 2" else "5\n", "", 0, False)

    casos = [{"entrada": "2 3", "salida_esperada": "5"}, {"entrada": "1 2", "salida_esperada": "3"}]
    assert await explicar_falla(PistonFalso(), "c", "x", casos) == (
        "Con la entrada '1 2' se esperaba '3' y el programa imprimió 'Total: 3'. "
        "Corrige la solución o la salida esperada, según cuál esté mal."
    )
    assert await explicar_falla(PistonFalso(), "c", "x", casos[:1]) is None


async def test_una_entrada_que_es_un_nombre_de_archivo_se_explica():
    class SinArchivo:
        async def ejecutar(self, _lenguaje, _codigo, entrada=""):
            return Ejecucion(True, "Error al abrir el archivo\n", "", 1, False)

    falla = await explicar_falla(SinArchivo(), "c", "x", [{"entrada": "archivo.txt", "salida_esperada": "60"}])
    assert "terminó con código 1: Error al abrir el archivo" in falla
    assert "no el nombre de un archivo" in falla and "vuelve a abrir" in falla

    # Con datos en la entrada, pero el programa abre un archivo que nunca creó
    otra = await explicar_falla(SinArchivo(), "c", "x", [{"entrada": "3\n10\n20", "salida_esperada": "60"}])
    assert "nombre de un archivo" not in otra
    assert "abrir un archivo que no existe" in otra and 'fopen("datos.txt", "w")' in otra


# ---------- Extracción y fragmentación ----------


def test_extrae_el_texto_de_cada_pagina_de_un_pdf():
    assert extraer("apuntes.pdf", pdf(["Un arreglo guarda datos", "Un ciclo for recorre"])) == [
        (1, "Un arreglo guarda datos"), (2, "Un ciclo for recorre"),
    ]
    assert extraer("notas.md", "# Punteros\n\nUn puntero guarda una dirección.".encode()) == [
        (None, "# Punteros\n\nUn puntero guarda una dirección."),
    ]


def test_un_documento_sin_texto_se_rechaza():
    with pytest.raises(DocumentoIlegible, match="escaneo"):
        extraer("escaneo.pdf", pdf([""]))
    with pytest.raises(DocumentoIlegible, match="PDF"):
        extraer("roto.pdf", b"no es un pdf")


def test_fragmentos_de_tamano_acotado_con_traslape_y_sin_cruzar_paginas():
    parrafo = " ".join(f"palabra{i}" for i in range(400))  # ~4 000 caracteres en un solo párrafo
    cortos = "\n\n".join(f"Párrafo {i} con algo de texto para agrupar." for i in range(30))
    fragmentos = fragmentar([(1, parrafo), (2, cortos)], tamano=800, traslape=150)

    assert [f.orden for f in fragmentos] == list(range(1, len(fragmentos) + 1))
    assert all(len(f.texto) <= 800 + 150 for f in fragmentos)
    assert {f.pagina for f in fragmentos} == {1, 2}
    primera = [f for f in fragmentos if f.pagina == 1]
    assert len(primera) >= 5
    for a, b in zip(primera, primera[1:], strict=False):
        assert a.texto.split()[-1] in b.texto.split()[:40]  # el siguiente retoma el final del anterior
    # Todo el texto sobrevive la fragmentación
    assert {w for f in primera for w in f.texto.split()} == set(parrafo.split())


def test_el_material_viaja_etiquetado_como_datos():
    diseno = json.loads(EJEMPLO.read_text(encoding="utf-8"))
    base = {"plantilla": "objetivos", "curso": {"titulo": "C", "lenguaje": "c", "nivel_educativo": "universidad"}, "diseno": diseno}

    sin = mensaje_usuario(PLANTILLAS["objetivos"], SolicitudGeneracion.model_validate(base))
    assert "<material_curso>" not in sin

    con = mensaje_usuario(PLANTILLAS["objetivos"], SolicitudGeneracion.model_validate({**base, "material": [
        {"documento": "Apuntes de C", "pagina": 3, "texto": "Un arreglo es una colección contigua."},
        {"documento": "Guía", "texto": "Usa nombres descriptivos."},
    ]}))
    assert "<material_curso>\n[M1] Apuntes de C, p. 3\nUn arreglo es una colección contigua.\n\n[M2] Guía\n" in con


def test_el_diseno_viaja_sin_los_pasos_de_las_trazas_y_acotado():
    # El trabajo 18 de «Programación I»: tres trazas calculadas en los enunciados = 20k tokens, y la respuesta se cortaba
    diseno = json.loads(EJEMPLO.read_text(encoding="utf-8"))
    paso = '{"l":5,"f":"main","v":[["n","3"]],"s":"","m":[{"f":"main","v":[{"n":"n","d":140,"t":"valor","x":"3","tam":4}]}]}'
    traza = f"```traza\ntitulo: Suma\nentrada: 3\n---\nint main(void) {{}}\n---\n{chr(10).join([paso] * 40)}\n```"
    diseno["tareas"][0]["enunciado_md"] += f"\n\n### Ejecución paso a paso\n\n{traza}\n"
    clase = diseno["tareas"][0]["clase_uid"]
    base = {"plantilla": "info_soporte", "curso": {"titulo": "C", "lenguaje": "c", "nivel_educativo": "universidad"},
            "diseno": diseno, "alcance": {"clase_uid": clase}}

    msg = mensaje_usuario(PLANTILLAS["info_soporte"], SolicitudGeneracion.model_validate(base))
    assert '{\\"l\\":5' not in msg  # los pasos no viajan
    assert "```traza\\ntitulo: Suma\\nentrada: 3\\n---\\nint main(void) {}\\n```" in msg  # encabezado y código sí

    # Una clase nueva solo ve los títulos de las tareas existentes: con sus enunciados, el modelo las copiaba
    nueva = SolicitudGeneracion.model_validate(base | {"plantilla": "clase_tareas", "alcance": {"objetivos": ["OB-1"]}})
    assert all("enunciado_md" not in t for t in json.loads(resumen_diseno(nueva))["tareas"])

    # Un curso grande: los enunciados se recortan para que quede espacio para la respuesta
    for t in diseno["tareas"]:
        t["enunciado_md"] = "Un enunciado muy largo. " * 2000
    resumen = resumen_diseno(SolicitudGeneracion.model_validate(base))
    assert len(resumen) <= MAX_DISENO and " …" in resumen
    assert json.loads(resumen)["tareas"][0]["uid"] == diseno["tareas"][0]["uid"]  # sigue siendo JSON válido


def test_una_clase_nueva_cierra_con_su_tema_las_indicaciones_y_lo_que_no_debe_repetir():
    # Trabajo 24: OB-1 ya lo cubre una clase; OB-2 (estructuras) es el tema nuevo
    diseno = json.loads(EJEMPLO.read_text(encoding="utf-8"))
    diseno["objetivos"].append(diseno["objetivos"][0] | {
        "uid": "ob2", "codigo": "OB-2", "orden": 2, "descripcion": "Estructuras de datos: declaración, acceso y anidación",
    })
    sol = SolicitudGeneracion.model_validate({
        "plantilla": "clase_tareas", "curso": {"titulo": "C", "lenguaje": "c", "nivel_educativo": "universidad"},
        "diseno": diseno, "alcance": {"orden": 2, "objetivos": ["OB-1", "OB-2"]}, "indicaciones": "Tema: estructuras",
    })
    tema = tema_clase(sol)
    assert tema.startswith("Tema de esta clase: OB-2, «Estructuras de datos: declaración, acceso y anidación».")
    assert "OB-1" not in tema  # ya lo cubre la clase existente
    assert "«Tema: estructuras»" in tema and f"«{diseno['clases'][0]['titulo']}»" in tema
    assert mensaje_usuario(PLANTILLAS["clase_tareas"], sol).endswith(tema)  # lo último que lee el modelo
    assert tema not in mensaje_usuario(PLANTILLAS["info_soporte"], sol.model_copy(update={"plantilla": "info_soporte"}))


def test_el_alcance_lleva_la_descripcion_de_sus_objetivos():
    diseno = json.loads(EJEMPLO.read_text(encoding="utf-8"))
    sol = SolicitudGeneracion.model_validate({
        "plantilla": "clase_tareas", "curso": {"titulo": "C", "lenguaje": "c", "nivel_educativo": "universidad"},
        "diseno": diseno, "alcance": {"orden": 2, "objetivos": ["OB-1", "ob1", "OB-9"]},
    })
    objetivos = alcance_con_objetivos(sol)["objetivos"]
    assert objetivos[0] == {"codigo": "OB-1", "descripcion": diseno["objetivos"][0]["descripcion"].strip()}
    assert objetivos[1]["codigo"] == "OB-1"  # citado por uid: igual se muestra su código
    assert objetivos[2] == "OB-9"  # uno que no existe se deja tal cual
    assert sol.alcance["objetivos"] == ["OB-1", "ob1", "OB-9"]  # la solicitud no cambia: solo el mensaje
    assert "Escribir programas en C que recorran" in mensaje_usuario(PLANTILLAS["clase_tareas"], sol).split("<alcance>")[1]


# ---------- Endpoints ----------


class EmbeddingsFalsos:
    modelo = "bge-m3"

    async def calcular(self, textos: list[str]) -> list[list[float]]:
        return [[float(len(t)), 1.0] for t in textos]


class OllamaApagado(EmbeddingsFalsos):
    async def calcular(self, textos: list[str]) -> list[list[float]]:
        raise ProveedorNoDisponible("Ollama no responde en http://ollama (ConnectError).")


@pytest.fixture
def cliente():
    app.dependency_overrides[ajustes] = lambda: Ajustes(agente_token="secreto-de-prueba")
    app.dependency_overrides[cliente_embeddings] = lambda: EmbeddingsFalsos()
    yield TestClient(app)
    app.dependency_overrides.pop(cliente_embeddings)


def test_procesar_un_documento_devuelve_fragmentos_con_su_vector(cliente):
    r = cliente.post("/v1/documentos/procesar", headers=TOKEN,
                     files={"archivo": ("apuntes.pdf", pdf(["Un arreglo guarda datos", "Un ciclo for recorre"]), "application/pdf")})

    assert r.status_code == 200
    assert r.json() == {"modelo": "bge-m3", "fragmentos": [
        {"orden": 1, "pagina": 1, "texto": "Un arreglo guarda datos", "embedding": [23.0, 1.0]},
        {"orden": 2, "pagina": 2, "texto": "Un ciclo for recorre", "embedding": [20.0, 1.0]},
    ]}


def test_endpoints_del_material_exigen_token_y_texto(cliente):
    assert cliente.post("/v1/documentos/procesar", files={"archivo": ("a.txt", b"hola")}).status_code == 401
    assert cliente.post("/v1/embeddings", json={"textos": ["hola"]}).status_code == 401
    vacio = cliente.post("/v1/documentos/procesar", headers=TOKEN, files={"archivo": ("vacio.txt", b"  \n ")})
    assert vacio.status_code == 422 and "texto" in vacio.json()["detail"]


def test_embeddings_de_la_consulta_y_ollama_apagado_es_503(cliente):
    r = cliente.post("/v1/embeddings", headers=TOKEN, json={"textos": ["arreglos en C", "punteros"]})
    assert r.json() == {"modelo": "bge-m3", "embeddings": [[13.0, 1.0], [8.0, 1.0]]}

    app.dependency_overrides[cliente_embeddings] = lambda: OllamaApagado()
    r = cliente.post("/v1/embeddings", headers=TOKEN, json={"textos": ["x"]})
    assert r.status_code == 503 and "Ollama" in r.json()["detail"]
