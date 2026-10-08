"""Etapas que hacen confiable el material de un modelo pequeño: salidas calculadas, trazas de ejemplos y clase completa."""

import copy

from app.config import Ajustes
from app.bloques_md import DATOS_DE_SOBRA
from app.ejecutor import Ejecucion
from app.etapas import (
    agregar_trazas_ejemplos, alinear_nivel, asignar_rutas, calcular_salidas, codigo_dado, formato_printf, huecos_desde_solucion,
    marcar_huecos, procedimental_del_tema, punto_y_coma_tras_tipos, reparar_codigo, repite_existente, secciones_del_tema,
    tareas_distintas,
)
from app.orquestador import Orquestador
from app.proveedor.base import ProveedorNoDisponible
from app.proveedor.falso import ProveedorFalso
from app.solicitud import ElementoPropuesto
from app.validacion import probar_codigo
from tests.test_regresion import FICHA
from tests.test_orquestador import diseno, propuesta_clase, sin_piston, solicitud  # noqa: F401 — fixtures

SUMA = '#include <stdio.h>\nint main(void) { int a, b; scanf("%d %d", &a, &b); printf("%d\\n", a + b); return 0; }'
FIJA = '#include <stdio.h>\nint main(void) { int arr[] = {10, 20, 30}; printf("%d\\n", (arr[0] + arr[1] + arr[2]) / 3); return 0; }'


class PistonQueSuma:
    """La solución SUMA imprime la suma de su entrada; la FIJA, siempre 20."""

    async def ejecutar(self, _lenguaje, codigo, entrada=""):
        entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
        if codigo == FIJA:
            return Ejecucion(True, "20\n", "", 0, False)
        a, b = (int(x) for x in entrada.split())
        return Ejecucion(True, f"{a + b}\n", "", 0, False)


def tarea(uid: str, solucion: str, casos: list[tuple[str, str]], nivel: str = "convencional") -> ElementoPropuesto:
    return ElementoPropuesto(tipo="tarea", contenido={
        "uid": uid, "titulo": f"Tarea {uid}", "nivel_apoyo": nivel, "solucion": solucion, "codigo_inicial": solucion,
        "enunciado_md": "Suma dos números.",
        "casos_prueba": [{"entrada": e, "salida_esperada": s, "oculto": i == len(casos) - 1} for i, (e, s) in enumerate(casos)],
    })


async def test_las_salidas_esperadas_salen_de_ejecutar_la_solucion():
    t = tarea("t1", SUMA, [("2 3", "5"), ("10 20", "31"), ("1 1", "2")])  # el modelo se equivocó en 10+20
    (aviso,) = await calcular_salidas([t], "c", PistonQueSuma())

    assert [c["salida_esperada"] for c in t.contenido["casos_prueba"]] == ["5", "30", "2"]
    assert aviso.nombre == "salidas_calculadas" and not aviso.bloqueante and "1 de 3" in aviso.detalle


async def test_una_solucion_que_ignora_la_entrada_bloquea():
    t = tarea("t1", FIJA, [("3\n10\n20\n30", "20"), ("2\n5\n15", "10"), ("1\n100", "100")])
    (v,) = await calcular_salidas([t], "c", PistonQueSuma())

    assert v.nombre == "solucion_usa_entrada" and v.bloqueante and v.elemento_uid == "t1"
    assert "scanf o cin" in v.detalle and "'20'" in v.detalle
    assert t.contenido["casos_prueba"][1]["salida_esperada"] == "10"  # no se tocan: la solución está mal


async def test_una_division_entre_cero_en_un_caso_bloquea():
    class PistonQueDivide:
        async def ejecutar(self, _lenguaje, _codigo, entrada=""):
            entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
            n = int(entrada.split()[0])
            return Ejecucion(True, "-nan\n" if n == 0 else f"{10 / n:.2f}\n", "", 0, False)

    t = tarea("t1", SUMA, [("2 10 10", "5.00"), ("1 10", "10.00"), ("0", "0")])
    (v,) = await calcular_salidas([t], "c", PistonQueDivide())
    assert v.nombre == "salida_invalida" and v.bloqueante and "'0'" in v.detalle and "'-nan'" in v.detalle


MAXIMO = """#include <stdio.h>
int main(void) {
    int n, max;
    scanf("%d", &n);
    for (int i = 0; i < n; i++) {
        int x;
        scanf("%d", &x);
        if (i == 0) {
            max = x;
        } else {
            if (x > max) {
                max = x;
            }
        }
    }
    printf("%d\\n", max);
    return 0;
}"""


def test_los_huecos_salen_de_las_lineas_clave_de_la_solucion():
    lineas = huecos_desde_solucion(MAXIMO, "c").split("\n")
    assert lineas[10] == "            if (/* HUECO 1: escribe la condición (usa x y max) */) {"
    assert lineas[11] == "                /* HUECO 2: escribe la instrucción que actualiza max */"
    assert lineas[8] == "            max = x;"  # la misma instrucción no se repite como hueco
    assert 'scanf("%d", &x);' in lineas[6] and "int x;" in lineas[5]  # ni la lectura ni las declaraciones
    assert huecos_desde_solucion('#include <stdio.h>\nint main(void) { printf("hola\\n"); }', "c") is None


