# 0002 — Sesión de la consola

- Fecha: 2026-09-26
- Estado: aceptada

## Contexto
La consola del instructor (Electron) consume la API central. Debe autenticar solo al personal
(administradores e instructores), resistir el robo del token y poder revocarse.

## Decisión
- Token personal de Laravel Sanctum con la habilidad `consola`, vigencia de 7 días
  (`clt4bp.token_consola_dias`) y revocación al cerrar sesión o al suspender la cuenta.
- Inicio de sesión en dos pasos: sin código, la API responde 422 con `requires_two_factor`.
  El personal sin 2FA activa recibe 403 en la consola y es enviado a Seguridad en la web.
- El token vive solo en el proceso `main` de Electron, cifrado con `safeStorage` (DPAPI en Windows)
  en `sesion.bin`. La interfaz nunca ve el token ni toca la red: todo pasa por `window.consola`
  (preload con `contextBridge`, `sandbox`, `contextIsolation` y sin `nodeIntegration`).
- Límite de 5 intentos por minuto por correo + IP en `POST /api/v1/auth/login`.

## Consecuencias
Un token robado del disco no sirve sin la cuenta de Windows del usuario; un token válido caduca
o se revoca desde el servidor. Cada inicio de sesión de la consola queda en `audit_logs`.
