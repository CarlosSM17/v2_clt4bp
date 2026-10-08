# Configuración por curso (`courses.configuracion`)

Todas las claves viven en el JSON `courses.configuracion`. Tienen un valor por omisión razonable
(la propuesta) y la API no las edita: se cambian con `php artisan tinker`, por ejemplo:

```
php artisan tinker
> $c = App\Models\Course::find(1);
> $c->configuracion = array_replace_recursive($c->configuracion ?? [], ['alertas' => ['dias_sin_actividad' => 3], 'menores' => true]);
> $c->save();
```

Anota en el protocolo de investigación los valores que uses en el piloto: forman parte del
diseño del estudio, no son un detalle técnico.

## Perfil del estudiante (Etapa 2)

| Clave | Por omisión | Qué controla |
|---|---|---|
| `perfil.peso_teorico` | 0.5 | peso del pre/post-test teórico en el conocimiento previo global |
| `perfil.peso_practico` | 0.5 | peso del pre/post-test práctico |
| `perfil.umbral_intermedio` | 40 | CP mínimo para el nivel «intermedio» |
| `perfil.umbral_avanzado` | 70 | CP mínimo para el nivel «avanzado» |
| `perfil.cv_maximo` | 0.25 | coeficiente de variación máximo para considerar el grupo homogéneo |
| `perfil.proporcion_modal` | 0.70 | proporción del nivel modal para la recomendación de homogeneidad |
| `perfil.minimo_confiable` | 10 | mínimo de estudiantes para que el análisis de grupo sea confiable |

## Agente IA (Etapa 4)

| Clave | Por omisión | Qué controla |
|---|---|---|
| `preferencias_agente` | `""` | estilo de redacción y convenciones que el instructor fija una vez |

## Evaluación, alertas y selección adaptativa (Etapa 6)

| Clave | Por omisión | Qué controla |
|---|---|---|
| `alertas.dias_sin_actividad` | 5 | días sin eventos para la alerta «Sin actividad» |
| `alertas.esfuerzo_alto` | 7 | esfuerzo (Paas, 1–9) desde el que una tarea cuenta como costosa |
| `alertas.desempeno_bajo` | 0.5 | fracción de casos aprobados por debajo de la cual el desempeño es bajo |
| `alertas.repeticiones` | 2 | tareas costosas con desempeño bajo que disparan la alerta |
| `criterio_logro` | 70 | porcentaje del post-test, por objetivo, para contar como logrado |
| `carga_extrinseca_alta` | 5 | media de carga extrínseca (escala CS, 0–10) que marca una clase en rojo |
| `menores` | false | si es `true`, la exportación exige también el consentimiento del tutor |
| `seleccion_adaptativa` | false | enciende la sugerencia de la selección adaptativa (paso 7 del aula) |
| `seleccion_minimo` | 5 | compañeros con desempeño y esfuerzo en la tarea, mínimo para poder comparar |
| `seleccion_umbral` | 0.5 | umbral de E para sugerir menos o más apoyo |
