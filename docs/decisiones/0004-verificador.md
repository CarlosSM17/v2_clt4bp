# 0004 — Umbrales del verificador CLT4BP

- Fecha: 2026-09-26
- Estado: propuesta (punto de partida; ajustar con lo observado en el piloto)

## Contexto
El verificador (`services/agent/app/verificador/reglas.py`) revisa el diseño 4C/ID antes de que el
instructor apruebe un elemento. La mayoría de las 16 reglas son estructurales (¿existe la tarea?, ¿tiene
ARCS?, ¿compila?) y no admiten grados. Tres reglas sí necesitan un umbral numérico porque comparan
textos o duraciones:

| Regla | Umbral | Qué compara |
|---|---|---|
| `variabilidad` | similitud de Jaccard ≥ 0.8 | dos enunciados de tareas de la misma clase |
| `texto_redundante` | similitud de Jaccard ≥ 0.7 | el cuerpo de un soporte/procedimental contra la transcripción del medio que cita |
| `video_sin_segmentar` | duración > 360 s (6 min) | videos y protocolos verbales sin marcas de segmento |

## Decisión
Se adoptan los valores anteriores como punto de partida, no como constantes definitivas:

- **0.8 para `variabilidad`**: la similitud de Jaccard sobre palabras (≥ 3 letras, sin bloques de
  código) es una medida gruesa; 0.8 es exigente a propósito, para no generar falsos positivos cuando
  dos tareas comparten vocabulario técnico legítimo (mismo dominio, distinto escenario). Se prefiere
  un umbral alto que deje pasar variabilidad real a uno bajo que obligue a reescribir tareas ya
  suficientemente distintas.
- **0.7 para `texto_redundante`**: la redundancia entre texto e imagen/audio es uno de los 17 efectos
  del catálogo (`packages/contracts/catalogo/efectos.json`); repetir la misma información en dos
  canales añade carga extrínseca. 0.7 es más permisivo que el de `variabilidad` porque aquí el objetivo
  es señalar una advertencia (no bloquea la aprobación), y un texto que resume o contextualiza la
  narración sin repetirla palabra por palabra no debe activarla.
- **360 segundos para `video_sin_segmentar`**: es la misma referencia (6 minutos) que usa la literatura
  de video educativo para el punto en que la atención decae sin cortes; por debajo de eso, segmentar es
  poco útil incluso si el video no tiene información transitoria real.

Los tres son constantes en `app/verificador/contexto.py` (`similitud`) y `app/verificador/reglas.py`
(`video_sin_segmentar`), no configuración por curso: cambiarlos es una decisión de diseño del
verificador, no del instructor.

## Consecuencias
Si el piloto muestra muchos falsos positivos o negativos en `variabilidad`, `texto_redundante` o
`video_sin_segmentar`, ajusta la constante correspondiente y actualiza este ADR con el nuevo valor y la
razón (idealmente con ejemplos reales del corpus del piloto). Un cambio de umbral no requiere migración
de datos: el semáforo se recalcula en cada verificación.
