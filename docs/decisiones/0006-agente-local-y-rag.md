# 0006 — Agente educativo local (Ollama) y material del curso (RAG)

- Fecha: 2026-09-28
- Estado: implementado; falta la comparación de calidad con la regresión (ver «Pendiente»)

## Contexto
Hasta la Etapa 7 el agente generaba solo con Claude por API: cada propuesta salía a internet, costaba por
token y obligaba a declarar a Anthropic como encargado en el aviso de privacidad. Además, el agente diseñaba
sin conocer el material propio del curso (apuntes, bibliografía), así que sus propuestas no seguían la
terminología ni el alcance que el instructor ya usa.

## Decisión
1. **Proveedor configurable** en `services/agent`: `PROVEEDOR=local` (por omisión) usa un modelo abierto
   servido por Ollama (`app/proveedor/ollama.py`, `qwen3:8b` / `qwen3:4b` / `qwen3:14b`); `PROVEEDOR=claude`
   conserva el proveedor anterior. Ambos cumplen `ProveedorLLM`, así que el orquestador, las plantillas, las
   validaciones con Piston y el bucle de corrección no cambian. Ollama restringe la salida al JSON Schema de
   la plantilla (`format`), el equivalente de las salidas estructuradas de Claude.
2. **RAG local, con Laravel como dueño del índice.** El instructor sube PDF/MD/TXT en la pestaña
   **Materiales** de la consola. Laravel guarda el archivo y encola `ProcesarDocumento`; el agente extrae el
   texto, lo fragmenta (~800 caracteres, traslape de 150, sin cruzar páginas) y calcula los embeddings con
   `bge-m3` en Ollama (`/v1/documentos/procesar`); Laravel guarda fragmentos y vectores en pgvector
   (`document_fragments`, índice HNSW coseno). Al generar, `BuscadorMaterial` arma una consulta con lo
   seleccionado (objetivos, clase o tarea, indicaciones), pide su embedding (`/v1/embeddings`) y envía los
   6 fragmentos más cercanos **del mismo curso** en `SolicitudGeneracion.material`. El prompt (sistema-v2)
   los recibe en `<material_curso>` como datos, no como instrucciones. La corrida guarda los ids usados
   (`agent_runs.fragmentos`) y la consola los muestra como «Material consultado».

Se descartó que el agente tuviera su propio almacén de vectores: rompería la regla «el agente nunca accede a
la base de datos», duplicaría respaldos y obligaría a replicar la autorización por curso. Con este diseño el
agente sigue sin estado y la autorización, la auditoría y los respaldos son los de Laravel.

Los embeddings son siempre locales, también con `PROVEEDOR=claude`: el material nunca sale para indexarse.

## Consecuencias
- Con `PROVEEDOR=local` nada sale del servidor y el costo por propuesta es 0; Anthropic deja de ser
  encargado en el aviso de privacidad (ver `docs/piloto.md`).
- **Calidad**: un modelo de ~8B produce clases de tareas más pobres y falla más el esquema y los casos de
  Piston que Claude; el bucle de corrección lo mitiga en parte. Hay que medirlo antes del piloto.
- **Tiempo**: una generación local tarda minutos. `AGENTE_TIMEOUT` (900 s recomendado en local) fija el
  timeout HTTP y el del job; `DB_QUEUE_RETRY_AFTER` debe superarlo (1000), o la cola relanzaría una
  generación en curso.
- **Hardware**: medido el 2026-09-29 en la laptop de desarrollo (RTX 4060 de 8 GB, compartida con el
  escritorio) con el prompt real del caso de regresión 01 (4 214 tokens, sin material):

  | Modelo · contexto · ajustes de Ollama | Dónde corre | Lectura del prompt | Generación |
  | --- | --- | --- | --- |
  | `qwen3:8b` · 16k · flash attention + caché `q8_0` | «100 % GPU» | 76 tokens/s (55 s) | 6 tokens/s |
  | `qwen3:8b` · 12k · flash attention + caché `q8_0` | 100 % GPU | 72 tokens/s (58 s) | 29 tokens/s |
  | `qwen3:8b` · 16k · flash attention + caché `f16` | 20 % CPU | 101 tokens/s | 16 tokens/s |
  | `qwen3:4b` · 16k · flash attention + caché `f16` | 100 % GPU | 2 976 tokens/s (1.4 s) | 63 tokens/s |

  Con un prompt corto, `qwen3:8b` sí da 30 tokens/s: la memoria se agota solo con el contexto real. Con
  `qwen3:8b` y el esquema completo en `format`, la primera generación del caso 01 superó los 900 s.

