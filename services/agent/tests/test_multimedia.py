"""Diagramas Mermaid y trazas de código en el material que genera el agente (el trazador real: services/trazador)."""

import json

import httpx
import pytest

from app.config import Ajustes
from app.multimedia import (
    bloques, desenvolver_markdown, diagrama_por_omision, errores_mermaid, normalizar_markdown, reparar_mermaid, sin_pasos,
    validar_multimedia,
)
from app.orquestador import Orquestador
from app.proveedor.base import ProveedorNoDisponible
from app.solicitud import ElementoPropuesto
from app.trazador import ClienteTrazador, leer_bloque

CODIGO = '#include <stdio.h>\nint main(void) {\n    int s = 0;\n    s += 5;\n    printf("%d\\n", s);\n    return 0;\n}'
# Lo que escribió el modelo en el trabajo 8: pasos en orden de texto y salidas inventadas
INVENTADA = f"titulo: Suma\nentrada: 3 10\n---\n{CODIGO}\n---\n1 | s=0 | | Inicia\n2 | s=10 | 10 | Primera\n3 | s=60 | 60 | Fin"
REAL = {"pasos": [
    {"l": 3, "f": "main", "v": [], "s": ""},
    {"l": 4, "f": "main", "v": [["s", "0"]], "s": ""},
    {"l": 5, "f": "main", "v": [["s", "5"]], "s": ""},
    {"l": 6, "f": "main", "v": [["s", "5"]], "s": "5\n"},
    {"l": 6, "f": "main", "v": [], "s": "", "fin": True},
], "truncada": False, "error": None}


def trazador(respuesta: dict | None = None, peticiones: list | None = None, estado: int = 200) -> ClienteTrazador:
    def manejar(peticion: httpx.Request) -> httpx.Response:
        if peticiones is not None:
            peticiones.append(json.loads(peticion.content))
        return httpx.Response(estado, json=respuesta or REAL)

    return ClienteTrazador("http://trazador", transporte=httpx.MockTransport(manejar))


def elemento(clase: str, **contenido) -> ElementoPropuesto:
    return ElementoPropuesto(tipo=clase, contenido={"uid": "u1", "titulo": "Recorridos", **contenido})


# ---------- Trazas: los pasos salen de la ejecución, no del modelo ----------


async def test_los_pasos_del_modelo_se_reemplazan_por_los_reales():
    peticiones: list = []
    md, errores = await trazador(peticiones=peticiones).completar_md(f"Antes.\n\n```traza\n{INVENTADA}\n```\nDespués.", "c")

    assert errores == []
    assert peticiones == [{"lenguaje": "c", "codigo": CODIGO, "entrada": "3 10"}]
    bloque = bloques(md)[0][1]
    assert "| Inicia" not in bloque and "60" not in bloque.split("---")[2]  # nada de lo inventado
    pasos = [json.loads(linea) for linea in bloque.split("---")[2].strip().splitlines()]
    assert [p["l"] for p in pasos] == [3, 4, 5, 6, 6]
    assert bloque.startswith("titulo: Suma\nlenguaje: c\nentrada: 3 10\n---\n#include <stdio.h>")
    assert md.startswith("Antes.\n\n```traza\n") and md.endswith("```\nDespués.")


async def test_basta_con_encabezado_y_codigo():
    b, error = leer_bloque("titulo: Suma\nlenguaje: python\n---\nprint(1)\n")
    assert error is None and (b.titulo, b.lenguaje, b.codigo) == ("Suma", "python", "print(1)")
    assert "separados por una línea «---»" in leer_bloque("print(1)")[1]


async def test_lo_que_impide_trazar_se_informa():
    sin_compilar = {"pasos": [], "truncada": False, "error": "no compila: falta ;"}
    _, errores = await trazador(sin_compilar).completar_md(f"```traza\n{INVENTADA}\n```", "c")
    assert errores == ["no compila: falta ;"]

    # Un error en tiempo de ejecución con pasos se conserva como aviso dentro de la traza (enseña algo)
    segv = {**REAL, "error": "el programa terminó con la señal SIGSEGV (acceso a memoria inválido)"}
    md, errores = await trazador(segv).completar_md(f"```traza\n{INVENTADA}\n```", "c")
    assert errores == [] and "aviso: el programa terminó con la señal SIGSEGV" in md

    cortada = {**REAL, "truncada": True}
    md, _ = await trazador(cortada).completar_md(f"```traza\n{INVENTADA}\n```", "c")
    assert "aviso: se muestran los primeros 5 pasos" in md


