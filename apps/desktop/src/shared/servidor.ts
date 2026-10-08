/**
 * Dirección de la API de la plataforma, escrita como la escriba quien instala la consola: con o sin `/api/v1`, con o
 * sin barra final («https://tu-app.up.railway.app» basta). Devuelve null si no es una URL http(s).
 */
/**
 * Nombre de un archivo de datos locales (la base del diseño, la sesión) para la plataforma a la que apunta la consola.
 * Con la dirección compilada conserva su nombre de siempre; con otra (servidor.json, CLT4BP_API_URL) lleva el host:
 * así los cambios pendientes de un curso nunca suben a otra plataforma con el mismo id, ni su token viaja a otra.
 */
export function nombrePorServidor(nombre: string, api: string, compilada: string): string {
  if (api === compilada) return nombre
  const host = new URL(api).host.replace(/[^a-z0-9.-]/gi, '_')
  const punto = nombre.lastIndexOf('.')
  return punto > 0 ? `${nombre.slice(0, punto)}-${host}${nombre.slice(punto)}` : `${nombre}-${host}`
}

export function normalizarApi(texto: string | undefined | null): string | null {
  const limpio = (texto ?? '').trim().replace(/\/+$/, '')
  if (!/^https?:\/\/[^\s/]+/i.test(limpio)) return null
  return /\/api\/v1$/.test(limpio) ? limpio : `${limpio}/api/v1`
}
