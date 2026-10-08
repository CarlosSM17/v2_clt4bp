# Historial de cambios del prompt de sistema

Cada vez que se modifique `app/conocimiento/prompt_sistema.md`, una plantilla o el modelo usado, sube `VERSION`
en `app/conocimiento/__init__.py`, corre `uv run python -m regresion.correr` y anota aquí qué cambió, por qué
y qué resultó (comparando `resumen.csv` con la versión anterior y, si el cambio es de fondo, con calificación
experta según `regresion/rubrica.md`).

## sistema-v12 (tema completo con la forma del mapa de ruta, 2026-10-06)

El instructor compartió el material del Tema 02 (PDF de 29 páginas) como modelo: ADR 0007. Reemplaza la brevedad de v11.

- Plantillas nuevas: `ficha_tema` (ficha de diseño en `clase.ficha_md`), `ejemplo_resuelto_tema` (soporte `sap`:
  algoritmo, PSeInt, código, salida, verificación) y `mapa_glosario` (soporte `mapa_conceptual`: mapa, glosario
  bilingüe, fuentes). `info_soporte` pasa a «Conceptos del tema» (analogía, pregunta, ¿por qué y para qué?, conceptos uno
  por uno con código anotado, salida, diagrama y ¡cuidado!, actividad en el aula). `info_procedimental` es del tema
  (tarjeta «Quiero… | Escribo», guía de rutina, errores frecuentes con mensaje del compilador, ejemplo isomórfico).
  `guion_protocolo` produce un elemento `protocolo_verbal` (segmentos CÓMO / POR QUÉ). `items_evaluacion` es la
  evaluación del tema (recall y comprehension).
- La secuencia T1–T8 del mapa de ruta va en dos solicitudes: `clase_tareas` pide la clase con T1–T4 (ejemplo + gemelo,
  por completar con y sin pistas, convencional) y, dentro de la misma propuesta, una subsolicitud `tareas_complementarias`
  pide T5–T8 (solución libre, autoexplicación, imaginación, reto colaborativo; `TAREAS_COMPLEMENTARIAS`, con el
  presupuesto de `PRESUPUESTO_CLASE_S`). Las ocho tareas en una sola respuesta se cortaban por longitud con `qwen3:4b`.
  Cada tarea lleva un título descriptivo («Calcular el IMC», no «Tarea 1»), «Contexto» y «¿Para qué?». Las rutas se
  asignan al final sobre las ocho.