async def test_sin_trazador_no_se_inventa_nada():
    with pytest.raises(ProveedorNoDisponible, match="trazador"):
        await trazador(estado=503).completar_md(f"```traza\n{INVENTADA}\n```", "c")


async def test_el_orquestador_completa_las_trazas_y_bloquea_las_que_no_corren():
    orq = Orquestador(None, None, Ajustes(), trazador({"pasos": [], "truncada": False, "error": "no compila: x"}))
    e = elemento("soporte", tipo="modelo_mental", cuerpo_md=f"```traza\n{INVENTADA}\n```")
    (v,) = await orq.completar_trazas([e], "c")
    assert v.nombre == "traza_ejecutable" and v.bloqueante and "no compila: x" in v.detalle

    orq.trazador = trazador()
    assert await orq.completar_trazas([e], "c") == []
    assert '{"l":3,"f":"main"' in e.contenido["cuerpo_md"]


async def test_tras_el_ultimo_intento_una_traza_que_no_compila_queda_como_codigo():
    orq = Orquestador(None, None, Ajustes(), trazador({"pasos": [], "truncada": False, "error": "no compila: x"}))
    e = elemento("procedimental", tipo="ejemplo_isomorfico", cuerpo_md=f"Mira:\n\n```traza\n{INVENTADA}\n```\n\nFin.")
    (v,) = await orq.quitar_trazas_rotas([e], "c")
    assert v.nombre == "traza_quitada" and not v.bloqueante and "no compila: x" in v.detalle
    assert e.contenido["cuerpo_md"] == f"Mira:\n\n```c\n{CODIGO}\n```\n\nFin."  # el código queda, sin pasos inventados


# ---------- Validación del material ----------


def test_mermaid_valido_y_etiquetas_que_lo_romperian():
    assert errores_mermaid('flowchart TD\n  A["suma = 0"] --> B{"¿i < n?"}\n  B -- "sí" --> C["suma += a[i]"]') == []
    assert errores_mermaid("mindmap\n  root((Punteros))\n    Dirección") == []
    assert "tipo de diagrama" in errores_mermaid("A --> B")[0]
    (error,) = errores_mermaid("flowchart TD\n  A[suma += a[i]] --> B")
    assert "entre comillas" in error and "A[suma += a[i]" in error
    assert errores_mermaid("flowchart TD\n  B{i < n?} --> C(printf(\"x\"))")


async def test_el_soporte_lleva_diagrama_y_el_ejemplo_resuelto_traza():
    md_con_diagrama = 'Texto.\n\n```mermaid\nflowchart TD\n  A["inicio"] --> B["fin"]\n```\n'
    con = await validar_multimedia([elemento("soporte", tipo="modelo_mental", cuerpo_md=md_con_diagrama)], "info_soporte")
    sin = await validar_multimedia([elemento("soporte", tipo="modelo_mental", cuerpo_md="Solo texto.")], "info_soporte")
    video = await validar_multimedia([elemento("soporte", tipo="guion_video", cuerpo_md="Segmento 1…")], "info_soporte")
    assert con == [] and video == []
    assert sin[0].nombre == "soporte_con_diagrama" and sin[0].bloqueante and sin[0].elemento_uid == "u1"
    (en_clase,) = await validar_multimedia([elemento("soporte", tipo="modelo_mental", cuerpo_md="Solo texto.")], "clase_tareas")
    assert not en_clase.bloqueante

    ejemplo = await validar_multimedia([elemento("tarea", nivel_apoyo="ejemplo_resuelto", enunciado_md="Estudia esto.")])
    assert ejemplo[0].nombre == "ejemplo_con_traza" and not ejemplo[0].bloqueante


async def test_una_traza_sin_pasos_calculados_se_avisa():
    sin_calcular = await validar_multimedia([elemento("soporte", tipo="guion_video", cuerpo_md=f"```traza\n{INVENTADA}\n```")])
    assert sin_calcular[0].nombre == "traza_calculada" and not sin_calcular[0].bloqueante

    md, _ = await trazador().completar_md(f"```traza\n{INVENTADA}\n```", "c")
    assert await validar_multimedia([elemento("soporte", tipo="guion_video", cuerpo_md=md)]) == []


async def test_un_diagrama_roto_en_el_soporte_bloquea():
    md = "```mermaid\nflowchart TD\n  A[suma += a[i]] --> B\n```"
    (v,) = await validar_multimedia([elemento("soporte", tipo="explicacion", cuerpo_md=md)], "info_soporte")
    assert v.nombre == "diagrama_valido" and v.bloqueante


