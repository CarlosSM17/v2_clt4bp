"""Pruebas del ejecutor con la forma de Piston (ADR 0008), con gcc y Python reales. Se corren dentro del contenedor:
    docker exec clt4bp-trazador python3 -m unittest discover -s /app/pruebas -v
"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parents[1]))
from ejecutor import ejecutar, runtimes  # noqa: E402

SUMA_C = '#include <stdio.h>\nint main(void) { int a, b; scanf("%d %d", &a, &b); printf("%d\\n", a + b); return 0; }\n'


def correr(lenguaje: str, codigo: str, entrada: str = "", **extra) -> dict:
    estado, r = ejecutar({"language": lenguaje, "version": "*", "files": [{"content": codigo}], "stdin": entrada, **extra})
    assert estado == 200, r
    return r


class EjecutorTest(unittest.TestCase):
    def test_c_cpp_y_python_como_los_devuelve_piston(self):
        r = correr("c", SUMA_C, "2 3")
        self.assertEqual((r["compile"]["code"], r["run"]["stdout"], r["run"]["code"], r["run"]["signal"]), (0, "5\n", 0, None))
        cpp = correr("c++", '#include <iostream>\nint main() { std::string s; std::cin >> s; std::cout << "hola " << s; }', "Ana")
        self.assertEqual(cpp["run"]["stdout"], "hola Ana")
        py = correr("python", "print(int(input()) * 2)", "21")
        self.assertEqual((py["run"]["stdout"], "compile" in py), ("42\n", False))

    def test_un_error_de_compilacion_llega_en_compile_y_sin_run(self):
        r = correr("c", "int main(void) { return x; }")
        self.assertNotEqual(r["compile"]["code"], 0)
        self.assertIn("'x' undeclared", r["compile"]["stderr"])
        self.assertNotIn("run", r)

    def test_un_ciclo_infinito_muere_con_sigkill_dentro_del_plazo(self):
        r = correr("c", "int main(void) { for (;;); }", run_timeout=1000)
        self.assertEqual((r["run"]["code"], r["run"]["signal"]), (None, "SIGKILL"))  # Laravel: «excedió el límite»
        dormido = correr("python", "import time\ntime.sleep(30)", run_timeout=1000)  # el reloj de pared también cuenta
        self.assertEqual(dormido["run"]["signal"], "SIGKILL")

    def test_la_salida_sin_fin_se_corta_sin_llenar_la_memoria(self):
        r = correr("python", "while True:\n    print('x' * 1000)")
        self.assertLessEqual(len(r["run"]["stdout"]), 65536)
        # Al pasar de 1 MB: SIGXFSZ en C; Python ignora esa señal y termina con error de escritura
        self.assertTrue(r["run"]["signal"] is not None or r["run"]["code"] != 0)
        c = correr("c", '#include <stdio.h>\nint main(void) { for (;;) puts("xxxxxxxxxx"); }')
        self.assertEqual(c["run"]["signal"], "SIGXFSZ")

    def test_la_memoria_y_los_procesos_tienen_techo(self):
        r = correr("c", "#include <stdlib.h>\n#include <string.h>\nint main(void) { char *p = malloc(400u << 20); if (!p) return 3; memset(p, 1, 400u << 20); return 0; }")
        self.assertEqual(r["run"]["code"], 3)  # 400 MB > 256 MB: malloc falla
        bomba = correr("python", "import os\nn = 0\ntry:\n    while True:\n        if os.fork() == 0:\n            import time; time.sleep(5); os._exit(0)\n        n += 1\nexcept OSError:\n    print('tope', n)")
        self.assertIn("tope", bomba["run"]["stdout"])

    def test_peticiones_invalidas_y_runtimes(self):
        self.assertEqual(ejecutar({"language": "rust", "files": [{"content": ""}]})[0], 400)
        self.assertEqual(ejecutar({"language": "c", "files": []})[0], 400)
        self.assertEqual({r["language"] for r in runtimes()}, {"c", "c++", "python"})


if __name__ == "__main__":
    unittest.main()