def test_un_problema_por_completar_sin_huecos_los_recibe():
    sin_huecos = tarea("t2", MAXIMO, [("2 3", "3")], nivel="por_completar")
    con_huecos = tarea("t3", MAXIMO, [("2 3", "3")], nivel="por_completar")
    con_huecos.contenido["codigo_inicial"] = "/* HUECO 1: lee n */"
    (aviso,) = marcar_huecos([sin_huecos, con_huecos], "c")

    assert aviso.nombre == "huecos_automaticos" and aviso.elemento_uid == "t2" and not aviso.bloqueante
    assert sin_huecos.contenido["codigo_inicial"].count("HUECO") == 2
    assert con_huecos.contenido["codigo_inicial"] == "/* HUECO 1: lee n */"

    # Trabajo 24: marca «HUECO» pero deja la instrucción debajo; sin comentarios es la solución completa
    comentado = tarea("t4", MAXIMO, [("2 3", "3")], nivel="por_completar")
    comentado.contenido["codigo_inicial"] = MAXIMO.replace("max = x;\n            }\n        }", "/* HUECO 1: actualiza max */ max = x;\n            }\n        }")
    (aviso,) = marcar_huecos([comentado], "c")
    assert aviso.elemento_uid == "t4" and "max = x;\n            }\n        }" not in comentado.contenido["codigo_inicial"]


async def test_el_ejemplo_resuelto_con_gemelo_conserva_su_codigo():
    # ADR 0007: el código inicial es el ejemplo que se estudia; la solución y los casos son del gemelo. Sustituirlo por
    # la solución (como hasta sistema-v11) le daría al estudiante la respuesta del gemelo
    ejemplo = SUMA.replace("a + b", "a * b")
    t = tarea("t1", SUMA, [("2 3", "5"), ("4 4", "8")], nivel="ejemplo_resuelto")
    t.contenido["codigo_inicial"] = ejemplo
    assert await calcular_salidas([t], "c", PistonQueSuma()) == []
    assert t.contenido["codigo_inicial"] == ejemplo


def test_un_printf_con_formato_de_otro_tipo_se_detecta():
    # El caso real: max es int y se imprime con %.1f → siempre «0.0»
    mal = MAXIMO.replace('printf("%d\\n", max);', 'printf("%.1f\\n", max);')
    assert "«%.1f» con «max», que es un entero" in formato_printf(mal)
    assert "un número real" in formato_printf('int main(void) { double p = 2.5; printf("%d %s\\n", p, "x"); }')
    assert formato_printf(MAXIMO) is None
    assert formato_printf('int main(void) { int s = 3, n = 2; printf("%.2f\\n", (double) s / n); }') is None  # con conversión
    assert formato_printf('int main(void) { int a[3]; double d; printf("%d %f\\n", a[0], d); }') is None


async def test_el_formato_de_printf_bloquea_antes_de_calcular_salidas():
    mal = MAXIMO.replace('printf("%d\\n", max);', 'printf("%.1f\\n", max);')
    t = tarea("t1", mal, [("2 3", "3"), ("1 7", "7")])
    (v,) = await calcular_salidas([t], "c", PistonQueSuma())
    assert v.nombre == "formato_printf" and v.bloqueante and t.contenido["casos_prueba"][0]["salida_esperada"] == "3"


async def test_un_ejemplo_que_no_compila_bloquea():
    # Trabajo 24: el código del ejemplo no traía el #include que usa (setprecision)
    t = tarea("t1", "#include <iomanip>\n" + SUMA, [("2 3", "5"), ("4 4", "8")], nivel="ejemplo_resuelto")
    t.contenido["codigo_inicial"] = SUMA + "\n// setprecision(2)"

    class PistonEstricto:
        async def ejecutar(self, _lenguaje, codigo, entrada=""):
            entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
            if "setprecision" in codigo and "iomanip" not in codigo:
                return Ejecucion(False, "", "error: 'setprecision' was not declared in this scope", None, False)
            return Ejecucion(True, "5\n", "", 0, False)

    _, avisos = await probar_codigo([t], "cpp", PistonEstricto())
    (v,) = [a for a in avisos if a.nombre == "ejemplo_ejecutable"]
    assert v.bloqueante and "setprecision" in v.detalle and t.contenido["codigo_inicial"].endswith("setprecision(2)")


