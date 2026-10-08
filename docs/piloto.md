# El piloto paso a paso

El piloto es el estudio empírico de la tesis: la plataforma lo sostiene, pero lo gobierna el
protocolo aprobado. Por eso la regla de oro del semestre es la estabilidad: el «tratamiento» que
reciben los estudiantes no debe cambiar a mitad del estudio por una actualización del sistema.

Nada de este documento puede ejecutarse desde este entorno: requiere un servidor real, la
aprobación de un comité de ética y estudiantes reales. Queda como protocolo a seguir cuando esas
condiciones existan.

## 1. Calendario

Las semanas cuentan desde el primer día de clase (semana 0):

| Cuándo | Qué |
|---|---|
| 8 a 6 semanas antes | Aprobación del comité de ética: protocolo, consentimiento y asentimiento, y aviso de privacidad con todos los encargados (servidor, correo y monitoreo; la API de Claude solo si el agente usa `PROVEEDOR=claude`, ADR 0006). Decisión del proveedor del agente (local o Claude): no se cambia durante el piloto. Trámite del certificado de firma y del servidor |
| 4 semanas antes | Curso completo en la consola: objetivos, pre-test y post-test en formas A y B, clases de tareas con su verificador en verde. Revisión por una persona experta en la materia |
| 3 semanas antes | Ensayo general y pruebas de usabilidad (7.4); corrección de lo que salga |
| 2 semanas antes | Puesta a cero (paso 2), versión `v1.0.0`, textos legales definitivos con su versión (`CLT4BP_VERSION_PRIVACIDAD` y `CLT4BP_VERSION_INVESTIGACION`), claves de `configuracion` del curso (6.6) y consola 1.0.0 instalada a los instructores |
| 1 semana antes | Inscripción de estudiantes, sesión de bienvenida presencial (registro, consentimientos y un recorrido por el aula) y apertura del diagnóstico |
| Semana 0 | Cierre del diagnóstico, análisis del grupo y decisión homogéneo o heterogéneo (Etapa 2), grupos y variantes, publicación y apertura de la primera clase |
| Semanas 1 a N | Clases de tareas; seguimiento diario del tablero; escala CS al cerrar cada clase |
| Semana N + 1 | Evaluación final: post-test en forma B, MSLQ e IMMS (6.1) |
| Semana N + 2 | Revisión de resultados (paso 10), informe del agente, decisión de cerrar o iterar, exportación final y respaldo de cierre |
| Después | Análisis en R o JASP, archivo de los datos según el plan de gestión y retrospectiva del desarrollo |

## 2. La puesta a cero

Después del ensayo general, la base tiene cuentas sintéticas, cursos de prueba y la basura del
escaneo activo. Nada de eso debe mezclarse con los datos del estudio. Como la base de producción
tiene prohibidos los comandos destructivos de Laravel (`DB::prohibitDestructiveCommands`, Etapa
1), se borra desde PostgreSQL, a propósito y una sola vez:

```bash
# 1. Nadie escribe en la base
artisan down
sudo supervisorctl stop all
sudo systemctl stop clt4bp-agente

# 2. Base nueva, vacía (--force cierra las conexiones que queden abiertas)
sudo -u postgres dropdb --force clt4bp
sudo -u postgres createdb -O clt4bp clt4bp
sudo -u postgres psql -d clt4bp -c "CREATE EXTENSION IF NOT EXISTS vector"

# 3. Archivos subidos durante el ensayo
sudo -u deploy find /srv/clt4bp/shared/storage/app/private /srv/clt4bp/shared/storage/app/public -mindepth 1 -delete

# 4. En web.env: MAIL_MAILER=smtp de nuevo y ADMIN_PASSWORD temporal. Luego, la versión del piloto
sudo -u deploy nano /srv/clt4bp/shared/web.env
artisan up                                          # la comprobación final de desplegar.sh necesita /up en 200
sudo -iu deploy /srv/clt4bp/desplegar.sh v1.0.0
artisan db:seed --force
for f in /srv/clt4bp/current/instruments/*.json; do artisan instrumentos:cargar "$f"; done
sudo -u deploy sed -i 's/^ADMIN_PASSWORD=.*/ADMIN_PASSWORD=/' /srv/clt4bp/shared/web.env
artisan optimize
sudo supervisorctl start all
```

Después: activa de nuevo la 2FA del administrador (la base es nueva) e invita a los instructores;
apunta `RESTIC_REPOSITORY` a una ruta nueva y corre `restic init`, para que los respaldos del
piloto no arrastren los del ensayo (pide que borren la ruta anterior); y marca como resueltos los
errores del ensayo en Sentry. Si en el futuro necesitas otra puesta a cero, la necesidad misma es
una señal de alarma: con datos reales, nunca.

