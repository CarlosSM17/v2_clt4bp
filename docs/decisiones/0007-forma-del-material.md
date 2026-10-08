# 0007 — Forma del material: el tema completo del «Mapa de ruta CLT4BP»

Fecha: 2026-10-06 · Estado: aceptada

## Contexto

El instructor compartió como modelo el material del Tema 02 («Tipos de datos, variables, constantes y entrada/salida»,
29 páginas): ficha de diseño, información de soporte con conceptos uno por uno, ejemplo resuelto con algoritmo y
pseudocódigo, mapa del tema, información procedimental (tarjeta de sintaxis, guía de preguntas, errores frecuentes con el
mensaje del compilador, ejemplo isomórfico), tareas T1–T8 asignadas por ruta, protocolo verbal y evaluación. El agente
producía piezas sueltas y escuetas (`sistema-v11`): soporte breve, una ficha por tarea y 3–5 tareas.

## Decisión

El agente genera el tema completo con esa forma, y el sistema lo guarda y lo muestra en el lugar que le corresponde:

| Pieza del tema | Dónde vive |
|---|---|
| Ficha de diseño | `clase.ficha_md` (solo el instructor) |
| Conceptos (analogía, pregunta, ¿por qué?, conceptos con código anotado y salida, ¡cuidado!, actividad) | soporte `modelo_mental` |
| Ejemplo resuelto (algoritmo, PSeInt, código, salida, verificación) | soporte `sap` |
| Mapa del tema, glosario bilingüe y fuentes | soporte `mapa_conceptual` |
| Tarjeta de sintaxis, guía de rutina, errores frecuentes, ejemplo isomórfico | procedimental **de la clase** |
| Protocolo verbal | procedimental `protocolo_verbal` de la clase |
| Tareas T1–T8 (ejemplo + gemelo, por completar con y sin pistas, convencional, solución libre, autoexplicación, imaginación, reto colaborativo) | tareas, cada una con sus `rutas` |
| Evaluación (recall y comprehension) | ítems → Banco de ítems |

Decisiones del instructor (2026-10-06):

- **Información procedimental del tema.** El contrato admite `clase_uid` como alternativa a `tarea_uid` (exactamente uno;
  no se expresa con `anyOf` porque los generadores de tipos producen uniones incómodas: lo validan `ValidadorManifiesto`,
  `TiposElemento`, el verificador del agente y la consola). El aula la muestra en la clase y en el panel de ayuda de cada
  tarea, después de las ayudas propias de la tarea.
- **Tareas por ruta.** `tarea.rutas` (claves de grupo; vacío = todos). `AplicadorVariantes::paraGrupo` quita las tareas de
  otras rutas, y con ellas sus ayudas: el aula, el mapa, el avance y el inicio lo heredan. El agente las asigna sin el
  modelo, según el papel de cada tarea (`asignar_rutas`): Ruta A (básico) el ejemplo, el primer por completar y la
  solución libre; Ruta B (intermedio y avanzado) la autoexplicación y la imaginación; todas, el resto. Si un grupo quedara
  con menos de 3 tareas, todas son para todos. El instructor las cambia en el editor de la tarea. El verificador revisa
  el desvanecimiento y el inicio con apoyo de cada grupo con las tareas de su ruta; la autoexplicación y la imaginación
  posteriores al primer ejemplo (código dado, sin traza) y el reto colaborativo convencional no cuentan como más apoyo.
- **Tema completo en cadena.** En la consola, «Generar tema completo» pide la clase y sus tareas; al guardarlas, se piden
  siete propuestas más (`PIEZAS_DEL_TEMA`), una por pieza, que se guardan o eliminan por separado. Con `qwen3:4b` cada pieza
  tarda de 1 a 5 minutos. `CLASE_COMPLETA` (la clase con sus piezas en una sola respuesta) queda apagado por omisión.
  Las ocho tareas tampoco caben en una respuesta del modelo local: `clase_tareas` trae T1–T4 y una subsolicitud
  `tareas_complementarias` agrega T5–T8 a la misma propuesta (también se puede pedir sola desde una clase guardada:
  «Más tareas para…»).
- **PDF después.** Exportar el tema con este formato queda para otra etapa.

Lo que no escribe el modelo (`app/bloques_md.py`): la salida de cada programa (bloque ` ```salida `, con su `entrada:`) y el
mensaje del compilador (bloque ` ```compilador `) los pone el sistema ejecutando el código en Piston, igual que los pasos de
una traza. Un «error» que compila o una salida sin programa bloquean; tras el último intento, el bloque se quita con aviso.
Todo programa completo del soporte, del ejemplo isomórfico y del protocolo recibe su bloque de salida aunque el modelo no lo
escriba, y la «salida esperada» que el modelo escriba en prosa se quita. Un fragmento sin `main` se ejecuta dentro de uno.
Cada salida se calcula dos veces: si cambia, a la entrada le faltan datos y el bloque no se escribe.

El ejemplo resuelto de una tarea pasa a ser **ejemplo + gemelo**: el código inicial es el ejemplo que se estudia (con su
traza) y la solución y los casos son los de su gemelo, que el estudiante escribe. Ya no se sustituye el código inicial por
la solución (delataría el gemelo); el ejemplo solo debe compilar. Las tareas de autoexplicación e imaginación (T6, T7) no
llevan traza: el estudiante deduce lo que la traza le mostraría.

El visor de Markdown (consola `components/VistaMarkdown.vue` y aula `components/clt4bp/Markdown.vue`, con la lógica en
`lib/bloques.ts`, gemelo en ambos) dibuja los recuadros por su emoji (💡 ❓ 🎯 ⚠️ 🙋 🗺 ✎ ⌨ ☑ 🐞 ⇄ 👥 🎙), el código con notas
`//→` en una columna junto a cada línea, la salida en consola, el mensaje del compilador y el pseudocódigo PSeInt.

Al estudiante no se le muestran las etiquetas de efectos de la TCC: `VistaEstudiante` excluye el bloque `diseno`
(decisiones del instructor y material de investigación). Sí ve el papel de la tarea (autoexplicación, imaginación,
solución libre, reto colaborativo), su ruta y su tiempo. El contrato no distingue la imaginación de la autoexplicación
(ambas son un ejemplo resuelto con código dado que pide explicación); el aula la reconoce por su título.

## Consecuencias

- Un tema completo con `qwen3:4b` tarda unos 15–25 minutos y puede dejar bloqueantes en alguna pieza; el validador los
  marca y cada pieza se puede regenerar sola. Con `PROVEEDOR=claude` la fidelidad al modelo será mayor.
- Las ayudas de diseños anteriores (de una tarea) siguen funcionando.
- Pendiente: exportar el tema a PDF con este formato; actualizar el manual del instructor (generar tema completo, rutas,
  guardar la evaluación en el Banco de ítems).
