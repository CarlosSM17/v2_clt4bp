import { BrowserWindow, ipcMain } from 'electron'
import { hostname } from 'node:os'
import { pedir } from './api'
import { API_URL } from './config'
import { subirMaterial } from './material'
import { borrarSesion, cargarSesion, guardarSesion } from './sesion'
import type { Metodo, ResultadoLogin, Usuario } from '../shared/tipos'

const METODOS: Metodo[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']

export function registrarIpc(): void {
  ipcMain.handle('sesion:actual', () => cargarSesion()?.usuario ?? null)

  ipcMain.handle(
    'sesion:login',
    async (
      _e,
      datos: { email: string; password: string; code?: string }
    ): Promise<ResultadoLogin> => {
      try {
        const res = await fetch(`${API_URL}/auth/login`, {
          method: 'POST',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify({ ...datos, device_name: `Consola en ${hostname()}` }),
          signal: AbortSignal.timeout(30_000)
        })
        const json = (await res.json().catch(() => ({}))) as Record<string, unknown>

        if (res.ok) {
          const usuario = json.user as Usuario
          guardarSesion({
            token: json.token as string,
            expiraEn: (json.expires_at as string) ?? null,
            usuario
          })
          return { estado: 'ok', usuario }
        }
        if (res.status === 422 && json.requires_two_factor) {
          return { estado: 'requiere_codigo', message: json.message as string }
        }
        return {
          estado: 'error',
          message: (json.message as string) ?? `Error ${res.status}`,
          errors: json.errors as Record<string, string[]> | undefined
        }
      } catch {
        return { estado: 'error', message: 'No hay conexión con el servidor.' }
      }
    }
  )

  ipcMain.handle('sesion:logout', async () => {
    await pedir('POST', '/auth/logout')
    borrarSesion()
  })

  // Material del curso (RAG): diálogo del sistema y subida multipart desde el proceso main
  ipcMain.handle('material:subir', (e, cursoId: number, titulo: string) => {
    if (!Number.isInteger(cursoId) || cursoId <= 0) throw new Error('Curso inválido.')
    return subirMaterial(BrowserWindow.fromWebContents(e.sender), cursoId, String(titulo ?? ''))
  })

  // Puerta única para la API: la interfaz pide y el proceso main agrega el token.
  ipcMain.handle('api:pedir', (_e, metodo: Metodo, ruta: string, cuerpo?: unknown) => {
    if (!METODOS.includes(metodo) || !ruta.startsWith('/')) {
      return { ok: false, status: 400, message: 'Petición inválida.' }
    }
    return pedir(metodo, ruta, cuerpo)
  })
}
