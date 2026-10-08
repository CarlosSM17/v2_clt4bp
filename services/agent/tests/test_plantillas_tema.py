"""Plantillas del tema completo con la forma del mapa de ruta (ADR 0007): cada pieza en su lugar del diseño."""

import copy

from app.ejecutor import Ejecucion
from app.plantillas import PLANTILLAS, mensaje_usuario, reparto_de_subtemas, subtemas
from app.plantillas import modelos as m
from app.validacion import validar_items
from tests.test_orquestador import diseno, sin_piston, solicitud  # noqa: F401 — fixtures


def _soporte(diseno: dict, tipo: str) -> dict:  # noqa: F811
    return copy.deepcopy(diseno["soporte"][0]) | {"uid": "s9", "clase_uid": "x", "tipo": tipo}


def test_cada_plantilla_de_soporte_fija_su_tipo_y_su_clase(diseno):  # noqa: F811
    clase = diseno["clases"][0]["uid"]
    for plantilla, tipo in (("info_soporte", "modelo_mental"), ("ejemplo_resuelto_tema", "sap"), ("mapa_glosario", "mapa_conceptual")):
        sol = solicitud(diseno, plantilla, clase_uid=clase)
        p = m.PropuestaSoporte.model_validate({"advertencias": [], "soporte": [_soporte(diseno, "explicacion")]})
        (e,), _ = PLANTILLAS[plantilla].convertir(p, sol)
        assert (e.contenido["tipo"], e.contenido["clase_uid"], e.contenido["uid"]) == (tipo, clase, "job42-s1")


def test_la_procedimental_del_tema_cuelga_de_la_clase_y_el_protocolo_es_un_elemento(diseno):  # noqa: F811
    clase = diseno["clases"][0]["uid"]
    ayuda = copy.deepcopy(diseno["procedimental"][0]) | {"tipo": "guia_preguntas"}
    p = m.PropuestaProcedimental.model_validate({"advertencias": [], "procedimental": [ayuda]})

    (tema,), _ = PLANTILLAS["info_procedimental"].convertir(p, solicitud(diseno, "info_procedimental", clase_uid=clase))
    assert tema.contenido["clase_uid"] == clase and "tarea_uid" not in tema.contenido  # sin nulos en el contrato
    (de_tarea,), _ = PLANTILLAS["info_procedimental"].convertir(p, solicitud(diseno, "info_procedimental", tarea_uid="tc1-t2"))
    assert de_tarea.contenido["tarea_uid"] == "tc1-t2" and "clase_uid" not in de_tarea.contenido

    (protocolo,), notas = PLANTILLAS["guion_protocolo"].convertir(p, solicitud(diseno, "guion_protocolo", clase_uid=clase))
    assert protocolo.tipo == "procedimental" and protocolo.contenido["tipo"] == "protocolo_verbal" and notas == {}


def test_la_ficha_de_diseno_se_guarda_en_su_clase(diseno):  # noqa: F811
    clase = diseno["clases"][0]
    sol = solicitud(diseno, "ficha_tema", clase_uid=clase["uid"])
    (e,), _ = PLANTILLAS["ficha_tema"].convertir(m.PropuestaFicha(advertencias=[], ficha_md="### 1. Objetivos\n"), sol)
    assert e.tipo == "clase" and e.contenido["uid"] == clase["uid"] and e.contenido["ficha_md"] == "### 1. Objetivos"
    assert e.contenido["titulo"] == clase["titulo"]  # el resto de la clase no cambia

    sin_clase = solicitud(diseno, "ficha_tema", clase_uid="no-existe")
    elementos, notas = PLANTILLAS["ficha_tema"].convertir(m.PropuestaFicha(advertencias=[], ficha_md="x"), sin_clase)
    assert elementos == [] and notas == {"ficha_md": "x"}


def test_el_mensaje_del_soporte_ve_las_tareas_de_su_clase(diseno):  # noqa: F811
    msg = mensaje_usuario(PLANTILLAS["info_soporte"], solicitud(diseno, "info_soporte", clase_uid=diseno["clases"][0]["uid"]))
    assert diseno["tareas"][0]["enunciado_md"][:30].replace('"', '\\"') in msg  # con su enunciado completo
    assert "Conceptos, uno por uno" in msg and "```salida" in msg


async def test_la_prediccion_de_salida_la_calcula_el_sistema():
    class Piston:
        async def ejecutar(self, _lenguaje, codigo, entrada=""):
            return Ejecucion(True, "14 7 C\n", "", 0, False) if "main" in codigo else Ejecucion(False, "", "error", None, False)

    item = {"tipo": "prediccion_salida", "nivel": "comprension", "objetivo": "OB-1", "forma": "A", "enunciado_md": "Predice",
            "opciones": [], "correcta": -1, "aceptadas": [], "salida": "lo que creyó el modelo", "lineas": [],
            "codigo_inicial": "int main() {}", "solucion": "", "casos_prueba": []}
    roto = item | {"codigo_inicial": "sin programa", "forma": "B"}
    avisos = await validar_items([item, roto], "cpp", Piston())
    assert item["salida"] == "14 7 C"
    assert [(a.nombre, a.bloqueante) for a in avisos] == [("item_salida", True)]


