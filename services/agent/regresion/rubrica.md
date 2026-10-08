# Rúbrica de revisión experta de propuestas del agente (CLT4BP)

Cada experto califica cada propuesta del conjunto de regresión de 1 (inaceptable) a 4 (lista para usar).
Registra la calificación y un comentario breve en la hoja `calificaciones.csv` (caso, experto, criterio, nota, comentario).

| Criterio | 1 | 4 |
|---|---|---|
| **Alineación** con los objetivos de `<alcance>` | Las tareas no ejercitan el objetivo | Cada tarea ejercita el objetivo completo con su criterio |
| **Fidelidad CLT4BP**: efectos del catálogo bien elegidos y explicados | Efectos genéricos o mal aplicados | Cada efecto está justificado por el perfil del grupo y se nota en el material |
| **Desvanecimiento y variabilidad** | Todas las tareas con la misma guía, o casi idénticas | Ejemplo resuelto → por completar → convencional, con contextos distintos |
| **Autenticidad y ARCS** | Ejercicios de libro sin contexto | Problemas reconocibles para el estudiante, con las cuatro estrategias ARCS concretas |
| **Adecuación al grupo** (nivel, ansiedad, autoeficacia) | Ignora el perfil | Ajusta apoyo, vocabulario y confianza al perfil agregado |
| **Corrección técnica** (código, casos, redacción) | Errores que un estudiante copiaría | Código idiomático para el nivel, casos que cubren bordes, redacción clara |

Umbral para dar por buena una versión del prompt: mediana ≥ 3 en todos los criterios y ningún caso con 1 en corrección técnica.

Guarda con cada revisión la versión del prompt (`version_prompt`) y el modelo: son parte de los resultados de la tesis.

El acuerdo entre expertos (por ejemplo, kappa ponderada o correlación intraclase) lo calcularás en la Etapa 6 junto con los demás análisis; por ahora basta con recoger las calificaciones en `calificaciones.csv` dentro de la carpeta de resultados.