def test_el_nivel_de_apoyo_sigue_al_titulo():
    # Trabajo 24: «Problema por completar: …» marcado como ejemplo resuelto
    por_completar = tarea("t2", MAXIMO, [("2 3", "3"), ("1 7", "7")], nivel="ejemplo_resuelto")
    por_completar.contenido["titulo"] = "Problema por completar: Venta más alta"
    convencional = tarea("t3", MAXIMO, [("2 3", "3"), ("1 7", "7")], nivel="por_completar")
    convencional.contenido["titulo"] = "Problema Convencional: Venta más alta del mes"
    convencional.contenido["casos_prueba"][-1]["oculto"] = False
    bien = tarea("t1", MAXIMO, [("2 3", "3")], nivel="ejemplo_resuelto")
    bien.contenido["titulo"] = "Ejemplo resuelto: Venta más alta"

    avisos = alinear_nivel([por_completar, convencional, bien])
    assert [a.elemento_uid for a in avisos] == ["t2", "t3"] and not any(a.bloqueante for a in avisos)
    assert por_completar.contenido["nivel_apoyo"] == "por_completar"
    assert convencional.contenido["nivel_apoyo"] == "convencional" and convencional.contenido["codigo_inicial"] == ""
    assert convencional.contenido["casos_prueba"][-1]["oculto"]  # sin apoyo: lleva un caso oculto
    # Y por completar, ya sin huecos, los recibe después
    assert marcar_huecos([por_completar], "c")[0].nombre == "huecos_automaticos"
    # Un problema convencional con la solución como código inicial: se vacía (el estudiante no tendría nada que hacer)
    copia = tarea("t4", MAXIMO, [("2 3", "3"), ("1 7", "7")])
    copia.contenido["titulo"] = "Problema convencional: Venta más alta del año"
    assert alinear_nivel([copia]) == [] and copia.contenido["codigo_inicial"] == ""


def test_cada_tarea_de_la_clase_es_un_ejercicio_distinto():
    # Las tres tareas eran «Cálculo del promedio de edades» con distinto nivel de apoyo
    a = tarea("t1", SUMA, [("1 2", "3")], nivel="ejemplo_resuelto")
    a.contenido["titulo"] = "Ejemplo resuelto: Promedio de edades"
    b = tarea("t2", SUMA, [("1 2", "3")], nivel="por_completar")
    b.contenido["titulo"] = "Problema por completar: Promedio de edades"
    c = tarea("t3", MAXIMO, [("1 2", "3")])
    c.contenido |= {"titulo": "Problema convencional: Estudiante de mayor edad", "enunciado_md": "Encuentra al estudiante de mayor edad."}
    (v,) = tareas_distintas([a, b, c])
    assert v.nombre == "tareas_distintas" and v.bloqueante and v.elemento_uid == "t2" and "Promedio de edades" in v.detalle

    # Corrida limpia del 2026-10-04: el nivel al final del título y enunciados redactados distinto (32 % de parecido)
    puntos = "struct Punto { int x, y; };\nint main() { int n; Punto puntos[100]; cin >> n; int suma = 0; suma += puntos[0].x; }"
    d = tarea("t4", puntos, [("1 2", "3")], nivel="ejemplo_resuelto")
    d.contenido |= {"titulo": "Suma de campos en estructuras", "enunciado_md": "Lee n puntos y suma sus coordenadas."}
    e = tarea("t5", puntos, [("1 2", "3")], nivel="ejemplo_resuelto")
    e.contenido |= {"titulo": "Totales de una lista de puntos (por completar)", "enunciado_md": "Completa el programa del plano."}
    assert [x.elemento_uid for x in tareas_distintas([d, e])] == ["t5"]  # casi el mismo programa
    assert alinear_nivel([e])[0].nombre == "nivel_por_titulo" and e.contenido["nivel_apoyo"] == "por_completar"


def test_la_procedimental_del_tema_tiene_su_forma(diseno):  # noqa: F811
    sol = solicitud(diseno, "info_procedimental", clase_uid=diseno["clases"][0]["uid"])

    def ayuda(tipo: str, cuerpo: str) -> ElementoPropuesto:
        return ElementoPropuesto(tipo="procedimental", contenido={"uid": f"p-{tipo}", "titulo": tipo, "tipo": tipo, "cuerpo_md": cuerpo})

    tarjeta = ayuda("ficha_sintaxis", "| Quiero… | Escribo |\n|---|---|\n| Leer un número | `scanf(\"%d\", &n);` |")
    guia = ayuda("guia_preguntas", "☐ ¿Inicialicé las variables?")
    errores = ayuda("errores_frecuentes", "**a) Sin declarar.**\n```c\nint main(void) { total = 5; }\n```\n```compilador\n```")
    isomorfico = ayuda("ejemplo_isomorfico", "```c\n#include <stdio.h>\nint main(void) { double precio; scanf(\"%lf\", &precio); }\n```")
    assert procedimental_del_tema(sol, [tarjeta, guia, errores, isomorfico]) == []

    sin_tabla = ayuda("ficha_sintaxis", "Usa scanf para leer.")
    copia = ayuda("ejemplo_isomorfico", f"```c\n{diseno['tareas'][2]['solucion']}\n```")
    nombres = sorted((v.nombre, v.bloqueante) for v in procedimental_del_tema(sol, [sin_tabla, copia]))
    # La tarjeta sin tabla y el «ejemplo» que es la solución de una tarea bloquean; faltar partes es aviso
    assert nombres == [("ejemplo_distinto", True), ("procedimental_completo", True), ("procedimental_del_tema", False)]