- Prompt de sistema: reglas 3 y 8 (tareas con papeles distintos; el código inicial del ejemplo resuelto es el ejemplo y la
  solución es la del gemelo) y la sección multimedia con código anotado `//→`, ```salida, ```compilador, ```pseint y
  recuadros por emoji.
- Etapas sin el modelo: salidas y mensajes del compilador calculados (`app/bloques_md.py`), rutas por el papel de cada
  tarea, la predicción de salida de los ítems calculada, ejemplo + gemelo sin sustituir el código inicial, trazas solo en
  el primer ejemplo (no en autoexplicación ni imaginación).
- Correcciones tras la primera corrida real (1034 s, todas las piezas con bloqueantes):
  - `clase_sin_soporte` ya no se evalúa al generar: el soporte llega en la cadena, después de la clase.
  - Un programa completo sin bloque ```salida lo recibe (`asegurar_salidas`), y la «Salida esperada» escrita en prosa
    (casi siempre inventada) se quita; la entrada que diga el texto pasa a la cabecera `entrada:`.
  - Un fragmento sin `main` (las líneas de un concepto) se ejecuta dentro de un `main` con las bibliotecas comunes; el
    material muestra solo el fragmento.
  - Cada bloque ```salida se ejecuta dos veces: si cambia, su `entrada:` no trae todos los datos («x = 6.95e-310»).
  - Una tarea sin código dado recibe el código inicial que le corresponde por su nivel (`codigo_dado`), y una solución
    que no lee datos necesita un solo caso de prueba.
  - `items_evaluacion` pide solo la forma A (6 de recuerdo, 1 de predicción, 1 de corrección, 1 de programación): con
    las dos formas el modelo devolvía 2 ítems. La forma B se arma en Pruebas.
  - `info_soporte` exige al menos un diagrama Mermaid y programas completos con `cout` para que haya salida que mostrar.
- Segunda corrida (1032 s): la clase llega con sus 8 tareas y rutas, y la ficha, los tres soportes y la información
  procedimental llegan sin bloqueantes. Correcciones por lo que quedó:
  - T2, T3 y T4 eran el mismo «promedio de temperaturas»: el objetivo se parte en subtemas (por «;», por oraciones o
    por los elementos de una lista) y el mensaje de `clase_tareas` asigna uno a cada tarea (`reparto_de_subtemas`).
  - Los ítems traían el código dentro de ```cpp … ``` (no compilaba) y el mismo ítem de predicción seis veces: el
    cerco se quita (`sin_cerco`, también en las tareas) y los ítems repetidos se descartan con aviso
    (`limpiar_items`). La regla de formas paralelas solo se revisa si hay forma B.
  - El protocolo verbal llegaba sin programa en los tres intentos: la plantilla pide cerrar con «**Programa del
    experto:**», el programa y su salida.
  - El verificador revisa a cada grupo con las tareas de su ruta, como las ve en el aula, y la autoexplicación y la
    imaginación no cuentan como «más apoyo» en el desvanecimiento (eran 4 avisos falsos sobre T6).
  - Ollama abortó T5–T8 con «token repeat limit reached» (el modelo se quedó repitiendo) y el error tiraba también la
    clase ya lista. Ese error ahora es una respuesta cortada que se reintenta, y si una subsolicitud falla la propuesta
    principal llega igual, con aviso (`Orquestador.complemento`).
- Tercera corrida: con el reparto, T1–T4 ya son ejercicios distintos (tipo de dato, declarar y asignar, leer y
  mostrar, uno que integra). Correcciones por lo que quedó:
  - Un «por completar» de un tema sin cálculos llegaba sin huecos y `marcar_huecos` no encontraba asignaciones ni
    condiciones: ahora los huecos son la lectura (`cin`, `getline`, `scanf`, `input`) y la declaración.
  - La imaginación traía como código dado solo las líneas sueltas (no compilaba): va el programa completo.
  - El mensaje de T5–T8 cierra con los títulos de T1–T4 (`tareas_previas`): el reto colaborativo copiaba a T4.
  - Los conceptos llegaron sin diagrama en tres intentos: desde el segundo intento, el sistema agrega un «Resumen
    visual» con los títulos de los conceptos (`diagrama_por_omision`, aviso).
  - Un fragmento que no imprime nada (`int edad = 20;`) dejaba un bloque de salida vacío: el bloque se quita.
- Cuarta corrida (1008 s): ficha, los tres soportes (los conceptos, con el resumen visual automático) y la información
  procedimental, sin bloqueantes. Correcciones:
  - Con solo los subtemas en el reparto, el modelo los copió como títulos y las cuatro primeras tareas salieron como
    ejemplo resuelto: cada línea del reparto repite el papel de la tarea y pide un título con el problema.
  - El ejemplo resuelto y las soluciones llegaban como líneas sueltas sin `main`, o con `main` y sin `#include`: se
    completan como programa (`programas_completos`, con el mismo envoltorio que los fragmentos del soporte).
  - El protocolo verbal seguía sin programa: si al final le falta, se pide aparte con un esquema de dos campos
    (programa y entrada) y se agrega con su salida calculada (`Orquestador.programa_del_protocolo`, aviso).
  - En los ítems, la predicción de salida ejecuta el programa que muestra el enunciado; las soluciones se completan
    como programa y, como en las tareas, las salidas esperadas de un ítem de programación salen de su solución.
  - Con esto, la clase salió al primer intento con la secuencia completa y títulos que nombran su problema; quedó
    bloqueado solo T8, que extendía el escenario de T4. El desvanecimiento tampoco cuenta el reto colaborativo
    (convencional, después de la solución libre); la autoexplicación excluida es la posterior al primer ejemplo, que
    también puede pedir autoexplicación.