async def test_las_salidas_de_un_item_de_programacion_salen_de_su_solucion(sin_piston):  # noqa: F811
    # Ítems del 2026-10-06: el modelo escribió «1.70» y el programa imprime «1.7»
    class Piston:
        async def ejecutar(self, _lenguaje, codigo, entrada=""):
            return Ejecucion(True, f"{int(entrada) / 100:g}\n", "", 0, False)

    casos = [{"entrada": "170", "salida_esperada": "1.70", "oculto": False}, {"entrada": "99", "salida_esperada": "0.99", "oculto": True}]
    item = {"tipo": "programacion", "nivel": "comprension", "objetivo": "OB-1", "forma": "A", "enunciado_md": "Convierte",
            "opciones": [], "correcta": -1, "aceptadas": [], "salida": "", "lineas": [], "codigo_inicial": "",
            "solucion": "#include <iostream>\nint main() { double cm; std::cin >> cm; std::cout << cm / 100; }", "casos_prueba": casos}
    assert await validar_items([item], "cpp", Piston()) == []
    assert [c["salida_esperada"] for c in casos] == ["1.7", "0.99"]


def test_cada_tarea_de_la_clase_recibe_una_parte_distinta_del_tema():
    # Corrida del 2026-10-06: T2, T3 y T4 eran el mismo «promedio de temperaturas»
    tema02 = ("Elegir el tipo de dato adecuado (int, double, char, bool, string); declarar, inicializar y asignar variables "
              "y constantes; y leer y mostrar datos con cin, getline y cout.")
    assert subtemas(tema02) == ["Elegir el tipo de dato adecuado (int, double, char, bool, string)",
                                "Declarar, inicializar y asignar variables y constantes", "Leer y mostrar datos con cin, getline y cout"]
    assert subtemas("Estructuras: Declaración, Acceso a estructuras, Arreglos y Anidación") == ["Declaración", "Acceso a estructuras", "Arreglos", "Anidación"]
    assert subtemas("Definir funciones. Pasar parámetros por valor y por referencia.") == ["Definir funciones", "Pasar parámetros por valor y por referencia"]
    reparto = reparto_de_subtemas([tema02])
    assert "T3 (por completar sin pistas) sobre «Leer y mostrar datos con cin, getline y cout»" in reparto
    assert "T4 (convencional) combina las partes anteriores" in reparto and "no el subtema" in reparto
    # Un objetivo de una sola idea no se reparte
    assert reparto_de_subtemas(["Calcular el promedio de n datos"]) == ""


def test_las_tareas_t5_a_t8_saben_que_problemas_ya_tiene_la_clase(diseno):  # noqa: F811
    sol = solicitud(diseno, "tareas_complementarias", clase_uid="tc1")
    titulos = [t["titulo"] for t in diseno["tareas"]]
    final = mensaje_usuario(PLANTILLAS["tareas_complementarias"], sol).rsplit("\n\n", 1)[-1]
    assert all(f"«{t}»" in final for t in titulos) and "otros escenarios" in final


async def test_el_protocolo_sin_programa_lo_recibe_en_una_solicitud_aparte(diseno):  # noqa: F811
    # Dos corridas del 2026-10-06: el guion llegaba con sus segmentos y sin el programa del experto en los tres intentos
    from app.config import Ajustes
    from app.orquestador import Orquestador
    from app.proveedor.falso import ProveedorFalso

    class PistonQueImprime:
        async def ejecutar(self, _lenguaje, codigo, entrada=""):
            return Ejecucion(True, "1.75\n", "", 0, False)

    guion = copy.deepcopy(diseno["procedimental"][0]) | {
        "uid": "p1", "tipo": "protocolo_verbal", "titulo": "Protocolo verbal · Altura",
        "cuerpo_md": "> 🎙 **Guion para narrar en 2 segmentos**\n\n**Problema:** Convertir cm a m.\n\n"
                     "**Segmento 1 — Tipo.** **CÓMO:** double. **POR QUÉ:** decimales.\n\n**Segmento 2 — Dividir.** **CÓMO:** / 100.0.",
    }
    programa = '#include <stdio.h>\nint main(void) { double cm; scanf("%lf", &cm); printf("%.2f\n", cm / 100.0); return 0; }'
    proveedor = ProveedorFalso([{"advertencias": [], "procedimental": [guion]}] * 3 + [{"programa": programa, "entrada": "175"}])
    r = await Orquestador(proveedor, PistonQueImprime(), Ajustes(proveedor="claude", max_intentos=3)).generar(
        solicitud(diseno, "guion_protocolo", clase_uid="tc1"))

    (protocolo,) = r.elementos
    md = protocolo.contenido["cuerpo_md"]
    assert "**Programa del experto:**" in md and "```salida\nentrada: 175\n---\n1.75\n```" in md
    assert not [v for v in r.validaciones if not v.ok and v.bloqueante], r.validaciones
    assert any(v.nombre == "programa_aparte" for v in r.validaciones)