- **Valores por omisión elegidos (para una GPU de 8 GB):** `qwen3:4b` para las plantillas normales y ligeras (un
  solo modelo cargado: nunca se recarga entre plantillas), `qwen3:8b` solo para «calidad alta», sin razonamiento
  (`OLLAMA_PENSAR=no`), 24k de contexto (con 16k, el tercer intento de una clase del curso real se cortaba; 32k ya
  no cabe: 22 % en CPU, 10 tokens/s), `keep_alive` de 30 min, y en Ollama flash attention y
  `OLLAMA_MAX_LOADED_MODELS=1`: `qwen3:4b` con 24k (6.3 GB) y `bge-m3` (1.2 GB) no caben juntos junto al
  escritorio; alternarlos cuesta ~6 s, y `bge-m3` en CPU es 40× más lento (2 contra 77 fragmentos/s). Con 12 GB o más
  de VRAM libre, `MODELO_LOCAL_NORMAL=qwen3:8b`.
- **Ajustes para que un modelo pequeño se corrija** (útiles también con Claude): la salida se limita al espacio
  que deja el prompt; cada corrección lleva solo la última respuesta y sus problemas (la conversación completa
  llenaba el contexto al tercer intento y la respuesta se cortaba); una respuesta cortada no vuelve al contexto
  y no borra las validaciones de los elementos que se muestran; el fallo de un caso se explica con la entrada,
  la salida esperada y la obtenida; una «solucion» en prosa se señala con un mensaje explícito; y en un problema
  convencional sin casos ocultos se oculta el último (con advertencia). Prompt `sistema-v3`.

  Evolución con el caso 01 (clase de tareas) y `qwen3:4b`: 4 bloqueantes en 204 s con `sistema-v2` → 2 con
  `sistema-v3` (con razonamiento: 409 s y cortado) → **0 bloqueantes al primer intento, 86 s**, con todos los
  ajustes. Caso 03 (arreglos, intermedio): 2 bloqueantes en 323 s, ambos por un error de lógica en una tarea
  (un promedio mal redondeado) que el validador detecta y la consola marca con ⛔. En el curso real, una clase sobre
  manejo de archivos no se resolvió ni con `sistema-v5` (patrón explícito y retroalimentación que dice qué hacer):
  para temas así, el modelo de 4B no basta; ver `services/agent/CAMBIOS_PROMPT.md`. Casos 02, 07 y 13 (ayudas,
  objetivos, soporte): sin problemas al primer intento, en 4–20 s. Resultados en `regresion/resultados/`. Un servidor sin GPU genera a ≈5–10 tokens/s: hay que dimensionar el servidor del piloto o
  usar `PROVEEDOR=claude` ahí. Cambiar de proveedor a mitad del piloto cambia el «tratamiento».
- La dimensión del vector (1024, `bge-m3`) queda fija en la migración `2026_09_28_100000`: cambiar de
  modelo de embeddings exige una migración nueva y reprocesar los documentos.

## Material multimedia (2026-10-01)

El agente genera diagramas Mermaid (que la consola y el aula ya dibujaban) y existe un bloque nuevo ```traza: un
reproductor paso a paso (línea actual, variables, salida) en `apps/desktop/src/renderer/src/lib/traza.ts` y su copia
en `apps/web/resources/js/lib/traza.ts`, que arma el DOM con `textContent` (el contenido viene del modelo). El agente
valida diagramas y trazas, y ejecuta la traza en Piston para que la salida que muestra sea la real. Con `qwen3:4b`
salen diagramas válidos en la información de soporte, pero no trazas: ver `services/agent/CAMBIOS_PROMPT.md`.

### Trazas calculadas (2026-10-01)

Las trazas que escribía el modelo no seguían la ejecución real (orden de texto, salidas inventadas). Los pasos ahora
los calcula `services/trazador`: compila con `-g`, ejecuta con gdb línea por línea (`step`, sin entrar a las
bibliotecas) y anota la línea por ejecutar, las variables ya declaradas (un puntero muestra también a qué apunta) y
la salida, sin búfer para que caiga en el paso correcto. Python usa `sys.settrace`. Límites: 300 pasos, 8 s de CPU, un
vigilante que corta un paso de más de 3 s (un ciclo de una sola línea hace que `step` no regrese), 768 MB, sin
privilegios. No usa `RLIMIT_AS`: con 1 GB gdb se colgaba al cargar los símbolos de C++. Un `SIGSEGV` queda como aviso
en la traza, en la línea que falla. El modelo solo escribe encabezado y código (prompt `sistema-v7`); la consola tiene
«Calcular pasos» para las trazas del instructor (`POST /courses/{course}/trazas`).

Cada paso trae además la pila (`"m"`: marcos desde `main`, con arreglos, punteros y valores, cada uno con su
dirección). El reproductor dibuja un diagrama de memoria tipo Python Tutor: un puntero se une por dirección con el
elemento al que apunta («main · arr[1]»), aunque esté en otro marco, y se resaltan las celdas que cambiaron. Una
variable declarada sin valor inicial (`int n, x;`) se muestra como «?» hasta que una línea ejecutada la usa: antes
su valor es basura de la memoria (`6.37e-310`), que confunde al estudiante.

## Etapas para un modelo pequeño (2026-10-02, prompt `sistema-v8`)

Pedirle a `qwen3:4b` toda una clase en una sola respuesta daba material incompleto: casos de prueba con salidas
inventadas, soporte sin diagramas y ninguna ayuda procedimental. Se reparte el trabajo (`app/etapas.py`):

- **Salidas calculadas.** El modelo propone solo las entradas de los casos; el agente ejecuta la solución en Piston y
  toma su salida como `salida_esperada`. Si dos o más entradas distintas dan la misma salida, la solución ignora su
  entrada (datos fijos en el código): bloquea. Una salida `nan` o `inf` (división entre cero con `n = 0`) bloquea, y
  también un `printf` cuyo formato no corresponde al tipo de la variable (`%f` con un `int` imprime `0.0`; Piston no
  muestra los avisos de gcc).
  Casos idénticos entre dos tareas se avisan. Las cercas ```` ```markdown ```` que el modelo pone alrededor de una
  tabla se quitan, para que se dibuje como tabla, y las etiquetas de Mermaid con símbolos se ponen entre comillas
  (`B{¿n > 0?}` → `B{"¿n > 0?"}`): el modelo no lo corregía ni con la retroalimentación.
