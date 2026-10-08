# Diccionario de eventos de aprendizaje

Los eventos son datos de la tesis: documéntalos antes del piloto y no cambies el significado de un
verbo después. Se registran en `learning_events` (`App\Models\LearningEvent`, servicio
`App\Services\Aula\Eventos`), al estilo xAPI: quién (`enrollment_id`), qué hizo (`verbo`), sobre qué
(`objeto_tipo`/`objeto_uid`).

| Verbo | Origen | Objeto | `resultado` / `duracion_ms` | Para qué sirve en el análisis |
|---|---|---|---|---|
| `abrio_mapa` | cliente | curso | — | frecuencia de acceso |
| `abrio_clase` | cliente | clase | — | si el aviso funcionó; cuándo empieza cada estudiante |
| `vio_soporte` | cliente | soporte | — | uso de la información de soporte |
| `reprodujo_segmento` | cliente | medio | `{inicio_s}` | uso de la segmentación (información transitoria) |
| `abrio_tarea` | cliente | tarea | — | orden en que se recorren las tareas |
| `consulto_ayuda` | cliente | ayuda | `{tipo}` | demanda de información procedimental por nivel de apoyo |
| `tiempo_visible` | cliente | clase o tarea | ms visibles | tiempo en tarea (sin contar pestañas ocultas) |
| `abrio_practica` | cliente | curso | — | uso de la práctica de tareas parciales |
| `ejecuto` | servidor | tarea | `{compilo, excedio}` | estrategia de prueba y error |
| `envio` | servidor | tarea | `{envio_id, numero}` | intentos |
| `calificado` | servidor | tarea | `{aprobados, total, compilo}` | desempeño por intento |
| `completo_tarea` | servidor | tarea | — | estudio de ejemplos resueltos |
| `valoro_esfuerzo` | servidor | tarea | `{valor}` 1–9 | carga cognitiva percibida (Paas) |
| `comento` | servidor | tarea | `{comentario_id}` | participación en la memoria colectiva |
| `comprobo_practica` | servidor | practica | `{ejercicio, aprobados, total}` | automatización de sintaxis |
| `recibio_sugerencia` | servidor | tarea | `{eficiencia, decision, sugerida}` | cuántas sugerencias se dieron y si se siguieron (compáralo con el siguiente `abrio_tarea`) |

Los verbos del cliente (`Eventos::VERBOS_CLIENTE`) llegan en lotes desde el navegador
(`resources/js/lib/eventos.ts`, cada 10 s o al ocultar la página) y pasan por
`EventoController::store`, que solo acepta esos verbos y corrige el reloj del cliente si está muy
desfasado. Los verbos del servidor (`Eventos::VERBOS_SERVIDOR`) los registra el propio backend en el
mismo momento en que ocurre la acción, donde no se pueden falsificar.

Cada fila guarda además la inscripción (que en las exportaciones se reemplaza por su seudónimo), la
publicación vigente (`release_id`), `ocurrido_at` (reloj del cliente, corregido si está muy
desfasado) y `recibido_at` (reloj del servidor).

## Consultas de referencia

Tiempo visible por tarea y estudiante:

```sql
select e.seudonimo, l.objeto_uid as tarea, round(sum(l.duracion_ms) / 60000.0, 1) as minutos
from learning_events l join enrollments e on e.id = l.enrollment_id
where l.verbo = 'tiempo_visible' and l.objeto_tipo = 'tarea' and e.course_id = 1
group by 1, 2 order by 1, 2;
```

Intentos hasta completar y esfuerzo promedio por tarea:

```sql
select p.tarea_uid,
       count(*) filter (where p.estado = 'completada') as completaron,
       round(avg(p.intentos) filter (where p.estado = 'completada'), 2) as intentos_prom,
       round(avg(p.esfuerzo), 2) as esfuerzo_prom
from task_progress p join enrollments e on e.id = p.enrollment_id
where e.course_id = 1
group by 1 order by 1;
```

Ayudas más consultadas:

```sql
select objeto_uid as ayuda, count(*) as consultas, count(distinct enrollment_id) as estudiantes
from learning_events where verbo = 'consulto_ayuda'
group by 1 order by 2 desc;
```

Estas tres consultas se convierten en indicadores del dashboard del instructor en la Etapa 6.