def test_encuentra_los_bloques_por_lenguaje():
    md = "x\n```mermaid\nflowchart TD\n```\ny\n```c\nint main(){}\n```\n```traza\ntitulo: t\n```"
    assert [lenguaje for lenguaje, _ in bloques(md)] == ["mermaid", "c", "traza"]


def test_las_etiquetas_con_simbolos_se_ponen_entre_comillas():
    roto = 'flowchart TD\n    A[max = a[0]] --> B{¿n > 0?}\n    B -- "sí" --> C["si a[i] > max"]\n    C --> D(printf("x"))'
    assert errores_mermaid(roto)  # así, el analizador de Mermaid falla
    reparado = reparar_mermaid(roto)
    assert reparado.split("\n")[1] == '    A["max = a[0]"] --> B{"¿n > 0?"}'
    assert reparado.split("\n")[2] == '    B -- "sí" --> C["si a[i] > max"]'  # lo que ya tenía comillas no cambia
    assert reparado.split("\n")[3] == '    C --> D("printf(#quot;x#quot;)")'
    assert errores_mermaid(reparado) == []
    assert reparar_mermaid("sequenceDiagram\n    A->>B: hola()") == "sequenceDiagram\n    A->>B: hola()"


async def test_al_generar_se_reparan_los_diagramas_y_se_quitan_las_cercas():
    e = elemento("soporte", tipo="explicacion", cuerpo_md="Así:\n```mermaid\nflowchart TD\n    B{n > 0}\n```\nFin.")
    normalizar_markdown([e])
    assert e.contenido["cuerpo_md"] == 'Así:\n```mermaid\nflowchart TD\n    B{"n > 0"}\n```\nFin.'


def test_una_tabla_dentro_de_una_cerca_markdown_se_desenvuelve():
    tabla = "| Instrucción | Ejemplo |\n|---|---|\n| `scanf` | `scanf(\"%d\", &n);` |"
    assert desenvolver_markdown(f"Ficha:\n```markdown\n{tabla}\n```\nFin.") == f"Ficha:\n{tabla}\nFin."
    # Un bloque de código dentro de la cerca se conserva entero
    dentro = "Así:\n```c\nint x;\n```\nListo."
    assert desenvolver_markdown(f"```md\n{dentro}\n```") == dentro
    # Sin cerca markdown, nada cambia
    assert desenvolver_markdown("```c\nint x;\n```") == "```c\nint x;\n```"


def test_sin_pasos_deja_encabezado_y_codigo():
    md = "Así:\n```traza\ntitulo: t\n---\nint main(void) {}\n---\n{\"l\":1}\n{\"l\":2}\n```\nY un diagrama:\n```mermaid\nflowchart TD\n```"
    assert sin_pasos(md) == "Así:\n```traza\ntitulo: t\n---\nint main(void) {}\n```\nY un diagrama:\n```mermaid\nflowchart TD\n```"
    assert sin_pasos("```traza\ntitulo: t\n---\nint main(void) {}\n```") == "```traza\ntitulo: t\n---\nint main(void) {}\n```"


def test_conceptos_sin_diagrama_reciben_un_resumen_visual():
    # Corrida del 2026-10-06: «Conceptos del tema» sin ```mermaid en tres intentos
    md = "## Conceptos del tema\n\n### 1. Tipos de datos\nTexto.\n\n### 2. Variables `int`\nTexto.\n\n### 3. Lectura con \"cin\"\nTexto."
    soporte = elemento("soporte", tipo="modelo_mental", cuerpo_md=md, titulo="Conceptos del tema")
    (aviso,) = diagrama_por_omision([soporte])
    nuevo = soporte.contenido["cuerpo_md"]
    assert aviso.nombre == "diagrama_automatico" and not aviso.bloqueante
    assert '> 🗺 **Resumen visual · Conceptos del tema**' in nuevo
    assert 'T --> C2["2. Variables int"]' in nuevo and 'C3["3. Lectura con cin"]' in nuevo
    (_, codigo), = [b for b in bloques(nuevo) if b[0] == "mermaid"]
    assert errores_mermaid(codigo) == []
    # Con diagrama propio, o sin conceptos que mostrar, no se toca
    assert diagrama_por_omision([soporte]) == []
    assert diagrama_por_omision([elemento("soporte", tipo="modelo_mental", cuerpo_md="Solo texto.")]) == []