def test_el_soporte_del_tema_avisa_lo_que_le_falta():
    conceptos = ElementoPropuesto(tipo="soporte", contenido={"uid": "s1", "titulo": "Conceptos del tema", "tipo": "modelo_mental",
                                                               "cuerpo_md": "> 💡 **Analogía · Casilleros**\n## Conceptos, uno por uno\n"})
    (v,) = secciones_del_tema("info_soporte", [conceptos])
    assert not v.bloqueante and "pregunta para pensar" in v.detalle and "actividad en el aula" in v.detalle
    assert secciones_del_tema("objetivos", [conceptos]) == []  # solo las plantillas del tema


class Grupo:
    def __init__(self, clave: str, nivel: str):
        self.clave, self.nivel = clave, nivel


def test_las_rutas_salen_del_papel_de_cada_tarea():
    # La matriz del Tema 02: Ruta A (sin previos) T1, T2, T5; Ruta B T6, T7; todas T3, T4, T8
    papeles = [("ejemplo_resuelto", False, False), ("por_completar", False, False), ("por_completar", False, False),
               ("convencional", False, False), ("solucion_libre", False, False), ("ejemplo_resuelto", True, False),
               ("ejemplo_resuelto", True, False), ("convencional", False, True)]
    tareas = []
    for i, (nivel, explica, colabora) in enumerate(papeles, start=1):
        t = tarea(f"t{i}", SUMA, [("1 2", "3")], nivel=nivel)
        t.contenido |= {"pide_autoexplicacion": explica, "colaborativa": colabora}
        tareas.append(t)
    grupos = [Grupo("G1", "basico"), Grupo("G2", "intermedio"), Grupo("G3", "avanzado")]
    (aviso,) = asignar_rutas(tareas, grupos)
    assert [t.contenido["rutas"] for t in tareas] == [["G1"], ["G1"], [], [], ["G1"], ["G2", "G3"], ["G2", "G3"], []]
    assert not aviso.bloqueante and "Ruta A (G1)" in aviso.detalle

    # Un solo nivel: todas para todos, sin aviso
    assert asignar_rutas(tareas, [Grupo("G1", "basico")]) == [] and all(t.contenido["rutas"] == [] for t in tareas)
    # Con pocas tareas un grupo se quedaría con menos de 3: todas para todos
    (pocas,) = asignar_rutas(tareas[:2], grupos)
    assert "menos de 3" in pocas.detalle and all(t.contenido["rutas"] == [] for t in tareas[:2])


def test_un_salto_de_linea_sin_comillas_se_corrige():
    # Trabajo 24: «stray '\' in program» en las tres soluciones y los tres intentos
    roto = 'int main() {\n    std::cout << suma / n << \\n;\n    std::cout << "a" << \\n << "b";\n}'
    t = tarea("t1", roto, [("1", "1")])
    reparar_codigo([t])
    assert t.contenido["solucion"] == 'int main() {\n    std::cout << suma / n << \'\\n\';\n    std::cout << "a" << \'\\n\' << "b";\n}'
    assert t.contenido["codigo_inicial"] == t.contenido["solucion"]
    bien = tarea("t2", SUMA, [("1", "1")])
    reparar_codigo([bien])
    assert bien.contenido["solucion"] == SUMA  # printf("%d\n") no se toca


def test_a_una_estructura_sin_punto_y_coma_se_le_agrega():
    # Trabajo 24: «expected ';' after struct definition» en tres tareas y tres intentos
    roto = "struct Fecha {\n    int dia;\n}\nstruct Persona {\n    char nombre[20];\n    struct Fecha nacimiento;\n}\nint main(void) {\n    return 0;\n}"
    assert punto_y_coma_tras_tipos(roto) == roto.replace("int dia;\n}", "int dia;\n};").replace("nacimiento;\n}", "nacimiento;\n};")
    bien = "typedef struct {\n    int x;\n} Punto;\nstruct P { int a; };\nstruct Q { int b; } q;\nstruct Persona leer(void) {\n    struct Persona p;\n    return p;\n}"
    assert punto_y_coma_tras_tipos(bien) == bien  # ni lo que ya lo tiene, ni las variables, ni las funciones
    assert punto_y_coma_tras_tipos("enum Color { ROJO, VERDE }\n") == "enum Color { ROJO, VERDE };\n"


async def test_una_salida_que_cambia_entre_ejecuciones_se_informa_y_luego_se_quita():
    # n = 3 con solo 5 de los 6 números: el programa lee memoria sin valor (imprimía 1701870090)
    class PistonConBasura:
        def __init__(self):
            self.vez = 0

        async def ejecutar(self, _lenguaje, _codigo, entrada=""):
            entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
            datos = [int(x) for x in entrada.split()]
            if len(datos) - 1 < 2 * datos[0]:
                self.vez += 1
                return Ejecucion(True, f"{1701870090 + self.vez}\n", "", 0, False)
            return Ejecucion(True, f"{sum(datos[1:])}\n", "", 0, False)

    casos = [("2 10 20 30 40", "100"), ("1 5 10", "15"), ("3 10 20 30 40 50", "150")]
    t = tarea("t1", SUMA, casos)
    (v,) = await calcular_salidas([t], "c", PistonConBasura())
    assert v.nombre == "salida_inestable" and v.bloqueante and "'3 10 20 30 40 50'" in v.detalle

    (aviso,) = await calcular_salidas([t], "c", PistonConBasura(), quitar_fallidos=True)
    assert aviso.nombre == "casos_quitados" and [c["entrada"] for c in t.contenido["casos_prueba"]] == ["2 10 20 30 40", "1 5 10"]