- Revisión visual en el aula (curso de demostración, Ruta A y Ruta B): la forma del mapa de ruta se ve completa y cada
  ruta ve sus tareas. Lo que mostró, corregido:
  - Datos de entrada que no corresponden a lo que lee el programa: «20.5» iba a un `char` y «A» a un `bool`, y la salida
    calculada era basura estable («Nombre: .5, Genero: 2»). En C++ con lectura en línea recta, cada dato se compara con
    el tipo de la variable que lo recibe (`desajuste_entrada`): bloquea y, desde el segundo intento, el caso se quita.
  - Entradas incompletas («Promedio: 0» con «entrada: 2» para dos notas; «Promedio: -nan» sin entrada): cada salida se
    calcula otra vez con datos de más al final; si cambia, a la entrada le faltaban datos (`lee_de_mas`, en los bloques
    de salida y en los casos de las tareas; no en programas que leen hasta el fin de la entrada).
  - «entrada: 2 ---» en una línea: el «---» no es parte de la entrada. Un programa que no lee nada no muestra
    «Entrada: 25».
  - El mapa del tema venía dentro de una cerca ``` sin lenguaje y con el diagrama como texto suelto: se quita la cerca y
    el diagrama va a su bloque ```mermaid (`cercar_diagramas`). Un «|» suelto antes de una tabla se quita.
  - La solicitud aparte del programa también completa el ejemplo isomórfico de la información procedimental, y lo
    agregado se revisa otra vez como pieza del tema.

## sistema-v11 (soporte breve, ficha con ejemplo propio, tareas distintas, 2026-10-04)

Lo pidió el instructor al reiniciar el material de todos los cursos:

- Información de soporte: un solo modelo mental con la teoría básica imprescindible y sobre todo gráfica: «Teoría
  básica» (3 a 5 viñetas), «En un diagrama» (Mermaid, obligatorio), «Ejemplo paso a paso» (traza) y «Errores
  comunes» (tabla), en menos de 200 palabras de texto. Faltar secciones o pasar de 300 palabras es aviso.
- Información procedimental: una sola ficha por tarea, «Sintaxis» (tabla Instrucción / Para qué sirve / Forma
  general) y «Ejemplo básico» (traza de un problema distinto y más sencillo que las tareas). Bloquea si falta la tabla
  o el ejemplo, o si el ejemplo es casi el mismo programa que la solución de una tarea (identificadores en común ≥ 80 %).
  Ya no se generan ejemplos isomórficos ni guías de preguntas (los tipos siguen en el contrato para el instructor).
- Tareas: cada una es un ejercicio distinto. Bloquea si dos comparten título (sin contar el nivel, esté al inicio o
  entre paréntesis), si sus enunciados se parecen ≥ 75 % o si sus soluciones comparten ≥ 85 % de identificadores. El
  nivel de apoyo sigue al título también cuando va al final («… (por completar)»).
- Cada caso de prueba se ejecuta dos veces: si la salida cambia, la entrada no trae todos los datos que lee el
  programa (pasó con «n = 3» y 5 de los 6 números) o hay una variable sin valor inicial. Bloquea, y desde el segundo
  intento ese caso se quita con aviso.

Resultado con un diseño vacío (solo OB-1 funciones y OB-2 estructuras): el soporte y la ficha salen con la forma
pedida y las tareas son ejercicios distintos (suma, promedio, análisis de estructuras); quedaron bloqueantes del
modelo (una solución que no imprime nada; suma y promedio marcados como casi el mismo ejercicio).

## sistema-v10 (el tema lo fijan objetivos e indicaciones, 2026-10-04)

