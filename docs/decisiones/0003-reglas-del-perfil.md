# 0003 — Reglas del perfil, la homogeneidad y la agrupación

- Fecha: 2026-09-26
- Estado: propuesta (pendiente de revisión con el director de tesis)

## Contexto
La primera decisión del modelo CLT4BP es «¿conocimientos homogéneos?». El sistema calcula el
perfil de cada estudiante a partir del MSLQ y de las pruebas iniciales, y **recomienda**; el
instructor decide y, si contradice la recomendación, justifica (queda como dato de la investigación).
Todo el cálculo vive en `apps/web/app/Domain` (PHP puro, con pruebas unitarias).

## Decisión

| Regla | Valor por defecto | Clave en `courses.configuracion.perfil` |
|---|---|---|
| Conocimiento previo global | CP = 0.5 · teórico + 0.5 · práctico | `peso_teorico`, `peso_practico` |
| Nivel | básico < 40 ≤ intermedio ≤ 70 < avanzado | `umbral_intermedio`, `umbral_avanzado` |
| Grupo homogéneo | CV ≤ 0.25 **y** ≥ 70 % de estudiantes en el nivel modal | `cv_maximo`, `proporcion_modal` |
| Recomendación confiable | n ≥ 10 perfiles | `minimo_confiable` |
| Banderas de apoyo (MSLQ, 1–7) | autoeficacia < 4, ansiedad > 5, metacognición < 4 | fijas en el código |

- **Desviación estándar muestral** (n − 1) para el CV; ante un empate de niveles gana el de menor
  conocimiento previo (elección conservadora).
- **Agrupación**: por nivel (predeterminada, transparente) o k-means con k entre 2 y 4 elegido por
  silueta y semilla fija (reproducible). k-means siempre encuentra grupos aunque no existan: la
  decisión la toma el análisis de homogeneidad y el agrupamiento se ofrece solo después.
- Cada perfil se guarda como **versión nueva**; cada análisis del grupo como fila nueva, y guardar
  grupos **cierra** las membresías anteriores en vez de borrarlas (historial para la tesis).

### El MSLQ usado (difiere de la guía)
La guía asume el MSLQ original de 81 ítems / 15 subescalas / 8 inversos. El instrumento de la tesis
(Anexo A.1) tiene **73 ítems (31 de motivación y 42 de estrategias), 13 subescalas y ningún ítem
inverso**, por lo que:

- `instruments/mslq.json` (generado por `instruments/generar_mslq.py`) usa las subescalas: intrinseca,
  extrinseca, valor_tarea, autoeficacia, ansiedad, repaso, elaboracion, organizacion, metacognicion
  (control de la comprensión), gestion_tiempo, regulacion_esfuerzo, busqueda_ayuda y entorno_aprendizaje.
- Faltan `control` (creencias de aprendizaje), `pensamiento_critico` y `aprendizaje_pares`, así que los
  índices se redefinieron: **motivación** = autoeficacia, valor de la tarea e intrínseca; **estrategias
  cognitivas** = repaso, elaboración y organización; **autorregulación** = metacognición, gestión del
  tiempo y regulación del esfuerzo.
- La bandera de baja autorregulación usa `metacognicion` (control de la comprensión).
- El anexo dice que las estrategias son 50 ítems, pero lista 42; se usaron los 42 listados.

## Consecuencias
Las reglas son configurables por curso: si el comité o el piloto obligan a cambiarlas, actualiza este
ADR además del código. Los umbrales y pesos por defecto son los de la propuesta de tesis; su fuente
en la literatura debe citarse al revisar este documento.