class PistonQueTruena:
    """Promedio con división entera: con n = 0 termina con SIGFPE (código 136), como en el trabajo 18."""

    async def ejecutar(self, _lenguaje, _codigo, entrada=""):
        entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
        n, *datos = (int(x) for x in entrada.split())
        if n == 0:
            return Ejecucion(True, "", "Floating point exception", 136, False)
        return Ejecucion(True, f"{sum(datos) // n}\n", "", 0, False)


async def test_desde_el_segundo_intento_se_quitan_los_casos_con_los_que_la_solucion_truena():
    casos = [("2 10 20", "15"), ("1 7", "7"), ("0", "0")]
    t = tarea("t1", SUMA, casos)  # convencional: el último (el que truena) era el oculto
    assert await calcular_salidas([t], "c", PistonQueTruena()) == []  # primer intento: lo informa el verificador
    assert len(t.contenido["casos_prueba"]) == 3

    (aviso,) = await calcular_salidas([t], "c", PistonQueTruena(), quitar_fallidos=True)
    assert aviso.nombre == "casos_quitados" and not aviso.bloqueante and "'0'" in aviso.detalle
    assert [(c["entrada"], c["salida_esperada"], c["oculto"]) for c in t.contenido["casos_prueba"]] == [
        ("2 10 20", "15", False), ("1 7", "7", True),  # sigue habiendo uno oculto
    ]


async def test_si_quedarian_menos_de_dos_casos_no_se_quitan():
    t = tarea("t1", SUMA, [("0", "0"), ("0 ", "0"), ("1 7", "7")])
    assert await calcular_salidas([t], "c", PistonQueTruena(), quitar_fallidos=True) == []
    assert len(t.contenido["casos_prueba"]) == 3  # lo informa el verificador, que bloquea


def test_una_clase_que_copia_una_existente_bloquea_y_dice_el_tema(diseno):  # noqa: F811
    sol = solicitud(diseno, "clase_tareas", objetivos=["OB-1"])
    existente = diseno["tareas"][0]["titulo"].split(":")[-1].strip()
    propuesta = [
        ElementoPropuesto(tipo="clase", contenido={"uid": "n", "titulo": diseno["clases"][0]["titulo"].upper()}),
        ElementoPropuesto(tipo="tarea", contenido={"uid": "n-t1", "titulo": f"Problema por completar: {existente}"}),
        ElementoPropuesto(tipo="tarea", contenido={"uid": "n-t2", "titulo": "Ejemplo resuelto: Arreglo de estructuras"}),
    ]
    v = repite_existente(sol, propuesta, "Tema de esta clase: OB-2.")
    assert [x.elemento_uid for x in v] == ["n", "n-t1"] and all(x.bloqueante for x in v)
    assert "Tema de esta clase: OB-2." in v[0].detalle and "ya existe" in v[1].detalle


async def test_casos_iguales_en_dos_tareas_se_avisan():
    casos = [("2 3", "5"), ("4 4", "8")]
    v = await calcular_salidas([tarea("t1", SUMA, casos), tarea("t2", SUMA, casos)], "c", PistonQueSuma())
    assert [x.nombre for x in v] == ["casos_variados"] and v[0].elemento_uid == "t2" and not v[0].bloqueante


async def test_sin_ejecutor_no_se_toca_nada():
    t = tarea("t1", SUMA, [("2 3", "99")])
    assert await calcular_salidas([t], "c", None) == []
    assert t.contenido["casos_prueba"][0]["salida_esperada"] == "99"


def test_cada_ejemplo_resuelto_lleva_su_traza():
    ejemplo = tarea("t1", SUMA, [("2 3", "5"), ("4 4", "8")], nivel="ejemplo_resuelto")
    otra = tarea("t2", SUMA, [("2 3", "5")])
    assert agregar_trazas_ejemplos([ejemplo, otra]) == 1

    md = ejemplo.contenido["enunciado_md"]
    assert md.startswith("Suma dos números.\n\n### Ejecución paso a paso")
    assert f"```traza\ntitulo: Tarea t1\nentrada: 2 3\n---\n{SUMA}\n```" in md
    assert "```traza" not in otra.contenido["enunciado_md"]  # solo ejemplos resueltos
    assert agregar_trazas_ejemplos([ejemplo]) == 0  # no se duplica


