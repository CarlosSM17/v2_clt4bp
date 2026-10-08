import copy
import json
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app import validacion
from app.conocimiento import VERSION
from app.config import Ajustes, ajustes
from app.main import app, orquestador
from app.orquestador import Orquestador
from app.proveedor.base import SalidaInvalida, Uso
from app.proveedor.falso import ProveedorFalso
from app.solicitud import SolicitudGeneracion

EJEMPLO = Path(__file__).parents[3] / "packages/contracts/examples/diseno-curso/clase-recorridos.json"
# Sin las solicitudes de seguimiento de la clase (tienen su propia prueba en test_etapas.py)
AJUSTES = Ajustes(agente_token="secreto-de-prueba", proveedor="claude", anthropic_api_key="", max_intentos=3, clase_completa=False,
                  tareas_complementarias=False)


@pytest.fixture
def diseno() -> dict:
    return json.loads(EJEMPLO.read_text(encoding="utf-8"))


@pytest.fixture(autouse=True)
def sin_piston(monkeypatch):
    """Piston falso: el código con la marca MAL falla todos sus casos; el resto los pasa."""

    async def probar(_ejecutor, _lenguaje, codigo, casos):
        return (0, len(casos), None) if "MAL" in codigo else (len(casos), len(casos), None)

    async def explicar_falla(_ejecutor, _lenguaje, codigo, casos):
        return "Con la entrada '1' se esperaba '2' y el programa imprimió '3'." if "MAL" in codigo else None

    monkeypatch.setattr(validacion, "probar", probar)
    monkeypatch.setattr(validacion, "explicar_falla", explicar_falla)


def solicitud(diseno: dict, plantilla: str, **alcance) -> SolicitudGeneracion:
    return SolicitudGeneracion.model_validate({
        "plantilla": plantilla,
        "curso": {"titulo": "Programación en C", "lenguaje": "c", "nivel_educativo": "universidad"},
        "diseno": diseno,
        "grupos": [{"clave": "G1", "nombre": "Ruta A", "nivel": "basico", "resumen": {"n": 14}}],
        "alcance": {"prefijo": "job42", **alcance},
    })


def propuesta_clase(diseno: dict, mal: bool = False) -> dict:
    """Una clase «como la que devolvería Claude»: uid inventados que el orquestador debe reemplazar."""
    tareas = copy.deepcopy(diseno["tareas"])
    for i, t in enumerate(tareas, start=1):
        t["uid"], t["clase_uid"] = f"t{i}", "clase-nueva"
        t["enunciado_md"] = f"Variación {i}: " + t["enunciado_md"]
        t["titulo"] = f"{t['titulo']} con condiciones"  # una clase nueva no repite títulos de las existentes
    if mal:
        tareas[2]["solucion"] = "/* MAL */ int main(void) { return 0; }"
    proc = copy.deepcopy(diseno["procedimental"][0]) | {"uid": "p1", "tarea_uid": "t2"}
    return {
        "advertencias": [],
        "clase": copy.deepcopy(diseno["clases"][0]) | {"uid": "clase-nueva", "titulo": "Recorridos con condiciones"},
        "tareas": tareas,
        "soporte": [copy.deepcopy(diseno["soporte"][0]) | {"uid": "s1", "clase_uid": "clase-nueva"}],
        "procedimental": [proc],
    }


async def test_clase_valida_en_un_intento(diseno):
    proveedor = ProveedorFalso([propuesta_clase(diseno)])
    r = await Orquestador(proveedor, None, AJUSTES).generar(solicitud(diseno, "clase_tareas", objetivos=["OB-1"]))

    assert r.intentos == 1
    assert [e.tipo for e in r.elementos] == ["clase", "tarea", "tarea", "tarea", "soporte", "procedimental"]
    clase, *tareas = [e.contenido for e in r.elementos[:4]]
    assert clase["uid"] == "job42-tc" and clase["orden"] == 2
    assert [t["uid"] for t in tareas] == ["job42-tc-t1", "job42-tc-t2", "job42-tc-t3"]
    assert {t["clase_uid"] for t in tareas} == {"job42-tc"}
    assert r.elementos[-1].contenido["tarea_uid"] == "job42-tc-t2"  # la ayuda sigue a su tarea
    assert not [v for v in r.validaciones if not v.ok and v.bloqueante]
    assert r.uso.entrada == 1000 and r.version_prompt == VERSION

    llamada = proveedor.llamadas[0]
    assert llamada["sistema"][-1]["cache_control"] == {"type": "ephemeral"}
    assert "<alcance>" in llamada["mensajes"][0]["content"]


