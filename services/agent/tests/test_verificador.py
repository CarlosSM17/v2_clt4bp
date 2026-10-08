import copy
import json
from pathlib import Path

import pytest

from app.verificador.contexto import aplicar_variante
from app.verificador.modelos import SolicitudVerificacion
from app.verificador.reglas import verificar

EJEMPLO = Path(__file__).parents[3] / "packages/contracts/examples/diseno-curso/clase-recorridos.json"


@pytest.fixture
def diseno() -> dict:
    return json.loads(EJEMPLO.read_text(encoding="utf-8"))


def codigo_ok(d: dict) -> dict:
    return {t["uid"]: {"aprobados": len(t["casos_prueba"]), "total": len(t["casos_prueba"])} for t in d["tareas"]}


def correr(d: dict, codigo: dict | None = None):
    return verificar(SolicitudVerificacion.model_validate({"diseno": d, "codigo": codigo if codigo is not None else codigo_ok(d)}))


def reglas(informe) -> list[str]:
    return [x.regla for x in informe.hallazgos]


def test_el_ejemplo_de_contracts_pasa_limpio(diseno):
    informe = correr(diseno)
    assert informe.errores == 0 and informe.advertencias == 0, informe.hallazgos
    assert set(informe.semaforo.values()) == {"verde"}


def test_sin_ejecutar_el_codigo_no_se_aprueba(diseno):
    informe = correr(diseno, codigo={})
    assert reglas(informe).count("codigo_verificado") == 3
    assert informe.semaforo["tc1-t1"] == "rojo"


def test_solucion_que_falla_un_caso(diseno):
    codigo = codigo_ok(diseno) | {"tc1-t3": {"aprobados": 1, "total": 2}}
    [x] = [x for x in correr(diseno, codigo).hallazgos if x.regla == "codigo_verificado"]
    assert x.elemento_uid == "tc1-t3" and "1 de 2" in x.mensaje


def test_variante_que_rompe_el_desvanecimiento_se_detecta_en_su_grupo(diseno):
    diseno["variantes"].append(
        {
            "uid": "tc1-t3-G1",
            "elemento_uid": "tc1-t3",
            "grupo_clave": "G1",
            "cambios": {"nivel_apoyo": "ejemplo_resuelto", "codigo_inicial": diseno["tareas"][2]["solucion"]},
            "diferenciacion": {"dimensiones": ["proceso"], "razon": "Más apoyo para Ruta A"},
        }
    )
    [x] = [x for x in correr(diseno).hallazgos if x.regla == "desvanecimiento"]
    assert (x.elemento_uid, x.grupo) == ("tc1-t3", "G1")


def test_por_completar_sin_huecos(diseno):
    diseno["tareas"][1]["codigo_inicial"] = diseno["tareas"][1]["solucion"]
    mensajes = [x.mensaje for x in correr(diseno).hallazgos if x.regla == "apoyo_coherente"]
    assert len(mensajes) == 2  # no marca huecos y además entrega la solución


def test_clase_vacia_y_sin_soporte(diseno):
    clase2 = copy.deepcopy(diseno["clases"][0]) | {"uid": "tc2", "orden": 2, "titulo": "Arreglos"}
    diseno["clases"].append(clase2)
    r = reglas(correr(diseno))
    assert "clase_sin_tareas" in r and "clase_sin_soporte" in r


def test_arcs_incompleto_y_efecto_sin_explicar(diseno):
    diseno["tareas"][0]["arcs"]["confianza"] = "  "
    diseno["tareas"][0]["diseno"]["efectos"][0]["como"] = ""
    r = reglas(correr(diseno))
    assert "tarea_sin_arcs" in r and "efecto_sin_explicar" in r


def test_variabilidad(diseno):
    diseno["tareas"][2]["enunciado_md"] = diseno["tareas"][1]["enunciado_md"]
    assert "variabilidad" in reglas(correr(diseno))


def test_video_largo_sin_segmentos(diseno):
    diseno["medios"].append(
        {"uid": "m1", "tipo": "video", "titulo": "Recorridos", "duracion_s": 900, "segmentos": [], "transcripcion": None}
    )
    assert "video_sin_segmentar" in reglas(correr(diseno))


def test_aplicar_variante_no_toca_identificadores_y_mezcla_diseno():
    base = {"uid": "t1", "orden": 1, "titulo": "A", "diseno": {"interactividad": "baja", "efectos": []}}
    r = aplicar_variante(base, {"uid": "x", "orden": 9, "titulo": "B", "diseno": {"interactividad": "alta"}})
    assert r == {"uid": "t1", "orden": 1, "titulo": "B", "diseno": {"interactividad": "alta", "efectos": []}}


def test_cada_grupo_ve_su_ruta_y_la_autoexplicacion_no_rompe_el_desvanecimiento(diseno):
    # Mapa de ruta (ADR 0007): la tarea 1 (ejemplo resuelto) es solo de la Ruta A; luego viene una autoexplicación
    diseno["tareas"][0]["rutas"] = ["G1"]
    autoexplicacion = copy.deepcopy(diseno["tareas"][0]) | {"uid": "tc1-t4", "orden": 4, "titulo": "Explica este código",
                                                             "pide_autoexplicacion": True, "rutas": []}
    diseno["tareas"].append(autoexplicacion)
    # Y cierra un reto colaborativo convencional después de la solución libre (T5 y T8 del mapa de ruta)
    diseno["tareas"][2]["nivel_apoyo"] = "solucion_libre"
    reto = copy.deepcopy(diseno["tareas"][2]) | {"uid": "tc1-t5", "orden": 5, "titulo": "Reto en equipo", "nivel_apoyo": "convencional",
                                                 "colaborativa": True, "rutas": []}
    diseno["tareas"].append(reto)
    informe = correr(diseno)
    assert "desvanecimiento" not in reglas(informe)
    # Sin el ejemplo de la tarea 1, la Ruta B no empieza con apoyo: si fuera de nivel básico, se le avisaría
    diseno["curso"]["grupos"] = [g | {"nivel": "basico"} for g in diseno["curso"]["grupos"]]
    avisos = [(x.elemento_uid, x.grupo) for x in correr(diseno).hallazgos if x.regla == "inicio_con_apoyo"]
    assert ("tc1-t2", "G2") in avisos and all(g != "G1" for _, g in avisos)
