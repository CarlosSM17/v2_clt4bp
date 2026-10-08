"""Salidas y mensajes del compilador que el sistema escribe ejecutando el código (forma del mapa de ruta)."""

from app.bloques_md import asegurar_salidas, completar_bloques, leer_entrada, limpiar_compilador
from app.bloques_md import DATOS_DE_SOBRA
from app.ejecutor import Ejecucion

PROGRAMA = '#include <iostream>\nint main() {\n    int edad = 17; //→ crea la caja\n    std::cout << edad << "\\n";\n}'
CON_ERROR = '#include <iostream>\nint main() {\n    total = 5;\n}'


class PistonFalso:
    """Compila todo menos lo que usa «total» sin declarar; repite la entrada después de «eco:»."""

    def __init__(self):
        self.llamadas = []

    async def ejecutar(self, lenguaje, codigo, entrada=""):
        entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
        self.llamadas.append((lenguaje, entrada))
        if "total = 5" in codigo:
            return Ejecucion(False, "", "/piston/jobs/x/file0.code.cpp: In function 'int main()':\n"
                                        "/piston/jobs/x/file0.code.cpp:3:5: error: 'total' was not declared in this scope\n", None, False)
        return Ejecucion(True, f"17\neco: {entrada}\n" if entrada else "17\n", "", 0, False)


async def test_la_salida_la_escribe_el_sistema_con_su_entrada():
    lee = PROGRAMA.replace("int edad = 17;", "std::string nombre; int edad; std::cin >> nombre >> edad;")
    md = f"Mira:\n```cpp\n{lee}\n```\n```salida\nentrada: Luis\\n16\n---\nlo que inventó el modelo\n```\nFin."
    piston = PistonFalso()
    nuevo, errores = await completar_bloques(md, "cpp", piston)
    # Tres veces: dos para ver que la salida no cambia y una con datos de más, para ver que la entrada está completa
    assert errores == [] and piston.llamadas == [("cpp", "Luis\n16")] * 3
    assert "```salida\nentrada: Luis\\n16\n---\n17\neco: Luis\n16\n```\nFin." in nuevo
    assert "lo que inventó el modelo" not in nuevo


async def test_sin_entrada_solo_va_la_salida():
    nuevo, _ = await completar_bloques(f"```cpp\n{PROGRAMA}\n```\n```salida\n```", "cpp", PistonFalso())
    assert nuevo.endswith("```salida\n17\n```")


async def test_el_mensaje_del_compilador_es_el_real_y_sin_rutas():
    md = f"**a) Usar una variable que no se declaró.**\n```cpp\n{CON_ERROR}\n```\n```compilador\n```\n*Corrección:* declárala."
    nuevo, errores = await completar_bloques(md, "cpp", PistonFalso())
    assert errores == []
    assert "```compilador\nmain.cpp:3:5: error: 'total' was not declared in this scope\n```" in nuevo


async def test_lo_que_no_cuadra_se_informa_y_al_final_se_quita():
    # Un «error» que compila, y una salida que no tiene programa antes
    md = f"```cpp\n{PROGRAMA}\n```\n```compilador\n```\n"
    _, (error,) = await completar_bloques(md, "cpp", PistonFalso())
    assert "sí compila" in error
    nuevo, (otro,) = await completar_bloques("Texto\n```salida\n7\n```\n", "cpp", PistonFalso(), quitar_si_falla=True)
    assert "no tiene un programa antes" in otro and "```salida" not in nuevo


def test_entrada_y_limpieza():
    assert leer_entrada("entrada: 3 10\\n20\n---\nx") == "3 10\n20"
    assert leer_entrada("solo salida") == ""
    assert limpiar_compilador("/tmp/file0.code.c:4:16: error: x\ncompilation terminated.\n", "c") == "main.c:4:16: error: x\n"
    # Sin el ruido de Piston ni las notas de la biblioteca estándar
    con_ruido = ("/piston/jobs/x/file0.code.cpp:3:5: error: 'cin' was not declared in this scope\n    3 |     cin >> x;\n"
                 "      |     ^~~\nIn file included from /piston/jobs/x/file0.code.cpp:1:\n"
                 "/piston/packages/gcc/10.2.0/include/c++/10.2.0/iostream:60:18: note: 'std::cin' declared here\n"
                 "chmod: cannot access 'a.out': No such file or directory\n")
    assert limpiar_compilador(con_ruido, "cpp") == (
        "main.cpp:3:5: error: 'cin' was not declared in this scope\n    3 |     cin >> x;\n      |     ^~~\n")
    assert limpiar_compilador("main.cpp:4:5: error: expected ';'\nchmod: cannot access 'a.out'\n", "cpp") == "main.cpp:4:5: error: expected ';'\n"


