# Procedimiento de operación

Tres garantías para el semestre del piloto: si el servidor se pierde, los datos no; si algo
falla, te enteras antes que tus estudiantes; y cuando algo pase, hay un procedimiento escrito que
seguir, aunque sea de madrugada. Guarda una copia impresa: cuando algo falla, no es momento de
buscar.

## Vigilancia desde fuera

`operacion:revisar` corre dentro del servidor cada 5 minutos y avisa por correo si algo anda
mal, pero si el servidor entero cae, o si el programador de tareas deja de correr, nadie la
ejecuta. Por eso, además, un servicio de monitoreo externo (el de tu institución si existe; si
no, uno como UptimeRobot o Healthchecks.io) con tres vigilancias:

| Vigilancia | Tipo | Configuración |
|---|---|---|
| La web responde | Revisión HTTP cada 5 minutos | `https://clt4bp.midominio.mx/up` debe responder 200 |
| La revisión corre | Latido (heartbeat): el servicio espera un aviso periódico | Periodo 5 min, margen 10 min; su URL va en `CLT4BP_PING_REVISION` |
| El respaldo corre | Latido | Periodo 1 día, margen 2 h; su URL va en `PING_RESPALDO` de `restic.env` |

Si un latido no llega a tiempo, el servicio te escribe: así también te enteras de que el
servidor se apagó.

## Qué revisar y cuándo

| Cuándo | Qué revisar |
|---|---|
| Cada día de clase | Los correos de alerta; en la consola, las alertas pedagógicas del tablero (6.4) |
| Cada semana | Sentry; `sudo supervisorctl status`; `df -h`; los pull requests de Dependabot (durante el piloto, fusiona solo los de seguridad) |
| Cada mes | `sudo clt4bp-probar-restauracion`; el gasto del agente; `sudo certbot renew --dry-run`; las cuentas del personal (baja de quien ya no participa) |
| Al cerrar el piloto | Exportación final (6.5), un respaldo etiquetado aparte (`restic backup --tag cierre ...`) y, según el protocolo, borrado o anonimización de los datos |

## Síntoma → primer paso

| Síntoma | Primer paso |
|---|---|
| La web no carga | `systemctl status nginx php8.5-fpm` y la bitácora del día en `/srv/clt4bp/shared/storage/logs/` |
| Los envíos se quedan en «Calificando...» | `sudo supervisorctl status`; si un trabajador está detenido, `sudo supervisorctl restart clt4bp-cola:*` |
| «Ejecutar» falla para todos | `docker ps`; `sudo docker compose -f /opt/clt4bp-piston/compose.yml restart` |
| El agente no genera | `journalctl -u clt4bp-agente -n 50`. Con `PROVEEDOR=local`: `systemctl status ollama` y `ollama list` (faltan modelos → `ollama pull`). Con `claude`: el estado de la API y el límite de gasto |
| Un material queda en «Error» | Si dice «texto extraíble», es un PDF escaneado: pásalo por OCR. Si no, revisa Ollama (`bge-m3` instalado) y que el instructor lo elimine y lo suba de nuevo |
| Una versión nueva rompió algo | `regresar.sh` (7.2) y después investigas |
| Disco lleno | Bitácoras viejas en `shared/logs`, versiones en `releases/` (el despliegue conserva cinco) y `sudo docker system prune` |
| Sospecha de acceso indebido o fuga | Contener primero: cambia las claves afectadas y revoca los tokens de la consola (`artisan tinker --execute="DB::table('personal_access_tokens')->delete();"`). Después evalúa el alcance con la bitácora de auditoría y avisa al responsable de datos de tu institución: si la vulneración afecta de forma significativa los derechos de las personas, la ley de protección de datos obliga a informárselo sin demora |

## El gasto del agente, en tres capas

Cada instructor tiene su cuota mensual (Etapa 4); la revisión automática avisa si el gasto del
día pasa el umbral (`CLT4BP_ALERTA_GASTO_DIARIO_USD`); y el límite de gasto del espacio de
trabajo en la Claude Console (7.2) corta todo si lo demás falla. Una vez al mes, revisa el gasto
real contra tu presupuesto:

```sql
select to_char(created_at, 'YYYY-MM') as mes, count(*) as generaciones, round(sum(costo_usd), 2) as usd
from agent_runs group by 1 order by 1;
```

## Cambiar un secreto

- El proveedor del agente, los modelos locales o la clave de Claude: en `agente.env` y `sudo systemctl restart clt4bp-agente`.
- El secreto compartido (`AGENTE_TOKEN`): en los dos `.env` a la vez, con `artisan optimize` y el
  reinicio del agente.
- La `APP_KEY`: moviendo la anterior a `APP_PREVIOUS_KEYS` antes de generar la nueva, para que
  Laravel siga descifrando lo ya cifrado.

## Verificación de este procedimiento

- `php artisan test --filter="Diagnostico|Operacion"` en tu PC (5 pruebas).
- En el servidor, `artisan operacion:revisar` dice «Todo en orden». Detén el agente
  (`sudo systemctl stop clt4bp-agente`): en menos de 5 minutos llega el correo; arráncalo de
  nuevo.
- Provoca un error en la web (una ruta de prueba que lance una excepción, en una rama que no
  fusionarás) y comprueba en Sentry que el evento llegó sin IP ni cuerpo.
- Los tres monitores externos están en verde.