def test_el_mapa_envuelto_en_una_cerca_y_su_diagrama_sin_bloque_se_reparan():
    # Mapa del tema del 2026-10-06: todo dentro de ``` sin lenguaje y el diagrama como texto suelto
    md = ("```\n> 🗺 **Resumen visual · Mapa del tema**\n\nflowchart LR\n  A[\"Variables\"] --> B[\"Tipos\"]\n  A --> C[\"cin\"]\n\n"
          "## Glosario bilingüe\n\n| Español | English |\n|---|---|\n| Variable | Variable |\n```")
    e = elemento("soporte", tipo="mapa_conceptual", cuerpo_md=md)
    normalizar_markdown([e])
    nuevo = e.contenido["cuerpo_md"]
    assert nuevo.startswith("> 🗺 **Resumen visual") and nuevo.rstrip().endswith("| Variable | Variable |")
    (diagrama,) = [c for t, c in bloques(nuevo) if t == "mermaid"]
    assert diagrama.startswith("flowchart LR") and errores_mermaid(diagrama) == []
    # Un bloque de código sin lenguaje que no encierra Markdown se queda como está
    codigo = "Mira:\n```\nint x = 3;\n```\nFin."
    assert normalizar_markdown([e2 := elemento("soporte", cuerpo_md=codigo)]) is None and e2.contenido["cuerpo_md"] == codigo


def test_un_separador_suelto_antes_de_la_tabla_se_quita():
    e = elemento("procedimental", cuerpo_md="|\n| Quiero… | Escribo |\n|---|---|\n| Leer un entero | `cin >> n;` |")
    normalizar_markdown([e])
    assert e.contenido["cuerpo_md"].startswith("| Quiero… | Escribo |")


def test_el_mapa_sin_diagrama_ni_titulos_usa_los_terminos_del_glosario():
    md = "## Glosario bilingüe\n\n| Español | English | Significado |\n|---|---|---|\n| Variable | Variable | Caja |\n| Tipo de dato | Data type | Clase |"
    mapa = elemento("soporte", tipo="mapa_conceptual", cuerpo_md=md, titulo="Mapa del tema y glosario")
    (aviso,) = diagrama_por_omision([mapa])
    assert 'C1["Variable"]' in mapa.contenido["cuerpo_md"] and 'C2["Tipo de dato"]' in mapa.contenido["cuerpo_md"]


def test_los_recuadros_con_la_cita_vacia_o_solo_con_el_titulo_se_reparan():
    # Conceptos del 2026-10-06: «>» vacío, el título debajo y el texto afuera de la cita
    md = (">\n💡 **Analogía · Tipos como herramientas**\n\nCada herramienta sirve para algo.\n\n"
          "> ❓ **Pregunta para pensar**\n¿Qué usarías?\n\n### 1. Tipo int\n\n> ⚠️ **¡Cuidado!** Un int no guarda 1.75.")
    e = elemento("soporte", tipo="modelo_mental", cuerpo_md=md)
    normalizar_markdown([e])
    assert e.contenido["cuerpo_md"] == (
        "> 💡 **Analogía · Tipos como herramientas**\n> Cada herramienta sirve para algo.\n\n"
        "> ❓ **Pregunta para pensar**\n> ¿Qué usarías?\n\n### 1. Tipo int\n\n> ⚠️ **¡Cuidado!** Un int no guarda 1.75.")


def test_un_programa_sin_cerrar_con_su_entrada_suelta_se_separa():
    # Ejemplo isomórfico del 2026-10-06: el programa sin «```» de cierre y su salida sin «```salida»
    md = ("Ejemplo: registro en la biblioteca\n\n```cpp\nint main() {\n    std::cin >> nombre;\n}\n//→ Lee y muestra\n\n"
          "entrada: Juan\n25\n---\n\n**Corrección:** nada.")
    e = elemento("procedimental", tipo="ejemplo_isomorfico", cuerpo_md=md)
    normalizar_markdown([e])
    assert e.contenido["cuerpo_md"] == (
        "Ejemplo: registro en la biblioteca\n\n```cpp\nint main() {\n    std::cin >> nombre;\n}\n//→ Lee y muestra\n\n```\n"
        "```salida\nentrada: Juan\n25\n---\n```\n\n**Corrección:** nada.")
    # Un bloque bien cerrado no cambia, y uno abierto al final se cierra
    bien = "```cpp\nint main() {}\n```\n```salida\nentrada: 3\n---\n```"
    assert normalizar_markdown([b := elemento("soporte", cuerpo_md=bien)]) is None and b.contenido["cuerpo_md"] == bien
    normalizar_markdown([a := elemento("soporte", cuerpo_md="Mira:\n```cpp\nint x;")])
    assert a.contenido["cuerpo_md"] == "Mira:\n```cpp\nint x;\n```"
