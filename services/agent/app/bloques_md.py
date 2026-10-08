"""Bloques del material que el sistema llena ejecutando el código (forma del «Mapa de ruta CLT4BP», ADR 0007).

Igual que los pasos de una traza, ni la salida de un programa ni el mensaje del compilador los escribe el modelo: los
obtiene Piston. Cada bloque toma el último bloque de código que lo precede en el mismo Markdown.

    ```cpp                         ```cpp
    int main() { … }               int main() { int x = 3.5 }
    ```                            ```
    ```salida                      ```compilador
    entrada: Luis\\n16 1.72        (vacío: lo llena el sistema)
    ---                            ```
    (lo llena el sistema)
    ```
"""

import re

from app.ejecutor import Ejecutor, normalizar

BLOQUE = re.compile(r"```[ \t]*([\w+-]*)[^\n]*\n(.*?)```", re.S)
LENGUAJE_BLOQUE = {"c": "c", "cpp": "cpp", "c++": "cpp", "python": "python", "py": "python"}
EXTENSION = {"c": "c", "cpp": "cpp", "python": "py"}


def leer_entrada(contenido: str) -> str:
    """La entrada de un bloque ```salida: la línea «entrada: …» antes de «---» (\\n separa renglones). El modelo a veces
    escribe el «---» en la misma línea («entrada: 2 ---»): no es parte de la entrada."""
    cabecera = contenido.split("\n---", 1)[0] if re.search(r"^---\s*$", contenido, re.M) else contenido.split("\n", 1)[0]
    m = re.search(r"^\s*entrada:\s*(.*)$", cabecera, re.M | re.I)
    return re.sub(r"\s*-{3,}\s*$", "", m.group(1).strip()).replace("\\n", "\n") if m else ""


LEE_DATOS = re.compile(r"\b(cin|scanf|getline|getchar|fgets|input)\b")
LEE_HASTA_EL_FIN = re.compile(r"while\s*\(\s*(std::)?(cin\s*>>|getline\s*\(|scanf\s*\(|fgets\s*\()|sys\.stdin|EOF")
DATOS_DE_SOBRA = "\n" + " ".join(["9"] * 24)


async def lee_de_mas(ejecutor: Ejecutor, lenguaje: str, codigo: str, entrada: str, salida: str) -> bool:
    """Si a la entrada le faltan datos, el programa lee los que le sobran a otra igual con datos de más al final, e
    imprime otra cosa (ejemplo isomórfico del 2026-10-06: «entrada: 2» para dos notas imprimía «Promedio: 0»; ejemplo
    resuelto sin entrada: «Promedio: -nan»). Un programa que lee hasta el fin de la entrada no se revisa: esos datos de más
    son suyos."""
    if not LEE_DATOS.search(codigo) or LEE_HASTA_EL_FIN.search(codigo):
        return False
    r = await ejecutor.ejecutar(lenguaje, codigo, entrada + DATOS_DE_SOBRA)
    return r.compilo and normalizar(r.salida) != normalizar(salida)


def escribir_salida(entrada: str, salida: str) -> str:
    cabecera = f"entrada: {entrada.replace(chr(10), chr(92) + 'n')}\n---\n" if entrada else ""
    return f"{cabecera}{normalizar(salida)}\n"


def limpiar_compilador(mensaje: str, lenguaje: str) -> str:
    """El mensaje de g++ como lo vería el estudiante: sin rutas de Piston, con su archivo «main.cpp»."""
    texto = re.sub(r"[^\s:]*file0\.code(\.\w+)?", f"main.{EXTENSION.get(lenguaje, 'cpp')}", mensaje)
    texto = re.sub(r"^.*(compilation terminated|In function).*\n?", "", texto, flags=re.M)
    # Lo que sigue al error es ruido de Piston o de la biblioteca estándar (errores frecuentes del 2026-10-06):
    # «chmod: cannot access 'a.out'» y «In file included from…/piston/packages/gcc/…/iostream: note: …»
    texto = re.split(r"^(?:chmod:|In file included from|.*/piston/)", texto, maxsplit=1, flags=re.M)[0]
    return "\n".join(texto.strip("\n").split("\n")[:8]) + "\n"