async def test_corrige_una_solucion_que_falla(diseno):
    proveedor = ProveedorFalso([propuesta_clase(diseno, mal=True), propuesta_clase(diseno)])
    r = await Orquestador(proveedor, None, AJUSTES).generar(solicitud(diseno, "clase_tareas"))

    assert r.intentos == 2 and r.uso.entrada == 2000
    segunda = proveedor.llamadas[1]["mensajes"]
    assert [m["role"] for m in segunda] == ["user", "assistant", "user"]
    assert "0 de 2" in segunda[-1]["content"]
    # El modelo ve qué salió mal, no solo cuántos casos fallaron
    assert "se esperaba '2' y el programa imprimió '3'" in segunda[-1]["content"]


async def test_un_problema_convencional_sin_casos_ocultos_oculta_el_ultimo(diseno):
    propuesta = propuesta_clase(diseno)
    for c in propuesta["tareas"][2]["casos_prueba"]:  # t3 es convencional
        c["oculto"] = False
    r = await Orquestador(ProveedorFalso([propuesta]), None, AJUSTES).generar(solicitud(diseno, "clase_tareas"))

    assert r.intentos == 1  # sin gastar una corrección en algo mecánico
    t3 = next(e.contenido for e in r.elementos if e.contenido.get("uid") == "job42-tc-t3")
    assert [c["oculto"] for c in t3["casos_prueba"]] == [False, True]
    assert any("se marcó como oculto el último caso" in a for a in r.advertencias)


async def test_si_todos_los_casos_son_ocultos_el_primero_se_hace_visible(diseno):
    propuesta = propuesta_clase(diseno)
    for c in propuesta["tareas"][2]["casos_prueba"]:
        c["oculto"] = True
    r = await Orquestador(ProveedorFalso([propuesta]), None, AJUSTES).generar(solicitud(diseno, "clase_tareas"))

    assert r.intentos == 1
    t3 = next(e.contenido for e in r.elementos if e.contenido.get("uid") == "job42-tc-t3")
    assert [c["oculto"] for c in t3["casos_prueba"]] == [False, True]
    assert any("se hizo visible el primer caso" in a for a in r.advertencias)


async def test_una_tarea_con_un_solo_caso_pide_mas(diseno):
    propuesta = propuesta_clase(diseno)
    propuesta["tareas"][0]["casos_prueba"] = propuesta["tareas"][0]["casos_prueba"][:1]
    proveedor = ProveedorFalso([propuesta, propuesta_clase(diseno)])
    r = await Orquestador(proveedor, None, AJUSTES).generar(solicitud(diseno, "clase_tareas"))

    assert r.intentos == 2
    assert "al menos 3 casos de prueba con datos distintos (tiene 1)" in proveedor.llamadas[1]["mensajes"][-1]["content"]


async def test_respuesta_fuera_del_esquema_se_reintenta(diseno):
    proveedor = ProveedorFalso(['{"clase": {}}', propuesta_clase(diseno)])
    r = await Orquestador(proveedor, None, AJUSTES).generar(solicitud(diseno, "clase_tareas"))

    assert r.intentos == 2
    assert "no cumple el esquema" in proveedor.llamadas[1]["mensajes"][-1]["content"]


async def test_cada_correccion_lleva_solo_la_ultima_respuesta(diseno):
    proveedor = ProveedorFalso([propuesta_clase(diseno, mal=True) for _ in range(3)])
    await Orquestador(proveedor, None, AJUSTES).generar(solicitud(diseno, "clase_tareas"))

    tercera = proveedor.llamadas[2]["mensajes"]
    assert [m["role"] for m in tercera] == ["user", "assistant", "user"]  # no crece con cada intento
    assert tercera[0] == proveedor.llamadas[0]["mensajes"][0]


async def test_una_respuesta_cortada_no_vuelve_al_contexto_ni_borra_los_problemas(diseno):
    class Cortado(ProveedorFalso):
        async def generar(self, sistema, mensajes, salida, modelo, max_tokens):
            if len(self.llamadas) == 1:  # el segundo intento se corta por longitud
                self.llamadas.append({"mensajes": [dict(m) for m in mensajes]})
                raise SalidaInvalida("La respuesta se cortó por longitud.", '{"clase": {"titulo": "TRUNCADO-XYZ', Uso(), cortada=True)
            return await super().generar(sistema, mensajes, salida, modelo, max_tokens)

    proveedor = Cortado([propuesta_clase(diseno, mal=True), propuesta_clase(diseno, mal=True)])
    r = await Orquestador(proveedor, None, AJUSTES).generar(solicitud(diseno, "clase_tareas"))

    tercera = proveedor.llamadas[2]["mensajes"]
    assert "TRUNCADO-XYZ" not in json.dumps(tercera)  # el texto truncado no se reenvía
    assert "más conciso" in tercera[-1]["content"] and "0 de 2" in tercera[-1]["content"]
    assert r.intentos == 3
    assert any(v.nombre == "verificador:codigo_verificado" and not v.ok for v in r.validaciones)


async def test_se_rinde_y_lo_dice(diseno):
    proveedor = ProveedorFalso([propuesta_clase(diseno, mal=True) for _ in range(3)])
    r = await Orquestador(proveedor, None, AJUSTES).generar(solicitud(diseno, "clase_tareas"))

    assert r.intentos == 3
    assert any("sin resolver" in a for a in r.advertencias)
    assert any(v.nombre == "verificador:codigo_verificado" and not v.ok for v in r.validaciones)


