"""Pruebas del trazador con gcc, gdb y Python reales. Se corren dentro del contenedor:
    docker exec clt4bp-trazador python3 -m unittest discover -s /app/pruebas -v
"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parents[1]))
from servidor import trazar  # noqa: E402

SUMA_C = """#include <stdio.h>
int main(void) {
    int n, x, s = 0;
    scanf("%d", &n);
    for (int i = 0; i < n; i++) {
        scanf("%d", &x);
        s += x;
    }
    printf("total: %d\\n", s);
    return 0;
}
"""

# El caso del trabajo 8 de «Programación I»: la traza del modelo recorría las líneas 1 a 8 en orden de texto
PROMEDIO_CPP = """#include <iostream>
int calcular_promedio(int *arr, int n) {
    int suma = 0;
    for (int i = 0; i < n; i++) {
        suma += arr[i];
    }
    return suma / n;
}
int main() {
    int arr[] = {10, 20, 30};
    int n = sizeof(arr) / sizeof(arr[0]);
    std::cout << calcular_promedio(arr, n) << std::endl;
    return 0;
}
"""


def lineas(r: dict) -> list[int]:
    return [p["l"] for p in r["pasos"] if not p.get("fin")]


def salida(r: dict) -> str:
    return "".join(p["s"] for p in r["pasos"])


class TrazadorC(unittest.TestCase):
    def test_sigue_el_orden_real_de_ejecucion(self):
        r = trazar("c", SUMA_C, "3 10 20 30")
        self.assertIsNone(r["error"])
        self.assertEqual(lineas(r)[:2], [3, 4])  # empieza en main, no en la línea 1
        self.assertEqual(lineas(r).count(7), 3)  # el cuerpo del ciclo, una vez por vuelta
        self.assertEqual(salida(r), "total: 60\n")
        self.assertTrue(r["pasos"][-1]["fin"])

    def test_variables_en_su_valor_y_solo_las_ya_declaradas(self):
        r = trazar("c", SUMA_C, "3 10 20 30")
        primero = dict(r["pasos"][0]["v"])
        self.assertNotIn("s", primero)  # en la línea 3 aún no existe: se declara ahí
        al_imprimir = next(p for p in r["pasos"] if p["l"] == 9)
        self.assertEqual(dict(al_imprimir["v"])["s"], "60")

    def test_una_variable_sin_valor_inicial_muestra_interrogacion_hasta_que_se_lee(self):
        r = trazar("c", SUMA_C, "3 10 20 30")
        al_leer_n = dict(r["pasos"][1]["v"])  # línea 4, el scanf de n: n y x se declararon sin valor; s = 0 sí
        self.assertEqual((r["pasos"][1]["l"], al_leer_n["n"], al_leer_n["x"], al_leer_n["s"]), (4, "?", "?", "0"))
        en_el_for = dict(r["pasos"][2]["v"])
        self.assertEqual((en_el_for["n"], en_el_for["x"]), ("3", "?"))  # n ya se leyó; x todavía no
        memoria = {c["n"]: c for c in r["pasos"][2]["m"][0]["v"]}
        self.assertEqual((memoria["n"]["x"], memoria["x"]["x"]), ("3", "?"))
        al_sumar = next(dict(p["v"]) for p in r["pasos"] if p["l"] == 7)
        self.assertEqual(al_sumar["x"], "10")

    def test_la_salida_aparece_en_el_paso_siguiente_a_la_linea_que_la_imprime(self):
        r = trazar("c", SUMA_C, "3 10 20 30")
        indice = next(i for i, p in enumerate(r["pasos"]) if p["l"] == 9)
        self.assertEqual(r["pasos"][indice]["s"], "")  # antes de ejecutar el printf no hay salida
        self.assertEqual(r["pasos"][indice + 1]["s"], "total: 60\n")

    def test_un_ciclo_infinito_se_corta(self):
        # En varias líneas: el límite de pasos
        r = trazar("c", "int main(void) {\n    int i = 0;\n    while (1) {\n        i++;\n        i--;\n    }\n}\n", "")
        self.assertTrue(r["truncada"])
        self.assertEqual(len(r["pasos"]), 300)
        # En una sola línea «step» de gdb nunca regresa: lo corta el límite de CPU y se dice por qué
        r = trazar("c", "int main(void) {\n    int i = 0;\n    while (1) {\n        i++;\n    }\n}\n", "")
        self.assertTrue(r["truncada"], r)
        self.assertIn("ciclo que no termina", r["error"])

    def test_un_puntero_invalido_se_ve_en_la_traza(self):
        r = trazar("c", '#include <stdio.h>\nint main(void) {\n    int *p = 0;\n    printf("antes\\n");\n    *p = 5;\n    return 0;\n}\n', "")
        self.assertIn("SIGSEGV", r["error"])
        self.assertEqual("".join(p["s"] for p in r["pasos"]), "antes\n")
        self.assertEqual(r["pasos"][-1]["l"], 5)  # la línea que falla es la última que se ve

    def test_un_error_de_compilacion_se_informa(self):
        r = trazar("c", "int main(void) { return x; }", "")
        self.assertTrue(r["error"].startswith("no compila"))


class TrazadorCpp(unittest.TestCase):
    def test_entra_a_la_funcion_y_no_a_iostream(self):
        r = trazar("cpp", PROMEDIO_CPP, "")
        self.assertIsNone(r["error"])
        self.assertEqual(lineas(r)[0], 10)  # main empieza en la línea 10
        self.assertIn("calcular_promedio", {p["f"] for p in r["pasos"]})
        self.assertEqual({p["l"] for p in r["pasos"]} - set(range(1, 15)), set())  # nunca líneas de <iostream>
        self.assertEqual(lineas(r).count(5), 3)
        self.assertEqual(salida(r), "20\n")
        en_funcion = [dict(p["v"]) for p in r["pasos"] if p["f"] == "calcular_promedio" and p["l"] == 7]
        self.assertEqual(en_funcion[0]["suma"], "60")

    def test_la_i_del_for_y_a_donde_apunta_un_puntero(self):
        r = trazar("cpp", PROMEDIO_CPP, "")
        vueltas = [dict(p["v"]) for p in r["pasos"] if p["f"] == "calcular_promedio" and p["l"] == 4]
        self.assertNotIn("i", vueltas[0])  # al entrar al for, i aún no existe
        self.assertEqual([v["i"] for v in vueltas[1:]], ["0", "1", "2"])  # al volver al encabezado, sí
        self.assertRegex(vueltas[0]["arr"], r"^0x[0-9a-f]+ → 10$")


class Memoria(unittest.TestCase):
    def test_la_pila_une_el_puntero_con_el_arreglo_de_main(self):
        r = trazar("cpp", PROMEDIO_CPP, "")
        paso = next(p for p in r["pasos"] if p["f"] == "calcular_promedio" and p["l"] == 5)
        main, funcion = paso["m"]
        self.assertEqual([main["f"], funcion["f"]], ["main", "calcular_promedio"])  # main primero
        arreglo = next(c for c in main["v"] if c["n"] == "arr")
        self.assertEqual((arreglo["t"], arreglo["e"], arreglo["tam"]), ("arreglo", ["10", "20", "30"], 4))
        puntero = next(c for c in funcion["v"] if c["n"] == "arr")
        self.assertEqual((puntero["t"], puntero["a"]), ("puntero", arreglo["d"]))  # apunta al arreglo de main
        self.assertEqual(next(c for c in funcion["v"] if c["n"] == "n")["x"], "3")


class TrazadorPython(unittest.TestCase):
    def test_traza_de_python(self):
        r = trazar("python", "n = int(input())\ns = 0\nfor i in range(n):\n    s += i\nprint('total', s)\n", "4")
        self.assertIsNone(r["error"])
        self.assertEqual(lineas(r)[:3], [1, 2, 3])
        self.assertEqual(lineas(r).count(4), 4)
        self.assertEqual(salida(r), "total 6\n")
        self.assertEqual(dict(next(p for p in r["pasos"] if p["l"] == 5)["v"])["s"], "6")


if __name__ == "__main__":
    unittest.main()