CABECERAS = {
    "cpp": "#include <iostream>\n#include <iomanip>\n#include <string>\n#include <cmath>\n#include <climits>\nusing namespace std;\n",
    "c": "#include <stdio.h>\n#include <stdlib.h>\n#include <string.h>\n#include <math.h>\n#include <limits.h>\n",
}


def ejecutable(lenguaje: str, codigo: str) -> str:
    """Un fragmento de C o C++ (sin main) se ejecuta dentro de un main con las bibliotecas comunes: así su salida se
    calcula aunque el material muestre solo las líneas que explica. Los #include y `using` del fragmento van arriba,
    fuera del main. En el material, el fragmento se muestra tal cual."""
    if lenguaje not in CABECERAS:
        return codigo
    if re.search(r"\bmain\s*\(", codigo):
        # Un programa con su main pero sin bibliotecas (ítems del 2026-10-06) no compila: se le ponen las comunes
        return codigo if re.search(r"^\s*#\s*include\b", codigo, re.M) else CABECERAS[lenguaje] + codigo
    arriba, cuerpo = [], []
    for linea in codigo.strip("\n").split("\n"):
        (arriba if re.match(r"\s*(#\s*include|using\s+namespace)\b", linea) else cuerpo).append(linea)
    extra = "".join(f"{linea.strip()}\n" for linea in arriba if linea.strip() not in CABECERAS[lenguaje])
    principal = "int main()" if lenguaje == "cpp" else "int main(void)"
    instrucciones = "\n".join(f"    {linea}" if linea.strip() else "" for linea in cuerpo)
    return f"{extra}{CABECERAS[lenguaje]}{principal} {{\n{instrucciones}\n    return 0;\n}}\n"


PROGRAMA_COMPLETO = re.compile(r"\bmain\s*\(|\binput\s*\(|\bprint\s*\(")
ENTRADA_EN_PROSA = re.compile(r"^\s*\**\s*Entrada\s*:?\s*\**\s*:?\s*(.*(?:\n(?!\s*\n|\s*\**\s*Salida)[^\n`#]*)*)", re.I | re.M)
SALIDA_EN_PROSA = re.compile(r"^\s*\**\s*Salida(?: esperada)?\s*:?\s*\**\s*:?.*(?:\n(?!\s*\n)[^\n`#]*)*\n?", re.I | re.M)


def asegurar_salidas(md: str) -> str:
    """Todo programa completo del material lleva su bloque ```salida: si el modelo la escribió en prosa («**Salida
    esperada:** …», casi siempre inventada), se quita y se pone el bloque, con la entrada que diga el texto; el sistema
    lo llena después ejecutando el programa. No se usa en los errores frecuentes: ahí el código falla a propósito."""
    partes, fin = [], 0
    bloques = list(BLOQUE.finditer(md))
    for i, m in enumerate(bloques):
        tipo, contenido = m.group(1).lower(), m.group(2)
        if tipo not in LENGUAJE_BLOQUE or not PROGRAMA_COMPLETO.search(contenido):
            continue
        siguiente = bloques[i + 1] if i + 1 < len(bloques) else None
        if siguiente and siguiente.group(1).lower() in ("salida", "compilador") and not md[m.end():siguiente.start()].strip():
            continue
        # La prosa que sigue al programa (hasta el siguiente bloque): de ahí sale la entrada; la salida inventada se va
        hasta = siguiente.start() if siguiente else len(md)
        prosa = md[m.end():hasta]
        entrada = ENTRADA_EN_PROSA.search(prosa)
        texto_entrada = " ".join(entrada.group(1).replace("*", "").split()) if entrada else ""
        prosa = SALIDA_EN_PROSA.sub("", ENTRADA_EN_PROSA.sub("", prosa)) if entrada else SALIDA_EN_PROSA.sub("", prosa)
        cabecera = f"entrada: {texto_entrada}\n---\n" if texto_entrada else ""
        partes += [md[fin:m.end()], f"\n```salida\n{cabecera}```\n", prosa]
        fin = hasta
    partes.append(md[fin:])
    return "".join(partes)