async def test_sin_tiempo_las_solicitudes_de_seguimiento_se_omiten_y_se_avisa(diseno):  # noqa: F811
    clase = propuesta_clase(diseno)
    clase["soporte"], clase["procedimental"] = [], []
    proveedor = ProveedorFalso([clase])  # si pidiera más, el proveedor falso fallaría
    ajustes = Ajustes(proveedor="claude", clase_completa=True, tareas_complementarias=False, presupuesto_clase_s=-1)
    r = await Orquestador(proveedor, None, ajustes).generar(solicitud(diseno, "clase_tareas"))

    assert len(proveedor.llamadas) == 1
    assert sum("No dio tiempo de generar" in a for a in r.advertencias) == 2  # el soporte y la procedimental del tema


async def test_una_clase_se_completa_con_su_soporte_y_su_procedimental_del_tema(diseno):  # noqa: F811
    clase = propuesta_clase(diseno)
    clase["soporte"], clase["procedimental"] = [], []  # el modelo solo propone la clase y sus tareas
    soporte = copy.deepcopy(diseno["soporte"][0]) | {"uid": "s1", "clase_uid": "x"}
    ayuda = copy.deepcopy(diseno["procedimental"][0]) | {"uid": "p1", "tarea_uid": "x", "cuerpo_md": FICHA}
    proveedor = ProveedorFalso([
        clase,
        {"advertencias": [], "soporte": [soporte]},
        {"advertencias": ["Revisa los nombres."], "procedimental": [ayuda]},  # del tema, no de una tarea
    ])
    ajustes = Ajustes(proveedor="claude", clase_completa=True, tareas_complementarias=False, max_intentos=3)
    r = await Orquestador(proveedor, None, ajustes).generar(solicitud(diseno, "clase_tareas"))

    uids = [(e.tipo, e.contenido["uid"]) for e in r.elementos]
    assert ("soporte", "job42-tc-s1") in uids
    (tema,) = [e.contenido for e in r.elementos if e.tipo == "procedimental"]
    assert (tema["uid"], tema["clase_uid"], "tarea_uid" in tema) == ("job42-tc-tema-p1", "job42-tc", False)
    assert "Procedimental: Revisa los nombres." in r.advertencias
    assert r.uso.entrada == 3000  # tres llamadas al modelo
    # Las solicitudes de seguimiento ven la clase nueva en el diseño
    assert "job42-tc-t3" in proveedor.llamadas[2]["mensajes"][0]["content"]
    # El ejemplo resuelto quedó con su traza (sin trazador en la prueba, sin pasos calculados)
    t1 = next(e.contenido for e in r.elementos if e.contenido["uid"] == "job42-tc-t1")
    assert "### Ejecución paso a paso" in t1["enunciado_md"]


async def test_la_clase_trae_sus_tareas_t5_a_t8_en_la_misma_propuesta(diseno):  # noqa: F811
    clase = propuesta_clase(diseno)
    clase["soporte"], clase["procedimental"] = [], []
    reto = copy.deepcopy(clase["tareas"][2]) | {
        "uid": "x1", "titulo": "Inventario del club de robótica", "colaborativa": True,
        "enunciado_md": "**Contexto.** El club de robótica cuenta sus piezas. **Integrante 1:** lee las piezas.",
        "solucion": '#include <stdio.h>\nint main(void) { int piezas, cajas; scanf("%d %d", &piezas, &cajas); printf("%d\n", piezas * cajas); return 0; }',
    }
    proveedor = ProveedorFalso([clase, {"advertencias": ["Revisa el reto."], "tareas": [reto]}])
    ajustes = Ajustes(proveedor="claude", tareas_complementarias=True, max_intentos=3)
    r = await Orquestador(proveedor, None, ajustes).generar(solicitud(diseno, "clase_tareas"))

    tareas = [e.contenido for e in r.elementos if e.tipo == "tarea"]
    assert [(t["uid"], t["orden"]) for t in tareas][-1] == ("job42-tc-t4", 4)  # sigue la numeración de la clase
    assert tareas[-1]["clase_uid"] == "job42-tc" and tareas[-1]["colaborativa"]
    assert "Tareas T5 a T8: Revisa el reto." in r.advertencias and r.uso.entrada == 2000
    # La segunda solicitud ve en el diseño la clase nueva y sus primeras tareas
    assert "job42-tc-t3" in proveedor.llamadas[1]["mensajes"][0]["content"]


async def test_si_fallan_t5_a_t8_la_clase_llega_igual(diseno):  # noqa: F811
    # Corrida del 2026-10-06: Ollama abortó T5 a T8 («token repeat limit reached») y se perdía también la clase
    class FallaLaSegunda(ProveedorFalso):
        async def generar(self, sistema, mensajes, salida, modelo, max_tokens):
            if self.llamadas:
                raise ProveedorNoDisponible("Ollama no responde")
            return await super().generar(sistema, mensajes, salida, modelo, max_tokens)

    clase = propuesta_clase(diseno)
    clase["soporte"], clase["procedimental"] = [], []
    r = await Orquestador(FallaLaSegunda([clase]), None, Ajustes(proveedor="claude", tareas_complementarias=True)).generar(
        solicitud(diseno, "clase_tareas"))
    assert len([e for e in r.elementos if e.tipo == "tarea"]) == 3
    assert any("No se pudo generar «Tareas T5 a T8»" in a for a in r.advertencias)


