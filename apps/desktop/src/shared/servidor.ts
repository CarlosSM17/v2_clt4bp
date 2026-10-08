/**
 * Dirección de la API de la plataforma, escrita como la escriba quien instala la consola: con o sin `/api/v1`, con o
 * sin barra final («https://tu-app.up.railway.app» basta). Devuelve null si no es una URL http(s).
 */
export function normalizarApi(texto: string | undefined | null): string | null {
  const limpio = (texto ?? '').trim().replace(/\/+$/, '')
  if (!/^https?:\/\/[^\s/]+/i.test(limpio)) return null
  return /\/api\/v1$/.test(limpio) ? limpio : `${limpio}/api/v1`
}
