import { API_URL } from './config'
import { borrarSesion, token } from './sesion'
import type { Metodo, Respuesta } from '../shared/tipos'

/** Hace una petición a la API central con el token de la sesión. */
export async function pedir<T>(
  metodo: Metodo,
  ruta: string,
  cuerpo?: unknown
): Promise<Respuesta<T>> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const t = token()
  if (t) headers.Authorization = `Bearer ${t}`
  if (cuerpo !== undefined) headers['Content-Type'] = 'application/json'

  let res: Response
  try {
    res = await fetch(`${API_URL}${ruta}`, {
      method: metodo,
      headers,
      body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
      signal: AbortSignal.timeout(30_000)
    })
  } catch {
    return { ok: false, status: 0, message: 'No hay conexión con el servidor.' }
  }

  const json = (await res.json().catch(() => ({}))) as Record<string, unknown>

  if (res.status === 401) borrarSesion() // token vencido o revocado

  if (!res.ok) {
    return {
      ok: false,
      status: res.status,
      message: (json.message as string) ?? `Error ${res.status}`,
      errors: json.errors as Record<string, string[]> | undefined
    }
  }
  // Los API Resources de Laravel envuelven los datos en "data"
  const data = ('data' in json ? json.data : json) as T
  return { ok: true, status: res.status, data }
}