def test_autoexplicacion_e_imaginacion_reciben_su_codigo_y_un_caso():
    sin_solucion = tarea("t6", "", [], nivel="ejemplo_resuelto")
    sin_solucion.contenido |= {"pide_autoexplicacion": True, "codigo_inicial": MAXIMO, "casos_prueba": []}
    sin_inicial = tarea("t7", MAXIMO, [("", "")], nivel="ejemplo_resuelto")
    sin_inicial.contenido |= {"pide_autoexplicacion": True, "codigo_inicial": ""}
    codigo_dado([sin_solucion, sin_inicial])
    assert sin_solucion.contenido["solucion"] == MAXIMO and sin_solucion.contenido["casos_prueba"] == [
        {"entrada": "", "salida_esperada": "", "oculto": False}]
    assert sin_inicial.contenido["codigo_inicial"] == MAXIMO


def test_un_titulo_que_solo_dice_el_nivel_no_cuenta_como_el_mismo_ejercicio():
    a = tarea("t1", SUMA, [("1 2", "3")], nivel="ejemplo_resuelto")
    a.contenido |= {"titulo": "Ejemplo resuelto", "enunciado_md": "Registro de una mascota en la veterinaria."}
    b = tarea("t4", MAXIMO, [("1 2", "3")])
    b.contenido |= {"titulo": "Problema convencional", "enunciado_md": "Credencial escolar con bordes y datos alineados."}
    assert tareas_distintas([a, b]) == []


def test_items_sin_cercos_ni_repetidos():
    # Evaluación del 2026-10-06: el código venía en ```cpp … ``` y el mismo ítem de predicción llegó seis veces
    from app.etapas import limpiar_items, sin_cerco

    prediccion = {"tipo": "prediccion_salida", "enunciado_md": "¿Cuál es la salida?", "codigo_inicial": "```cpp\nint main() {}\n```", "solucion": ""}
    items = [prediccion, dict(prediccion), {"tipo": "respuesta_corta", "enunciado_md": "¿Qué tipo usarías?", "codigo_inicial": ""}]
    (aviso,) = limpiar_items(items, "cpp")
    assert [it["tipo"] for it in items] == ["prediccion_salida", "respuesta_corta"] and "1 ítems repetidos" in aviso.detalle
    assert items[0]["codigo_inicial"].startswith("#include <iostream>") and items[0]["codigo_inicial"].endswith("int main() {}")
    assert sin_cerco("int x = 3;") == "int x = 3;" and sin_cerco("  ```\nprint(1)\n```  ") == "print(1)"
    assert limpiar_items(items, "cpp") == []


def test_sin_calculos_los_huecos_son_la_lectura_y_la_declaracion():
    # Corrida del 2026-10-06: «Declarar y asignar variables para el nombre de un estudiante», sin asignaciones ni if
    nombre = '#include <iostream>\n#include <string>\nint main() {\n    std::string nombre;\n    int edad;\n' \
             '    std::cin >> nombre >> edad;\n    std::cout << nombre << " " << edad;\n    return 0;\n}'
    con_huecos = huecos_desde_solucion(nombre, "cpp")
    assert "/* HUECO 1: declara nombre con el tipo adecuado */" in con_huecos
    assert "/* HUECO 2: lee nombre y edad desde la entrada */" in con_huecos
    assert "std::cout << nombre" in con_huecos  # la salida se queda: es lo que comprueba el estudiante
    assert "HUECO 1: lee texto desde la entrada" in huecos_desde_solucion('texto = input()\nprint(texto)', "python")


def test_la_imaginacion_recibe_el_programa_completo_y_no_las_lineas_sueltas():
    t = tarea("t7", "#include <iostream>\nint main() {\n    int x = 5;\n    std::cout << x;\n}", [], nivel="ejemplo_resuelto")
    t.contenido |= {"pide_autoexplicacion": True, "codigo_inicial": "int x = 5;\nx = x + 1;"}
    codigo_dado([t])
    assert t.contenido["codigo_inicial"] == t.contenido["solucion"]


def test_un_ejemplo_o_una_solucion_en_lineas_sueltas_se_vuelve_programa():
    # Cuarta corrida del 2026-10-06: «cin >> altura_metros;» sin main en el ejemplo resuelto
    from app.etapas import programas_completos

    t = tarea("t1", "#include <iostream>\nusing namespace std;\ndouble a;\ncin >> a;\ncout << a * 2;", [], nivel="ejemplo_resuelto")
    t.contenido["codigo_inicial"] = "double m;\ncin >> m;"
    completar = tarea("t2", "int main() { return 0; }", [], nivel="por_completar")
    completar.contenido["codigo_inicial"] = "/* HUECO 1: lee */\ncout << x;"
    programas_completos([t, completar], "cpp")
    sol = t.contenido["solucion"]
    assert sol.count("#include <iostream>") == 1 and sol.count("using namespace std;") == 1
    assert "int main() {\n    double a;\n    cin >> a;\n    cout << a * 2;\n    return 0;\n}" in sol
    assert "int main() {\n    double m;\n    cin >> m;" in t.contenido["codigo_inicial"]
    assert completar.contenido["codigo_inicial"] == "/* HUECO 1: lee */\ncout << x;"  # los huecos no se tocan


