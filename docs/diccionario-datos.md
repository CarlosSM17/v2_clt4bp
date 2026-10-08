# Diccionario de datos de la exportación

Generado con `php artisan datos:diccionario`; no lo edites a mano (edita `app/Domain/Evaluacion/DiccionarioDatos.php`).

Solo se exportan estudiantes con el consentimiento de investigación vigente, identificados por seudónimo. Los CSV van en UTF-8 con BOM, separados por comas; las fechas, en ISO 8601.

## estudiantes.csv

Una fila por estudiante que consintió la investigación.

| Columna | Descripción |
|---|---|
| `seudonimo` | Identificador estable del estudiante (E-XXXXXX). Nunca nombre ni correo. |
| `grupo` | Clave del grupo diferenciado vigente (G1, G2...); vacío si el curso no usa grupos. |
| `estado` | Estado de la inscripción: con_perfil, cursando, evaluacion_final o concluido. |
| `nivel_inicial` | Nivel del perfil del diagnóstico: basico, intermedio o avanzado. |
| `cp_recall` | Conocimiento previo: % en ítems de recuerdo del pre-test teórico (0–100). |
| `cp_comprension` | Conocimiento previo: % en ítems de comprensión del pre-test teórico (0–100). |
| `cp_teorico` | % en el pre-test teórico (0–100). |
| `cp_practico` | % en el pre-test práctico (0–100). |
| `cp_global` | Conocimiento previo ponderado con los pesos del curso (0–100). |
| `banderas` | Banderas del MSLQ inicial separadas por «\|»: baja_autoeficacia, alta_ansiedad, baja_autorregulacion. |

## pruebas.csv

Una fila por prueba presentada (pre o post, teórica o práctica).

| Columna | Descripción |
|---|---|
| `seudonimo` | Ver estudiantes. |
| `momento` | pre (diagnóstico) o post (evaluación final). |
| `tipo` | teorica o practica. |
| `forma` | Forma paralela: A o B. |
| `porcentaje` | Puntaje total, 0–100, ponderado por los puntos de cada ítem. |
| `recall` | % en ítems de recuerdo; vacío si la prueba no tiene. |
| `comprension` | % en ítems de comprensión; vacío si la prueba no tiene. |
| `practica` | % en problemas de programación; vacío si la prueba no tiene. |
| `minutos` | Minutos entre el inicio y el envío. |
| `enviado_at` | Fecha y hora del envío (ISO 8601). |

## items.csv

Una fila por ítem y estudiante: base para análisis de ítems y logro por objetivo.

| Columna | Descripción |
|---|---|
| `seudonimo` | Ver estudiantes. |
| `momento` | pre o post. |
| `prueba_tipo` | teorica o practica. |
| `item_id` | Identificador del ítem en el banco del curso. |
| `objetivo` | Código del objetivo que evalúa (OB-n); vacío si no tiene. |
| `nivel` | recall, comprension o practica. |
| `tipo_item` | opcion_multiple, respuesta_corta, prediccion_salida, parsons o programacion. |
| `puntos` | Puntos del ítem en esa prueba. |
| `fraccion` | Fracción obtenida, 0–1 (en programación, casos aprobados / casos). |

## instrumentos.csv

Formato largo: una fila por estudiante, aplicación y subescala. Las respuestas ítem por ítem están en instrumento_<clave>_<momento>.csv (una columna por ítem, valor original sin recodificar).

| Columna | Descripción |
|---|---|
| `seudonimo` | Ver estudiantes. |
| `instrumento` | mslq, imms, cis o cs. |
| `version` | Versión del instrumento cargada (p. ej., es-2026). |
| `momento` | pre, post o clase (la escala CS se aplica al cerrar cada clase de tareas). |
| `clase_uid` | Clase de tareas (solo momento clase). |
| `subescala` | Clave de la subescala, como en el JSON del instrumento. |
| `puntaje` | Media de los ítems de la subescala, con los ítems inversos ya recodificados. |
| `completado_at` | Fecha y hora en que terminó el cuestionario. |

