# 0005 — `lodash-es` forzado por override (Etapa 7, auditoría de dependencias)

- Fecha: 2026-09-27
- Estado: aplicado

## Contexto
Al añadir el flujo de auditoría de dependencias (`.github/workflows/auditoria.yml`), `npm audit
--omit=dev --audit-level=high` reportó 5 hallazgos de severidad alta en `apps/web` y `apps/desktop`,
todos originados en `lodash-es` (GHSA-r5fr-rjxr-66jc, inyección de código vía `_.template`; y
GHSA-f23m-r3pf-42rh, contaminación de prototipos), consumido de forma transitiva por
`@chevrotain/gast` y `@chevrotain/cst-dts-gen` (rango `11.0.0 - 11.2.0`), a su vez dependencias de
`chevrotain`, el analizador que usa `mermaid` (`^12.0.0`, el diagramador de la Etapa 3/Estudio de
diseño).

`npm audit`'s propia sugerencia de arreglo era degradar `mermaid` a `11.17.2`, un cambio de versión
mayor en sentido inverso (de 12.x a 11.x) que no es una opción real: `mermaid@12.0.0` es una versión
muy reciente (publicada el 10 de septiembre de 2026) y todavía fija `chevrotain@~11.1.2` — no existe
aún una versión de `mermaid` que dependa de un `chevrotain` corregido.

## Decisión
Se fuerza `lodash-es` a `^4.18.1` (fuera del rango vulnerable `<=4.17.23`) con `overrides` en
`package.json` de `apps/web` y `apps/desktop`. `@chevrotain/gast` y `@chevrotain/cst-dts-gen` son
herramientas de generación de código de `chevrotain` (generan definiciones TypeScript a partir de
gramáticas); no se invocan en tiempo de ejecución al analizar el texto de un diagrama Mermaid, así que
el vector de la vulnerabilidad (`_.template` con nombres de clave controlados por quien atacaría) no es
alcanzable desde el navegador del estudiante o del instructor. Aun así, forzar la versión corregida de
`lodash-es` es gratuito (mismo rango semver, sin cambios de API) y cierra el hallazgo sin esperar a que
`mermaid` actualice su dependencia.

Verificado tras el override: `npm audit --omit=dev --audit-level=high` → 0 vulnerabilidades en las
tres carpetas auditadas; `npm run build` (web), `npm test`, `npm run typecheck` y `npx electron-vite
build` (consola) sin cambios de comportamiento.

## Consecuencias
Cuando `mermaid` publique una versión que dependa de un `chevrotain` ≥ 12.0.0 (o que dependa
directamente de una versión no vulnerable), quita el `overrides` de ambos `package.json`: ya no hará
falta. Revisa este ADR en cada auditoría semanal que vuelva a marcar `lodash-es`, `chevrotain` o
`mermaid`.