## 3. Durante el piloto

- **Código congelado.** Solo se despliegan correcciones de errores: una rama desde la etiqueta
  vigente, su prueba automática, la integración continua en verde y una etiqueta `v1.0.x`. Nada
  de funciones nuevas ni actualizaciones de dependencias que no sean de seguridad.
- **Contenido versionado.** Corregir un enunciado o un caso de prueba sí se vale, pero como una
  publicación nueva desde la consola (Etapa 5): queda con su número, su huella y su fecha, y los
  eventos registran qué versión vio cada estudiante.
- **Bitácora del piloto.** En [`docs/piloto/bitacora.md`](piloto/bitacora.md), una línea por cada
  cambio o incidente: fecha, qué pasó, qué se hizo y a quiénes afectó. En la discusión de la tesis
  responde a la pregunta «¿qué más pudo influir en los resultados?».
- **Rutina diaria.** Antes de cada clase: correos de alerta, estado del tablero (quién no ha
  entrado, quién se esfuerza mucho con poco resultado) y comentarios por moderar. Después de cada
  clase: cuántos contestaron la escala CS.
- **Soporte a estudiantes.** Un canal único (correo o foro de la institución) y una respuesta en
  menos de un día hábil. Si un problema afecta a varios, publica un aviso en la plataforma en
  lugar de responder uno por uno.

## Lista de la Etapa 7

- [ ] En verde: `php artisan test`, `npm run types:check` (web), `npm test` y `npm run typecheck`
      (consola), `uv run pytest` (agente) y la auditoría semanal de dependencias.
- [ ] Servidor: HTTPS con calificación A, HSTS y CSP; hacia fuera solo 22, 80 y 443;
      `APP_DEBUG=false`; configuración, rutas y vistas en caché.
- [ ] Piston sin red y con límites; el agente y Ollama solo en 127.0.0.1; el agente con su secreto; con
      `PROVEEDOR=local`, los modelos instalados y una generación de prueba dentro de `AGENTE_TIMEOUT`; con
      `PROVEEDOR=claude`, clave de producción en su propio espacio de trabajo con límite de gasto.
- [ ] Respaldos diarios cifrados fuera del servidor; prueba de restauración correcta; simulacro de
      desastre hecho y su tiempo anotado; clave de restic en tu gestor de contraseñas.
- [ ] Monitoreo: la revisión de cada 5 minutos avisa por correo; tres vigilancias externas en
      verde; Sentry recibe errores sin datos personales.
- [ ] Consola: instalador firmado publicado, fuses verificados y actualización de 1.0.0 a 1.0.1
      probada en una máquina limpia.
- [ ] Ensayo: las cuatro pruebas de Playwright pasan; k6 cumple sus umbrales; ZAP sin FAIL y con
      cada WARN justificado; SUS de 68 o más en los dos roles y sin problemas de gravedad 3 o 4
      pendientes.
- [ ] «Mis datos» funciona (revocar y descargar); el aviso de privacidad nombra a cada encargado y
      el plazo de conservación de los datos.
- [ ] Aprobación del comité vigente y textos legales cargados con su versión.
- [ ] Puesta a cero hecha; administrador con 2FA; instrumentos cargados; valores de configuración
      del curso anotados en el protocolo.
- [ ] `docs/operacion.md` impreso y `CLAUDE.md` actualizado («Etapa actual: piloto»).
- [ ] Etiqueta de versión: `git tag v1.0.0` y `git push --tags`.

`v1.0.0` marca el despliegue real usado con estudiantes durante el piloto, no el estado del
código en este repositorio: exige la puesta a cero, la aprobación del comité y un servidor de
producción real, ninguno de los cuales existe en este entorno de desarrollo. Ver la nota en
`CLAUDE.md` sobre el estado de esta etapa.

## Después del piloto

La decisión del paso 10 abre el siguiente ciclo: si fue «iterar», el Estudio de diseño ya muestra
tus notas (6.5) y empiezas por la fase que elegiste. Del lado de la plataforma, anota en el
tablero del proyecto lo que quedó fuera por diseño de esta primera versión (avisos en tiempo real
con Reverb y almacenamiento de medios compatible con S3, las dos simplificaciones de la tabla de
Inicio, y la selección adaptativa encendida por omisión si el estudio la respalda) y prioriza
según lo que enseñó el piloto, no antes.