async def completar_bloques(md: str, lenguaje_curso: str, ejecutor: Ejecutor, quitar_si_falla: bool = False,
                            base: str | None = None) -> tuple[str, list[str]]:
    """Llena los bloques ```salida y ```compilador. Devuelve el Markdown nuevo y lo que no se pudo llenar; con
    `quitar_si_falla`, el bloque que falla se quita (tras el último intento, para no bloquear todo el material).
    `base`: el programa para un bloque sin código antes (el ejemplo de ejecución de una tarea sale de su solución)."""
    partes, errores, fin = [], [], 0
    # (lenguaje, código) del último bloque de código visto
    codigo: tuple[str, str] | None = (lenguaje_curso, base) if base and base.strip() else None
    for m in BLOQUE.finditer(md):
        tipo, contenido = m.group(1).lower(), m.group(2)
        if tipo in LENGUAJE_BLOQUE:
            codigo = (LENGUAJE_BLOQUE[tipo], contenido)
            continue
        if tipo not in ("salida", "compilador"):
            continue
        nuevo, error = None, None
        if codigo is None:
            error = f"un bloque ```{tipo} no tiene un programa antes"
        else:
            lenguaje, programa = codigo
            entrada = leer_entrada(contenido) if tipo == "salida" else ""
            if not re.search(r"\b(cin|scanf|getline|getchar|fgets|input)\b", programa):
                entrada = ""  # «entrada: 25» junto a un programa que no lee nada confunde al estudiante (soporte del 2026-10-06)
            r = await ejecutor.ejecutar(lenguaje or lenguaje_curso, ejecutable(lenguaje or lenguaje_curso, programa), entrada)
            if tipo == "salida":
                if not r.compilo:
                    error = f"el programa antes de un bloque ```salida no compila: {r.errores.strip()[:200]}"
                elif r.excedio_limite:
                    error = "el programa antes de un bloque ```salida no termina (¿un ciclo infinito o le falta entrada?)"
                elif normalizar((await ejecutor.ejecutar(lenguaje or lenguaje_curso, ejecutable(lenguaje or lenguaje_curso, programa),
                                                         entrada)).salida) != normalizar(r.salida):
                    # Imprimía «x = 6.95e-310» con «entrada: 3» y otra cosa al repetir: leyó datos que la entrada no trae
                    error = ("la salida del programa antes de un bloque ```salida cambia en cada ejecución: su «entrada:» no "
                             "trae todos los datos que lee, o usa una variable sin valor inicial")
                elif await lee_de_mas(ejecutor, lenguaje or lenguaje_curso, ejecutable(lenguaje or lenguaje_curso, programa),
                                      entrada, r.salida):
                    error = ("el programa antes de un bloque ```salida lee más datos de los que trae su «entrada:» (escribe en "
                             "ella TODOS los datos que lee, en orden: si n = 2, n y luego los 2 valores)")
                elif not normalizar(r.salida).strip():
                    nuevo = ""  # un fragmento que no imprime nada (`int edad = 20;`): su consola vacía no enseña, se quita
                else:
                    nuevo = escribir_salida(entrada, r.salida)
            elif r.compilo:
                error = ("un bloque ```compilador sigue a un programa que sí compila: el ejemplo de error debe tener el "
                         "error (o usa ```salida si el programa compila y falla al ejecutarse)")
            else:
                nuevo = limpiar_compilador(r.errores, lenguaje)
        partes.append(md[fin:m.start()].rstrip("\n") + "\n" if nuevo == "" else md[fin:m.start()])
        if nuevo:
            partes.append(f"```{tipo}\n{nuevo}```")
        elif nuevo is None and not quitar_si_falla:
            partes.append(m.group(0))
        if error:
            errores.append(error)
        fin = m.end()
    partes.append(md[fin:])
    return "".join(partes), errores


# Dónde todo programa completo debe ir con su salida real: el soporte y, de lo procedimental, el ejemplo isomórfico y el
# protocolo (los errores frecuentes fallan a propósito; las tareas no muestran la salida de su solución)
CON_SALIDA = {"soporte": None, "procedimental": {"ejemplo_isomorfico", "protocolo_verbal"}}


def asegurar_salidas_elementos(elementos: list) -> None:
    for e in elementos:
        tipos = CON_SALIDA.get(e.tipo, ())
        if tipos is not None and e.contenido.get("tipo") not in tipos:
            continue
        md = e.contenido.get("cuerpo_md")
        if isinstance(md, str) and "```" in md:
            e.contenido["cuerpo_md"] = asegurar_salidas(md)
