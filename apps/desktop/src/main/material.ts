import { BrowserWindow, dialog } from 'electron'
import { openAsBlob } from 'node:fs'
import { basename, extname } from 'node:path'
import { API_URL } from './config'
import { token } from './sesion'
import type { DocumentoCurso, Respuesta } from '../shared/tipos'

const TIPOS: Record<string, string> = {
  '.pdf': 'application/pdf',
  '.md': 'text/markdown',
  '.txt': 'text/plain'
}

/**
 * Material del curso para el agente (RAG). El archivo lo elige el instructor en el diálogo del sistema y lo sube
 * el proceso main: la interfaz nunca maneja rutas del disco ni el token.
 */
export async function subirMaterial(
  ventana: BrowserWindow | null,
  cursoId: number,
  titulo: string
): Promise<Respuesta<DocumentoCurso> | null> {
  const opciones = {
    title: 'Material del curso',
    properties: ['openFile' as const],
    filters: [{ name: 'PDF, Markdown o texto', extensions: ['pdf', 'md', 'txt'] }]
  }
  const eleccion = ventana
    ? await dialog.showOpenDialog(ventana, opciones)
    : await dialog.showOpenDialog(opciones)
  if (eleccion.canceled || !eleccion.filePaths[0]) return null

  const ruta = eleccion.filePaths[0]
  const mime = TIPOS[extname(ruta).toLowerCase()]
  if (!mime)
    return { ok: false, status: 422, message: 'Solo se admiten archivos PDF, Markdown o texto.' }

  const formulario = new FormData()
  formulario.append('archivo', await openAsBlob(ruta, { type: mime }), basename(ruta))
  if (titulo.trim()) formulario.append('titulo', titulo.trim().slice(0, 200))

  try {
    const res = await fetch(`${API_URL}/courses/${cursoId}/documents`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token() ?? ''}` },
      body: formulario,
      signal: AbortSignal.timeout(5 * 60_000)
    })
    const json = (await res.json().catch(() => ({}))) as Record<string, unknown>
    if (!res.ok) {
      return {
        ok: false,
        status: res.status,
        message: (json.message as string) ?? `Error ${res.status}`,
        errors: json.errors as Record<string, string[]> | undefined
      }
    }
    return { ok: true, status: res.status, data: json.data as DocumentoCurso }
  } catch {
    return { ok: false, status: 0, message: 'No hay conexión con el servidor.' }
  }
}
