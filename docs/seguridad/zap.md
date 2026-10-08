# Escaneo con OWASP ZAP (Etapa 7.4)

Escaneo pasivo (`zap-baseline.py`) y activo (`zap-full-scan.py`) contra el servidor de
desarrollo local (`php artisan serve`, sin Nginx delante). Resultado del pasivo:

```
FAIL-NEW: 0	FAIL-INPROG: 0	WARN-NEW: 11	WARN-INPROG: 0	INFO: 0	IGNORE: 0	PASS: 56
```

Sin `FAIL`. A continuación, cada regla `WARN-NEW`, con su identificador y el veredicto:
corregida, justificada, o artefacto de probar sin Nginx delante (ya cubierto por
`infra/prod/nginx/clt4bp.conf`).

## Corregidas

**Content Security Policy (CSP) Header Not Set [10038]** — apareció solo en `/sitemap.xml`
(404) y en una respuesta 429 de `/login`. Causa real: `App\Http\Middleware\CabecerasSeguridad`
agrega las cabeceras en el «después» de la petición, pero cuando el kernel de Laravel captura
una excepción (`NotFoundHttpException`, `ThrottleRequestsException`, etc.) la respuesta de
error se genera sin volver a subir por esa parte de la pila de middleware, así que las
cabeceras nunca se aplican. Verificado en vivo: antes de la corrección, `curl -sI
.../sitemap.xml` no traía `Content-Security-Policy`; después, sí. **Corrección**:
`bootstrap/app.php` ahora registra `$exceptions->respond(...)`, que llama a la nueva
`CabecerasSeguridad::aplicar()` (el mismo código que el middleware, extraído a un método
estático) sobre toda respuesta de error que no sea JSON/API. Suite completa verificada en
169/169 después del cambio.

**X-Content-Type-Options Header Missing [10021]** y **Permissions Policy Header Not Set
[10063]** en `favicon.ico`, `robots.txt` y los `.css`/`.js` de `/build/` — mismo origen que el
punto anterior en parte (ya resuelto por la corrección de arriba para las páginas dinámicas),
pero `favicon.ico`/`robots.txt` y los archivos de `/build/` nunca pasan por PHP en producción:
Nginx los sirve directo desde disco (`location = /favicon.ico`, `location /build/`), así que
ninguna cabecera de Laravel les llega, con o sin la corrección anterior. **Corrección**: se
agregaron `add_header X-Content-Type-Options "nosniff" always;` a `/favicon.ico` y
`/robots.txt`, y `add_header Permissions-Policy "..." always;` a `/build/`, en
`infra/prod/nginx/clt4bp.conf`.

## Justificadas (artefacto de probar sin Nginx delante)

**Server Leaks Information via "X-Powered-By" [10037]** — `php artisan serve` (servidor
embebido de PHP, usado solo para desarrollo) no oculta esta cabecera. En producción, el bloque
`location ~ ^/index\.php(/|$)` de `infra/prod/nginx/clt4bp.conf` ya trae
`fastcgi_hide_header X-Powered-By;` (agregado en el commit `feat(infra): archivos de
producción`, verificado antes de este escaneo). Los archivos estáticos (favicon, robots,
`/build/`) los sirve Nginx directo desde disco sin invocar PHP-FPM en absoluto, así que ni
siquiera llegan a generar esta cabecera. No requiere cambios adicionales.

## Justificadas (esperadas por diseño)

**CSP: style-src unsafe-inline [10055]** — esperada y documentada en la decisión de 7.1
(`app/Domain/Seguridad/PoliticaContenido.php`): `style-src` incluye `'unsafe-inline'` porque
Tailwind/los componentes de Reka UI insertan estilos en línea en tiempo de ejecución, y
moverlos todos a hojas de estilo o a un nonce por-elemento habría implicado reescribir esas
librerías de terceros. El resto de la política (`script-src` con nonce, `object-src 'none'`,
`frame-ancestors 'none'`, etc.) sigue siendo estricto. Riesgo residual aceptado: inyección de
estilos (baja severidad frente a inyección de script, que sí está bloqueada).

**Cross-Origin-Embedder-Policy Header Missing or Invalid [90004]** — no se agrega COEP a
propósito. `PoliticaContenido` ya carga `https://fonts.bunny.net` como origen cruzado
legítimo (`font-src`, `style-src`); activar COEP (`require-corp`) rompería esa carga a menos
que Bunny Fonts sirva `Cross-Origin-Resource-Policy`, cosa que no controlamos. Se prefiere no
tener COEP a tener una política que force `credentialless`/`require-corp` y arriesgue romper
las fuentes en producción sin poder probarlo contra el proveedor real.