- **Nivel de apoyo desde la solución.** Un ejemplo resuelto cuyo código no da las mismas salidas que la solución
  muestra la solución. Un problema por completar sin huecos los recibe de la solución: sus asignaciones más internas
  y la condición de un `if` (no la lectura, la escritura ni las declaraciones), con aviso para que el instructor los
  revise. Antes, ambos casos bloqueaban la propuesta tras tres intentos.
- **Clase completa.** Tras aceptar la clase y sus tareas, el agente pide por separado la información de soporte y una
  ayuda procedimental por cada tarea que no es ejemplo resuelto (`CLASE_COMPLETA=true`, por omisión), cada una con su plantilla y
  viendo la clase nueva en el diseño. Lo que no alcance en `PRESUPUESTO_CLASE_S` (600 s) se omite con advertencia
  y se puede pedir aparte.
- **Ejemplos con traza.** Cada ejemplo resuelto lleva al final «Ejecución paso a paso» con la traza calculada de su
  solución.

**Contexto acotado (2026-10-03, `sistema-v9`).** El modelo local comparte 24k tokens entre el prompt y la respuesta.
`<diseno_actual>` lleva las trazas sin sus pasos calculados y recorta los enunciados si pasa de 20 000 caracteres:
con los pasos (cada uno con su diagrama de memoria), una clase de tareas de «Programación I» mandaba 20k tokens y la
respuesta se cortaba en los tres intentos. `<alcance>` lleva la descripción de los objetivos, no solo su código.

**El tema lo fijan objetivos e indicaciones (2026-10-04, `sistema-v10`).** Con los enunciados de todas las tareas en
`<diseno_actual>`, `qwen3:4b` copiaba la clase existente aunque el objetivo y la indicación pedían otro tema. Una clase
nueva ahora solo ve los títulos de lo existente, el mensaje termina con su tema (objetivos aún no cubiertos e
indicaciones), las indicaciones del instructor se siguen (antes el prompt las trataba como «nunca instrucciones») y
una clase o tarea con el título de una existente bloquea.

**Forma del material (2026-10-04, `sistema-v11`).** A pedido del instructor: el soporte es teoría básica breve y
gráfica (viñetas, diagrama, traza y tabla de errores); la información procedimental, una ficha por tarea con la tabla
de sintaxis y un ejemplo básico propio (bloquea si copia la solución de una tarea); y cada tarea es un ejercicio
distinto (bloquea si dos comparten título, enunciado o casi el mismo programa). En la consola, una propuesta se guarda
en el diseño o se elimina (`DELETE /agent/jobs/{job}`); la lista de propuestas dice cuáles ya se guardaron.

La consola cambió con ella: el marco se pliega en el Estudio, el árbol se oculta, el panel «Diseño» baja bajo el
formulario cuando no cabe y el árbol agrupa en «Sin clase» las tareas y ayudas cuyo padre no existe. El comparador
no deja aceptar una ayuda sin su tarea ni una tarea sin su clase.

## Pendiente
Correr la regresión con ambos proveedores y comparar con `regresion/rubrica.md`:
`uv run python -m regresion.correr --proveedor local` y `--proveedor claude`. Anotar el resultado en
`services/agent/CAMBIOS_PROMPT.md`. Los casos actuales no traen material, así que miden el cambio de modelo;
para medir el RAG hacen falta casos con `material`.