async def test_variantes_solo_con_lo_que_cambia(diseno):
    cambios = {
        "enunciado_md": "",
        "nivel_apoyo": "por_completar",
        "codigo_inicial": "int main(void) {\n  /* HUECO 1: cuenta */\n}",
        "arcs_confianza": "",
    }
    respuesta = {
        "advertencias": [],
        "planes": [{"grupo_clave": "G1", "contenido": "igual", "proceso": "más andamiaje", "producto": "igual"}],
        "variantes": [
            {"tarea_uid": "tc1-t3", "grupo_clave": "G1", "cambios": cambios, "dimensiones": ["proceso"], "razon": "Nivel básico"},
            {"tarea_uid": "no-existe", "grupo_clave": "G1", "cambios": cambios, "dimensiones": ["proceso"], "razon": "x"},
        ],
    }
    r = await Orquestador(ProveedorFalso([respuesta]), None, AJUSTES).generar(solicitud(diseno, "diferenciacion"))

    [v] = [e.contenido for e in r.elementos]
    assert v["uid"] == "tc1-t3-G1" and set(v["cambios"]) == {"nivel_apoyo", "codigo_inicial"}
    assert any("no-existe" in a for a in r.advertencias)
    assert r.notas["planes"][0]["proceso"] == "más andamiaje"


async def test_objetivo_con_verbo_no_observable(diseno):
    malo = {"uid": "x", "codigo": "OB-9", "orden": 9, "descripcion": "Comprender cómo funcionan los ciclos en C.", "tipo": "habilidad", "evaluacion": ["comprension"]}
    bueno = malo | {"descripcion": "Trazar la ejecución de un ciclo while en C y predecir su salida sin errores."}
    proveedor = ProveedorFalso([
        {"advertencias": [], "objetivos": [malo], "jerarquia": "…"},
        {"advertencias": [], "objetivos": [bueno], "jerarquia": "…"},
    ])
    r = await Orquestador(proveedor, None, AJUSTES).generar(solicitud(diseno, "objetivos"))

    assert r.intentos == 2
    assert r.elementos[0].contenido | {} == bueno | {"uid": "job42-ob1", "codigo": "OB-2", "orden": 2}
    assert "no es observable" in proveedor.llamadas[1]["mensajes"][-1]["content"]


def test_generar_por_http(diseno):
    proveedor = ProveedorFalso([{"advertencias": [], "grupos": [
        {"grupo_clave": "G1", "fortalezas": ["constancia"], "riesgos": ["ansiedad"], "implicaciones": ["más ejemplos"]}
    ]}])
    app.dependency_overrides[ajustes] = lambda: AJUSTES
    app.dependency_overrides[orquestador] = lambda: Orquestador(proveedor, None, AJUSTES)
    cliente = TestClient(app)
    cabeceras = {"X-Agente-Token": "secreto-de-prueba"}

    r = cliente.post("/v1/generar", json=solicitud(diseno, "resumen_grupo").model_dump(mode="json"), headers=cabeceras)
    assert r.status_code == 200, r.text
    assert r.json()["notas"]["resumen"][0]["riesgos"] == ["ansiedad"]
    assert r.json()["modelo"] == "falso:claude-haiku-4-5"  # los resúmenes usan el modelo ligero

    otra = solicitud(diseno, "no_existe").model_dump(mode="json")
    assert cliente.post("/v1/generar", json=otra, headers=cabeceras).status_code == 422
    assert len(cliente.get("/v1/plantillas", headers=cabeceras).json()) == 15  # con las del tema completo (ADR 0007)
    app.dependency_overrides.pop(orquestador)


async def test_informe_de_revision_recibe_los_resultados(diseno):
    informe = {
        "advertencias": [], "resumen": "Se lograron dos de tres objetivos.",
        "objetivos": [{"codigo": "OB-1", "logrado": True, "evidencia": "82 % de logro"}],
        "hallazgos": ["La carga extrínseca de la clase 2 es alta (6.1 de 10)."],
        "recomendacion": "iterar", "regresar_a": "fase2",
        "cambios": [{"paso": 7, "elemento_uid": "tc1-t3", "sugerencia": "Agregar una ficha de sintaxis."}],
        "limitaciones": ["n = 8"],
    }
    proveedor = ProveedorFalso([informe])
    sol = solicitud(diseno, "informe_revision")
    sol.resultados = {"logro_objetivos": [{"codigo": "OB-1", "media": 82.0}], "n": 8}
    r = await Orquestador(proveedor, None, AJUSTES).generar(sol)
    assert r.elementos == [] and r.notas["informe"]["recomendacion"] == "iterar"
    assert '<resultados>\n{"logro_objetivos"' in proveedor.llamadas[0]["mensajes"][0]["content"]
