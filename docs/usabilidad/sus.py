"""Puntaje SUS (System Usability Scale) por participante y por rol.

Uso: uv run --no-project python docs/usabilidad/sus.py respuestas.csv

El CSV lleva las columnas participante, rol, p1 … p10, con respuestas de 1 (totalmente en desacuerdo)
a 5 (totalmente de acuerdo). Ítems impares: respuesta − 1; pares: 5 − respuesta; la suma × 2.5 da 0–100.
"""

import csv
import statistics
import sys
from collections import defaultdict


def sus(respuestas: list[int]) -> float:
    if len(respuestas) != 10 or not all(1 <= r <= 5 for r in respuestas):
        raise ValueError(f"Se esperan 10 respuestas entre 1 y 5: {respuestas}")
    return 2.5 * sum(r - 1 if i % 2 == 0 else 5 - r for i, r in enumerate(respuestas))


def main(ruta: str) -> None:
    por_rol: dict[str, list[float]] = defaultdict(list)
    with open(ruta, newline="", encoding="utf-8-sig") as f:
        for fila in csv.DictReader(f):
            puntaje = sus([int(fila[f"p{i}"]) for i in range(1, 11)])
            por_rol[fila["rol"]].append(puntaje)
            print(f"{fila['participante']:>12} {fila['rol']:<12} {puntaje:6.1f}")

    print()
    for rol, puntajes in por_rol.items():
        de = statistics.stdev(puntajes) if len(puntajes) > 1 else 0.0
        estado = "cumple (≥ 68)" if statistics.mean(puntajes) >= 68 else "NO cumple (< 68)"
        print(f"{rol:<12} n = {len(puntajes):2d}  media = {statistics.mean(puntajes):5.1f}  DE = {de:4.1f}  {estado}")


if __name__ == "__main__":
    main(sys.argv[1])