def test_los_items_ejecutan_el_programa_que_ve_el_estudiante():
    # Cuarta corrida del 2026-10-06: el enunciado traía el programa y el código solo unas líneas
    from app.etapas import limpiar_items

    programa = "#include <iostream>\nint main() {\n    std::cout << 175 / 100;\n}"
    prediccion = {"tipo": "prediccion_salida", "enunciado_md": f"Predice:\n\n```cpp\n{programa}\n```", "codigo_inicial": "cout << 175 / 100;"}
    escribir = {"tipo": "programacion", "enunciado_md": "Convierte a metros.", "solucion": "int cm;\ncin >> cm;\ncout << cm / 100;"}
    limpiar_items([prediccion, escribir], "cpp")
    assert prediccion["codigo_inicial"] == programa
    assert "int main() {\n    int cm;\n    cin >> cm;" in escribir["solucion"]


def test_los_datos_de_cada_caso_corresponden_a_lo_que_lee_el_programa():
    # Ejemplo del 2026-10-06: «20.5» iba a un char y «A» a un bool; la salida calculada era «Nombre: .5, Genero: 2»
    from app.etapas import desajuste_entrada

    paciente = ("#include <iostream>\n#include <string>\nusing namespace std;\nint main() {\n    int edad;\n    double peso;\n"
                "    char genero;\n    bool tiene_ahorro;\n    string nombre;\n    cin >> edad;\n    cin >> peso;\n    cin >> genero;\n"
                "    cin >> tiene_ahorro >> nombre;\n    cout << nombre << edad;\n}")
    assert desajuste_entrada(paciente, "3\n10\n20.5\nA\ntrue") == "el dato 3 («20.5») va a «genero», que espera un solo carácter"
    assert desajuste_entrada(paciente, "25 70.5 F 1 Ana") is None
    assert desajuste_entrada(paciente, "25 70.5 F 1") == "la entrada trae 4 datos y el programa lee 5"
    assert desajuste_entrada(paciente, "25 70.5 F true Ana").startswith("el dato 4 («true»)")
    # Con ciclos o getline no se adivina: no se revisa
    assert desajuste_entrada("int main() { int n; cin >> n; for (int i = 0; i < n; i++) {} }", "x") is None


async def test_un_caso_con_datos_desajustados_bloquea_y_despues_se_quita():
    from app.etapas import calcular_salidas

    class PistonEco:
        async def ejecutar(self, _lenguaje, _codigo, entrada=""):
            entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
            return Ejecucion(True, entrada + "\n", "", 0, False)

    sol = "#include <iostream>\nint main() {\n    int a;\n    char c;\n    std::cin >> a >> c;\n    std::cout << a << c;\n}"
    t = tarea("t1", sol, [("1 x", ""), ("2 y", ""), ("3.5 z", "")])
    (error,) = await calcular_salidas([t], "cpp", PistonEco())
    assert error.nombre == "entrada_desajustada" and error.bloqueante and "«3.5»" in error.detalle
    (aviso,) = [v for v in await calcular_salidas([t], "cpp", PistonEco(), quitar_fallidos=True) if v.nombre == "casos_quitados"]
    assert [c["entrada"] for c in t.contenido["casos_prueba"]] == ["1 x", "2 y"] and not aviso.bloqueante


def test_el_texto_con_espacios_se_lee_con_getline():
    # Tarea del 2026-10-06: «Super Mario» con cin >> nombre imprimía «Nombre: Super», «Género: Mario», «Jugadores: 0»
    from app.etapas import desajuste_entrada, getline_para_texto

    sol = ("#include <iostream>\nusing namespace std;\nint main() {\n    string nombre;\n    string genero;\n    int jugadores;\n"
           "    cin >> nombre;\n    cin >> genero;\n    cin >> jugadores;\n    cout << nombre << genero << jugadores;\n}")
    t = tarea("t2", sol, [("Super Mario\nAventura\n2", ""), ("Minecraft\nSandbox\n4", "")], nivel="por_completar")
    t.contenido["codigo_inicial"] = sol.replace("    cin >> jugadores;\n", "    /* HUECO 1: lee jugadores */\n")
    assert desajuste_entrada(sol, "Super Mario\nAventura\n2") is not None
    (aviso,) = getline_para_texto([t], "cpp")
    assert "getline(cin >> ws, nombre);" in t.contenido["solucion"] and "cin >> genero;" in t.contenido["solucion"]
    assert "getline(cin >> ws, nombre);" in t.contenido["codigo_inicial"] and "nombre" in aviso.detalle
    assert getline_para_texto([t], "cpp") == []  # con getline ya no se revisa ni se repara
