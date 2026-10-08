import csv

from app.config import Ajustes
from app.orquestador import Orquestador
from app.proveedor.falso import ProveedorFalso
from regresion.correr import cargar_casos, ejecutar

# Una ficha como la pide la plantilla: sintaxis (tabla) y un ejemplo básico propio
FICHA = """## Sintaxis

| Instrucción | Para qué sirve | Forma general |
|---|---|---|
| `if` | Decidir | `if (condición) { … }` |

## Ejemplo básico

```c
#include <stdio.h>
int main(void) {
    int edad;
    scanf("%d", &edad);
    if (edad >= 18) printf("adulto\\n");
    return 0;
}
```"""


async def test_el_conjunto_de_regresion_carga_y_produce_su_resumen(tmp_path):
    casos = cargar_casos(["02"])
    assert casos[0]["solicitud"]["diseno"]["curso"]["titulo"] == "Programación en C"  # se resolvió diseno_desde

    ayuda = {
        "uid": "x", "tarea_uid": "tc1-t3", "tipo": "ficha_sintaxis", "titulo": "Contar con if", "cuerpo_md": FICHA,
        "diseno": {"paso_clt4bp": 7, "componente": "procedimental",
                   "efectos": [{"id": "elementos_aislados", "como": "Solo el patrón de conteo."}],
                   "interactividad": "baja", "tiempo_estimado_min": 3,
                   "justificacion": "Justo a tiempo.", "advertencias": []},
    }
    orq = Orquestador(ProveedorFalso([{"advertencias": [], "procedimental": [ayuda]}]), None, Ajustes(max_intentos=1))
    filas = await ejecutar(orq, casos, tmp_path)

    assert filas[0]["bloqueantes"] == 0 and filas[0]["elementos"] == 1
    assert (tmp_path / "02-ayudas-tarea-por-completar.json").exists()
    assert next(csv.DictReader((tmp_path / "resumen.csv").open(encoding="utf-8")))["caso"] == "02-ayudas-tarea-por-completar"