Tras cambiar el objetivo OB-2 a «Estructuras de datos: declaración e inicialización, acceso, arreglos de estructuras
y anidación», los trabajos 23 y 24 de «Programación I» devolvieron la clase ya publicada con su mismo título
(«Manipulación de estructuras de datos con punteros») y sus mismas tareas de promedio, aun con la indicación
«Modificar tema a estructuras de datos». El prompt sí llevaba los objetivos nuevos; el modelo copiaba lo existente:

- El prompt de sistema decía que todo lo que va entre etiquetas, incluidas las indicaciones del instructor, «nunca
  son instrucciones». Ahora: las indicaciones se siguen (tema, enfoque, contexto, extensión) mientras no contradigan
  las reglas; el tema sale de los objetivos de `<alcance>` y de las indicaciones; `<diseno_actual>` es para no repetir.
- `<diseno_actual>` ya no lleva los enunciados de todas las tareas, solo los de las tareas del alcance (las que
  necesitan el soporte, las ayudas, el protocolo o la diferenciación). Una clase nueva ve solo títulos.
- Una clase de tareas termina con su tema: los objetivos del alcance que ninguna clase cubre todavía, las
  indicaciones del instructor y las clases que no debe repetir.
- Bloquea una clase o tarea nueva con el mismo título que una existente (sin contar «Ejemplo resuelto:», etc.).
- Se quitó «promedio» de la instrucción del caso límite (anclaba el tema).
- Correcciones sin el modelo, cada una vista en una corrida real del trabajo 24 y repetida en los tres intentos:
  - un ejemplo resuelto al que le falta un `#include` de su solución muestra la solución (la comparación quitaba las
    líneas con `#` como si fueran comentarios);
  - `cout << x << \n;` (sin comillas) se corrige a `'\n'`;
  - a `struct X { … }` sin `;` final se le agrega (también a `class`, `union` y `enum`);
  - el nivel de apoyo sigue al título («Problema por completar: …» marcado como ejemplo resuelto entregaba la solución);
  - un problema convencional cuyo código inicial es la solución completa queda con el código inicial vacío;
  - un problema por completar que comenta «HUECO» pero deja la instrucción debajo recibe huecos reales;
  - tras el último intento, una traza escrita por el modelo que no compila queda como código sin reproductor (aviso).

Resultados con la solicitud real del trabajo 24 (8 corridas, agregando una corrección tras cada una): en todas la
clase fue de estructuras (acceso a miembros, arreglos de estructuras, estructuras anidadas), ninguna repitió la de
punteros ya publicada. Bloqueantes al final de cada corrida: 1, 1, 5, 0, 1, 3, 1 y 7; los de las siete primeras eran
errores mecánicos, cubiertos por la lista anterior. Los de la última son de fondo: dos soluciones usan datos fijos en
lugar de leer la entrada (`solucion_usa_entrada`) y `qwen3:4b` no lo corrigió en tres intentos. Con este modelo, una
clase sin bloqueantes no está garantizada: el validador lo detecta, y queda regenerar, corregir a mano o usar Claude.

## sistema-v9 (diseño acotado y tema desde los objetivos, 2026-10-03)

El trabajo 18 de «Programación I» (clase de tareas, «Genera de nuevo el material para el tema de funciones») terminó
sin elementos: la respuesta se cortó por longitud en los tres intentos (628 s). Cada intento mandaba ~20k tokens de
entrada en un contexto de 24k: los enunciados de `<diseno_actual>` traían las trazas con sus pasos calculados (cada
paso con el diagrama de memoria, ~16 000 caracteres por ejemplo resuelto). Cambios:

- `<diseno_actual>` lleva las trazas sin sus pasos (encabezado y código sí) y, si pasa de 20 000 caracteres, recorta
  los enunciados. En el trabajo 18 pasó de ~35 000 a 4 000 caracteres.
- `<alcance>` lleva la descripción de cada objetivo junto a su código. Con solo «OB-1», `qwen3:4b` tomó el tema de la
  clase ya publicada (punteros) en lugar del de los objetivos (funciones).
