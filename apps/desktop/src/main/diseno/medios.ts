import { app, shell } from 'electron'
import { mkdirSync, openAsBlob, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { API_URL } from '../config'
import { token } from '../sesion'
import type { Almacen } from './almacen'

const UID = /^[A-Za-z0-9_-]{1,64}$/

/** Guarda una grabación en %APPDATA%\clt4bp-consola\medios\<curso>\ y la deja en la cola de subida. */
export function guardarMedio(almacen: Almacen, cursoId: number, uid: string, datos: ArrayBuffer, mime: string): void {
  if (!UID.test(uid)) throw new Error('uid inválido.')
  if (!/^(video|audio)\/webm$/.test(mime)) throw new Error('Formato no permitido.')
  const carpeta = join(app.getPath('userData'), 'medios', String(cursoId))
  mkdirSync(carpeta, { recursive: true })
  const ruta = join(carpeta, `${uid}.webm`)
  writeFileSync(ruta, Buffer.from(datos))
  almacen.encolarSubida(cursoId, uid, ruta, mime)
}

/** Sube lo pendiente. Se detiene al primer error de red (se reintenta en la siguiente sincronización). */
export async function subirMedios(almacen: Almacen, cursoId: number): Promise<number> {
  let subidos = 0
  for (const s of almacen.subidasPendientes(cursoId)) {
    const formulario = new FormData()
    formulario.append('uid', s.uid)
    formulario.append('archivo', await openAsBlob(s.ruta, { type: s.mime }), `${s.uid}.webm`)
    try {
      const res = await fetch(`${API_URL}/courses/${cursoId}/media`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token() ?? ''}` },
        body: formulario,
        signal: AbortSignal.timeout(10 * 60_000) // un video largo tarda
      })
      if (!res.ok) break
      almacen.marcarSubida(cursoId, s.uid)
      subidos++
    } catch {
      break // sin conexión
    }
  }
  return subidos
}

/** Abre la grabación local con el reproductor del sistema. */
export async function abrirMedio(almacen: Almacen, cursoId: number, uid: string): Promise<string> {
  const ruta = almacen.rutaMedio(cursoId, uid)
  if (!ruta) return 'Esta grabación no está en esta computadora.'
  return shell.openPath(ruta) // '' si todo salió bien
}