async def test_un_fragmento_sin_main_se_ejecuta_dentro_de_uno():
    class PistonQueExigeMain:
        def __init__(self):
            self.codigo = ""

        async def ejecutar(self, _lenguaje, codigo, entrada=""):
            entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
            self.codigo = codigo
            return Ejecucion(True, "20\n", "", 0, False) if "int main()" in codigo else Ejecucion(False, "", "sin main", None, False)

    piston = PistonQueExigeMain()
    md = "```cpp\nint a = 20.5; //→ se trunca\ncout << a << endl;\n```\n```salida\n```"
    nuevo, errores = await completar_bloques(md, "cpp", piston)
    assert errores == [] and nuevo.endswith("```salida\n20\n```")
    assert "#include <iostream>" in piston.codigo and "using namespace std;" in piston.codigo
    assert nuevo.startswith("```cpp\nint a = 20.5; //→ se trunca\n")  # se muestra el fragmento, no el envoltorio


def test_un_programa_sin_bloque_de_salida_lo_recibe_y_se_quita_la_inventada():
    # Ejemplo resuelto del 2026-10-06: «Entrada» y «Salida esperada» en prosa, con una salida que el programa no da
    md = ("### C. Código\n\n```cpp\nint main() {\n    int n; cin >> n;\n}\n```\n\n### D. Verificación\n\n"
          "**Entrada:** 3\n10\n\n**Salida esperada:** n = 3, x = 20.000000\n\nCon esto se comprueba.")
    nuevo = asegurar_salidas(md)
    assert "```salida\nentrada: 3 10\n---\n```" in nuevo
    assert "20.000000" not in nuevo and "Con esto se comprueba." in nuevo
    # Un programa que ya trae su bloque, o un fragmento sin main, no cambia
    con_bloque = "```cpp\nint main() {}\n```\n```salida\n```"
    assert asegurar_salidas(con_bloque) == con_bloque
    assert asegurar_salidas("```cpp\nint x = 3;\n```") == "```cpp\nint x = 3;\n```"


async def test_una_salida_que_cambia_entre_ejecuciones_no_se_escribe():
    # Protocolo verbal del 2026-10-06: «entrada: 3» para un programa que lee cuatro datos → «x = 6.95302e-310»
    class PistonConBasura:
        def __init__(self):
            self.vez = 0

        async def ejecutar(self, _lenguaje, _codigo, entrada=""):
            entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
            self.vez += 1
            return Ejecucion(True, f"n = 3, x = {self.vez}e-310\n", "", 0, False)

    md = f"```cpp\n{PROGRAMA}\n```\n```salida\nentrada: 3\n---\n```"
    nuevo, (error,) = await completar_bloques(md, "cpp", PistonConBasura())
    assert "cambia en cada ejecución" in error and "e-310" not in nuevo


async def test_un_fragmento_que_no_imprime_nada_pierde_su_bloque_de_salida():
    # Soporte del 2026-10-06: «int edad = 20;» con un bloque de salida que quedaba vacío
    class PistonMudo:
        async def ejecutar(self, _lenguaje, _codigo, entrada=""):
            entrada = entrada.removesuffix(DATOS_DE_SOBRA)  # el programa lee solo lo que necesita
            return Ejecucion(True, "", "", 0, False)

    md = "### 1. Tipos\n\n```cpp\nint edad = 20;\n```\n\n```salida\nentrada: 20\n---\n```\n\n### 2. Variables"
    nuevo, errores = await completar_bloques(md, "cpp", PistonMudo())
    assert errores == [] and "```salida" not in nuevo
    assert nuevo == "### 1. Tipos\n\n```cpp\nint edad = 20;\n```\n\n\n### 2. Variables"


async def test_un_programa_que_no_lee_nada_no_muestra_entrada():
    # Soporte del 2026-10-06: «Entrada: 25» junto a «int edad = 25;»
    md = "```cpp\nint main() {\n    int edad = 25;\n    std::cout << edad;\n}\n```\n```salida\nentrada: 25\n---\n```"
    piston = PistonFalso()
    nuevo, errores = await completar_bloques(md, "cpp", piston)
    assert errores == [] and nuevo.endswith("```salida\n17\n```") and piston.llamadas[0] == ("cpp", "")


async def test_una_entrada_con_menos_datos_de_los_que_lee_el_programa_se_detecta():
    # Ejemplo isomórfico del 2026-10-06: «entrada: 2 ---» para un promedio de 2 notas imprimía «Promedio: 0»
    class PistonPromedio:
        async def ejecutar(self, _lenguaje, _codigo, entrada=""):
            datos = [float(x) for x in entrada.split()]
            n, notas = int(datos[0]), datos[1:]
            leidas = notas[:n] + [0.0] * (n - len(notas[:n]))  # cin falla en silencio: lo que falta queda en cero
            return Ejecucion(True, f"Promedio: {sum(leidas) / n:g}\n", "", 0, False)

    promedio = "```cpp\nint main() {\n    int n;\n    std::cin >> n;\n}\n```\n"
    _, (error,) = await completar_bloques(promedio + "```salida\nentrada: 2 ---\n```", "cpp", PistonPromedio())
    assert "lee más datos de los que trae" in error
    nuevo, errores = await completar_bloques(promedio + "```salida\nentrada: 2 8 9\n---\n```", "cpp", PistonPromedio())
    assert errores == [] and "Promedio: 8.5" in nuevo
    assert leer_entrada("entrada: 2 ---") == "2"