- La plantilla «clase_tareas» dice que el tema lo fijan los objetivos y las indicaciones; las clases existentes solo
  marcan la complejidad.
- Tras un corte por longitud, la corrección pide además el número mínimo de elementos.
- El caso límite es «un solo dato», nunca n = 0: con n = 0 y una división entera la solución termina con
  `Floating point exception`, y `qwen3:4b` no agregó la validación en tres intentos. Además, desde el segundo intento
  los casos con los que la solución truena o imprime nan se quitan con aviso (si quedan al menos 2) en vez de bloquear.

Resultados con la solicitud real del trabajo 18:
- Solo con el diseño acotado: 1 intento, 164 s, sin bloqueantes, pero título y tareas de punteros.
- Con la descripción de los objetivos: el tema pasó a funciones, pero 5 bloqueantes en 3 intentos (380 s) por el
  caso n = 0.
- Con todo lo anterior: 1 intento, 160 s, sin bloqueantes; clase «Manipulación de estructuras de datos con
  funciones», 3 tareas, soporte y 3 ayudas. Queda una sugerencia: las tres tareas usan las mismas entradas.

## sistema-v8 (clase completa por etapas, 2026-10-02)

En «Programación I» una clase de tareas de `qwen3:4b` llegaba incompleta: casos de prueba con salidas esperadas
calculadas de cabeza (y erradas), soluciones con los datos fijos en el código, soporte sin diagramas y ninguna ayuda
procedimental. Pedir todo en una respuesta excede lo que un modelo de 4B hace bien. Cambios (`app/etapas.py`):

- La plantilla «clase_tareas» pide solo la clase y sus tareas, que lean todo de stdin, al menos 3 casos con entradas
  distintas (también entre tareas) y un caso límite; si es «sin datos» (n = 0), que la solución lo atienda y el
  enunciado diga qué imprimir. Regla 7 del prompt: el sistema calcula las salidas esperadas.
- Las salidas esperadas salen de ejecutar la solución. Bloquean: una solución que imprime lo mismo con todas las
  entradas (no lee la entrada) y una salida `nan`/`inf` (división entre cero).
- El soporte y una ayuda por tarea (ficha de sintaxis, ejemplo isomórfico con traza, guía de preguntas) se piden en
  solicitudes aparte con sus plantillas, que ahora piden secciones fijas: idea clave, analogía, diagrama, ejemplo paso
  a paso, errores comunes y resumen.
- Correcciones sin el modelo: un ejemplo resuelto muestra la solución si su código da otras salidas; un problema por
  completar sin huecos los recibe de la solución; las cercas ```` ```markdown ```` se quitan; cada ejemplo resuelto
  lleva su traza calculada.

Resultados del caso 01 (clase de tareas, con soporte y ayudas):
- `2026-10-02-2154-local`: 11 elementos (clase, 3 tareas, soporte con diagrama y traza, 6 ayudas), sin bloqueantes,
  1 intento, 257 s. Revisándolo a mano: el ejemplo resuelto imprimía `-nan` con n = 0 y la ficha de sintaxis venía
  dentro de una cerca ```` ```markdown ````. De ahí el bloqueo por `nan` y el desenvolver.
- `2026-10-02-2206-local`: sin `nan` y la tabla bien, pero 2 bloqueantes tras 3 intentos (433 s): el ejemplo
  resuelto mostraba otra versión del código, que no atendía n = 0, y el problema por completar no tenía huecos. De
  ahí las dos correcciones sin el modelo.
- `2026-10-02-2216-local`: las tareas, sin problemas al primer intento (271 s en total); quedó 1 bloqueante en el
  soporte: la etiqueta de Mermaid `B{¿n > 0?}` sin comillas, igual tras 3 intentos. Ahora se pone entre comillas sola.