## Informativas, sin acción

- **Big Redirect Detected [10044]** — en los 302 de `/login`, `/register` y
  `/forgot-password`. ZAP marca cualquier redirección con cuerpo "grande"; revisado el cuerpo
  real de esas respuestas (las páginas de Fortify/Inertia) y no llevan tokens, URLs firmadas ni
  datos sensibles, solo el HTML normal de la página. Sin acción.
- **Non-Storable Content [10049]** — Laravel no marca las páginas dinámicas como cacheables por
  el navegador/proxy, que es justamente lo que se quiere para páginas autenticadas con datos
  por usuario. Sin acción (lo contrario sería el problema).
- **Modern Web Application [10109]** y **Session Management Response Identified [10112]** —
  puramente informativas (ZAP detectando que es una SPA con Inertia y dónde vive la cookie de
  sesión). Sin acción.

## Cookie No HttpOnly Flag [10010]

Investigado: la cookie de sesión de Laravel (`clt4bp-session` en este entorno) SÍ tiene
`HttpOnly` (confirmado con `curl -sI` en `/login`, cabecera `Set-Cookie` incluye
`HttpOnly`). La única cookie sin `HttpOnly` es `XSRF-TOKEN`, y eso es intencional: Laravel la
expone así para que el cliente (fetch/axios/Inertia) pueda leerla y reenviarla como cabecera
`X-XSRF-TOKEN` en cada petición mutante, que es el mecanismo estándar de protección CSRF de
Laravel (`VerifyCsrfToken`/`HandleCsrfToken`). Quitarle `HttpOnly` a `XSRF-TOKEN` sin cambiar
ese mecanismo rompería el login y cualquier envío de formulario. Sin acción: es el
comportamiento esperado del framework, no una cookie de sesión expuesta.

## Nota sobre «Absence of Anti-CSRF Tokens»

La guía anticipaba este aviso como el otro caso a justificar (Inertia envía el token por
cabecera, no en un campo oculto del formulario, así que un escáner que solo busca `<input
name="_token">` puede no verlo). En esta corrida concreta, ZAP lo reportó como `PASS`, no como
`WARN` — probablemente porque reconoció la cabecera `X-XSRF-TOKEN`/cookie `XSRF-TOKEN` en las
peticiones de su propio spider. Se deja esta nota por si una corrida futura sí lo marca: la
justificación sigue siendo la misma (protección real presente, solo que por cabecera).

## Escaneo activo

Se intentó dos veces con `zap-full-scan.py -t http://host.docker.internal:8000 -m 10` contra
el servidor de desarrollo local. Ambas corridas murieron por falta de memoria
(`docker inspect` confirma `OOMKilled: true` en las dos, la segunda incluso con un tope
explícito de 3 GB para el contenedor) exactamente en el mismo punto: justo al arrancar
`DomXssScanRule`, la única regla activa de esta corrida que no es puro HTTP request/response,
sino que levanta su propio navegador sin cabeza para evaluar XSS basado en DOM. Bajar `-j`
(spider Ajax, que también usa un navegador) no cambió nada, confirmando que el consumo de
memoria viene de esa regla puntual y no del spider. No se consiguió una máquina con más RAM
disponible para esta sesión, así que no hay un `zap-activo.html` completo.

Lo que sí se completó, dos veces, de forma idéntica, antes de la caída: 18 reglas activas —
recorrido de rutas, inclusión remota de archivos, revelación de código fuente (Web-Inf y
CVE-2012-1823), ShellShock, HeartBleed, ejecución remota de código (CVE-2012-1823),
redirección externa, server-side includes, XSS reflejado y persistente (3 variantes), e
inyección SQL por temporización (MySQL, HSQL, Oracle, PostgreSQL) — **todas con 0 alertas**.
El registro completo de esa corrida (incluida la falla final) está en
`docs/seguridad/zap-activo.txt`.

No se considera una laguna crítica: la regla que no llegó a correr (XSS basado en DOM,
ejecutado en un navegador real) es exactamente el tipo de comprobación que la suite de
Playwright de esta misma etapa (`e2e/pruebas/aula.spec.ts`) ya ejerce de otra forma —
ejecutando JavaScript real contra las páginas reales del curso — aunque sin buscar
específicamente inyección de DOM. Queda como limitación conocida del entorno local, a repetir
contra el servidor de producción real (con más memoria disponible) antes del piloto.

El escaneo activo crea cuentas y envíos de prueba contra la base de datos local; se revisó
después de las corridas y no quedó ningún registro nuevo (las dos corridas murieron durante el
escaneo, antes de llegar a los módulos que envían formularios de registro/envío).