## tareas.csv

Una fila por estudiante y tarea abierta.

| Columna | Descripción |
|---|---|
| `seudonimo` | Ver estudiantes. |
| `clase_orden` | Número de la clase de tareas (de simple a compleja). |
| `clase_uid` | Identificador de la clase. |
| `tarea_orden` | Posición de la tarea en su clase. |
| `tarea_uid` | Identificador de la tarea. |
| `nivel_apoyo` | ejemplo_resuelto, por_completar, convencional o solucion_libre (versión base). |
| `estado` | en_progreso o completada. |
| `intentos` | Envíos calificados. |
| `mejor_fraccion` | Mejor fracción de casos aprobados, 0–1. |
| `esfuerzo` | Esfuerzo mental percibido, escala de Paas 1–9. |
| `eficiencia` | E = (zP - zR)/√2 respecto a quienes hicieron la misma tarea; vacío si falta un dato. |
| `ayudas` | Veces que abrió una ayuda procedimental de la tarea. |
| `minutos_visibles` | Minutos con la tarea visible en pantalla (eventos tiempo_visible). |
| `iniciado_at` | Primera vez que abrió la tarea. |
| `completado_at` | Cuándo quedó completada; vacío si no. |

## envios.csv

Cada envío de código con su resultado.

| Columna | Descripción |
|---|---|
| `seudonimo` | Ver estudiantes. |
| `tarea_uid` | Tarea. |
| `numero` | 1.º, 2.º... envío de esa tarea. |
| `publicacion` | Número de la publicación que vio el estudiante. |
| `estado` | calificado, en_cola o error. |
| `fraccion` | Casos aprobados / casos, 0–1. |
| `creado_at` | Fecha y hora del envío. |
| `autoexplicacion` | Auto-explicación escrita antes de enviar (si la tarea la pedía). |
| `codigo` | Código enviado. |

## eventos.csv

Eventos de aprendizaje estilo xAPI; los verbos están en docs/diccionario-eventos.md.

| Columna | Descripción |
|---|---|
| `seudonimo` | Ver estudiantes. |
| `verbo` | Qué hizo (abrio_tarea, consulto_ayuda, envio, calificado...). |
| `objeto_tipo` | curso, clase, tarea, soporte, ayuda, medio o practica. |
| `objeto_uid` | Identificador del objeto. |
| `publicacion` | Número de la publicación vigente en ese momento. |
| `duracion_ms` | Duración en milisegundos (solo tiempo_visible y reproducciones). |
| `origen` | cliente (navegador) o servidor. |
| `ocurrido_at` | Cuándo ocurrió (reloj del navegador si origen = cliente). |
| `resultado` | Detalle en JSON (p. ej., casos aprobados). |

## agente.csv

Una fila por propuesta del agente IA en el curso (validación del agente, sección 12.2). No contiene datos de estudiantes.

| Columna | Descripción |
|---|---|
| `trabajo_id` | Identificador del trabajo del agente. |
| `plantilla` | Plantilla usada (clase_tareas, info_soporte...). |
| `paso` | Paso de CLT4BP (1–10). |
| `modelo` | Modelo de Claude que respondió. |
| `version_prompt` | Versión del prompt de sistema. |
| `costo_usd` | Costo de la generación en dólares. |
| `segundos` | Duración de la generación. |
| `intentos` | Intentos del orquestador (1 = válido a la primera). |
| `decision` | aceptado, parcial o descartado; vacío si no se decidió. |
| `propuestos` | Elementos de diseño propuestos. |
| `aceptados` | Elementos aceptados. |
| `proporcion_editada` | Promedio, en los aceptados, de cuánto los cambió el instructor después (0 = nada, 1 = todo). |
| `creado_at` | Fecha de la solicitud. |