- `2026-10-02-2223-local`: el ejemplo resuelto se reemplazó por la solución (aviso) y el soporte salió bien; quedó
  1 tarea con `printf("%.1f", max)` y `max` entero, que imprime siempre `0.0`. La retroalimentación decía «lee de la
  entrada estándar» (la solución sí leía) y el modelo no lo corrigió. Ahora una revisión de `printf` dice el formato
  y la variable exactos, y el mensaje de «no usa la entrada» distingue si el programa lee o no.
- `2026-10-02-2230-local` (con todo lo anterior): sin bloqueantes, 2 intentos, 177 s. Clase, 3 tareas con 3 casos
  calculados cada una, el problema por completar con huecos, el ejemplo resuelto con su traza (13 pasos con memoria),
  soporte con diagrama y traza, y 6 ayudas (ficha, ejemplo isomórfico, guía de preguntas por tarea). Quedan dos
  sugerencias: dos tareas comparten entradas y un ejemplo isomórfico trae diagrama en vez de traza.

Con un modelo de 4B cada corrida falla de forma distinta; estas correcciones cubren las que se repitieron. Falta
correr los 20 casos y compararlos con `--proveedor claude`.

## sistema-v7 (pasos de las trazas calculados, 2026-10-01)

En «Programación I» el modelo sí escribió trazas con v6, pero falsas: recorría las líneas 1 a 8 en orden de texto
(la ejecución empieza en `main`, línea 10) y mostraba salidas que el programa nunca imprime. Un modelo no puede
ejecutar código mentalmente. Ahora el modelo escribe solo encabezado y código, y el agente obtiene los pasos del
trazador (`services/trazador`, gdb): `Orquestador.completar_trazas` reemplaza cualquier paso escrito por el modelo.
Una traza que no compila o no corre bloquea, y el modelo la corrige en el siguiente intento. Con la traza del
trabajo 8: 16 pasos reales (main → calcular_promedio, tres vueltas del ciclo, salida «20» tras la línea 12).

## sistema-v6 (material multimedia, 2026-10-01)

