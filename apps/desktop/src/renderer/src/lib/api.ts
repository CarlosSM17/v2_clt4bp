import type { Metodo, Respuesta } from '@shared/tipos'

/** Llama a la API a través del proceso main. Lanza un Error con mensaje legible si falla. */
export async function api<T>(metodo: Metodo, ruta: string, cuerpo?: unknown): Promise<T> {
  // El IPC no puede clonar los Proxy reactivos de Vue; el main lo manda como JSON de todos modos
  const plano = cuerpo === undefined ? undefined : JSON.parse(JSON.stringify(cuerpo))
  const r: Respuesta<T> = await window.consola.api<T>(metodo, ruta, plano)
  if (r.ok) return r.data
  const detalle = r.errors ? Object.values(r.errors).flat().join(' ') : ''
  throw new ErrorApi(detalle || r.message, r.status)
}

export class ErrorApi extends Error {
  constructor(
    message: string,
    public status: number
  ) {
    super(message)
  }
}