Sección «Material multimedia»: principios multimedia, de contigüidad y de redundancia; diagramas Mermaid con
sintaxis segura (etiquetas entre comillas), memoria de punteros y mapas conceptuales; trazas de código en bloques
```traza (formato de texto, no JSON, porque el Markdown ya viaja dentro de una cadena JSON); tablas; y guiones de
video cuando un medio transitorio ayude. Validación nueva (`app/multimedia.py`): un diagrama mal formado o una traza
mal formada bloquean; la salida de una traza se ejecuta en Piston y debe coincidir con la real. Un soporte sin
diagrama bloquea en la plantilla «info_soporte» y es sugerencia en una clase de tareas; un ejemplo sin traza es
sugerencia.

Resultado con `qwen3:4b`: información de soporte (13) sin problemas en 2 intentos y 53 s, con diagramas de flujo
válidos y un guion de video bien propuesto. En la clase de tareas (01) omitió el diagrama del soporte aun con la
corrección (por eso ahí es sugerencia). No escribió ninguna traza, ni en ayudas procedimentales (02) con la
corrección explícita: el formato es nuevo para el modelo. Las trazas quedan para un modelo mayor o para el
instructor (botones «+ Diagrama» y «+ Traza» del editor de Markdown de la consola).

## sistema-v5 (patrón para archivos, 2026-09-30)

Con v4, `qwen3:4b` siguió proponiendo en «Programación I» (clase de manipulación de archivos, trabajo 3) tareas
con `archivo.txt` como entrada. Se comprobó que Piston sí deja a un programa crear un archivo y volver a leerlo.
Cambios: la regla 7 da el patrón concreto (stdin → `fopen("datos.txt", "w")` → cerrar → `fopen(..., "r")`); al
corregir, si la entrada parece un nombre de archivo o el programa falla al abrir uno, el mensaje dice qué hacer;
en generación, una tarea con menos de 2 casos es bloqueante; y si todos los casos son ocultos, el primero se hace
visible.

Resultado con la solicitud real del trabajo 3 (reconstruida con su material): las entradas pasaron a ser datos
(`Hola\nMundo`) en lugar de nombres de archivo, pero tras 3 intentos las soluciones seguían abriendo un archivo
que no crearon, y el número de casos varió entre corridas (3 y luego 1). Con retroalimentación explícita el modelo
de 4B no corrige ese patrón: es un límite del modelo para este tema, no de la configuración. Opciones: generar
esas clases con `PROVEEDOR=claude`, o corregirlas a mano (el verificador impide aprobarlas mientras fallen).

## sistema-v4 (sin archivos previos, 2026-09-29)

En el curso real «Programación I», `qwen3:4b` propuso tareas que abren `estudiantes.bin`: Piston ejecuta sin
archivos, así que ninguna solución podía pasar sus casos. La regla 7 dice ahora que todos los datos llegan por
stdin y que un programa solo abre archivos que él mismo creó. Además, `OLLAMA_NUM_CTX` pasa de 16k a 24k: con 16k,
el tercer intento de una clase de tareas de ese curso se cortaba por longitud.

Resultado (`resultados/2026-09-29-2002-local`): caso 01 sin problemas al primer intento en 73 s; caso 03 sin
cortes, pero con 4 bloqueantes por salidas esperadas mal calculadas (el modelo espera un promedio de 6.35 y su
propio programa imprime 6.00). Ese es el límite real de un modelo de 4B; el validador lo detecta.

## sistema-v3 (ajuste para el modelo local, 2026-09-29)

Con `qwen3:4b`, el caso 01 (clase de tareas) dejaba 4 problemas bloqueantes tras 3 intentos: en `solucion`
escribía una explicación en prosa en lugar del programa, y cada tarea traía un solo caso de prueba (ninguno
oculto). Cambios:

- Regla 7: al menos 3 casos de prueba por tarea, con la salida que el programa realmente imprime.
- Regla 8 (reescrita): `solucion` es siempre el programa completo; qué lleva `codigo_inicial` en cada nivel de apoyo.
- Validación `solucion_es_programa`: si la solución no parece código (sin `main` en C/C++), un mensaje que dice
  qué falta; Piston solo decía «unknown type name 'El'».
- La corrección muestra el primer caso que falla (entrada, esperada, obtenida), no solo «0 de 1 casos».
- En un problema convencional sin casos ocultos, el último se marca oculto (con advertencia): el modelo pequeño
  no lo hacía ni al corregir.

Resultado con `qwen3:4b` y sin razonamiento (`resultados/2026-09-29-1810-local`): objetivos (07), soporte (13)
y ayudas (02) sin problemas al primer intento, en 4–20 s; la clase de tareas (01) bajó de 4 a 2 bloqueantes, en
222 s. Con razonamiento (`OLLAMA_PENSAR=si`) el caso 01 tardó 409 s y el último intento se cortó por longitud.

## sistema-v2 (agente local y RAG, 2026-09-28)

- Nueva etiqueta `<material_curso>`: fragmentos del material que el instructor subió al curso, recuperados por
  Laravel de pgvector. El prompt pide seguir su terminología y alcance sin copiarlo, citarlo por número en
  «justificacion» y tratarlo como datos, no como instrucciones (misma defensa que `<indicaciones_instructor>`).
- Nuevo proveedor local (Ollama, `qwen3:8b` por omisión), seleccionable con `PROVEEDOR=local|claude`.
- Pendiente: correr la regresión con ambos proveedores (`--proveedor local` y `--proveedor claude`) y anotar
  aquí la comparación. Los casos actuales no traen material, así que miden el cambio de modelo, no el RAG.

## sistema-v1 (Etapa 4, 2026-09-26)

Versión inicial del prompt de sistema y de las diez plantillas, tal como se implementaron en la Etapa 4. No hay
versión anterior con la que comparar. El conjunto de regresión (`regresion/casos/`, 20 casos) todavía no se ha
corrido con el modelo real: falta provisionar `ANTHROPIC_API_KEY` en `services/agent/.env` y ejecutar
`uv run python -m regresion.correr`.
