import { BrowserWindow, dialog, ipcMain } from 'electron'
import { writeFile } from 'node:fs/promises'
import { API_URL } from './config'
import { token } from './sesion'

type Resultado = { ruta: string | null; error: string | null }

/**
 * Exportación de datos (Etapa 6): el proceso main descarga el archivo con el token de la sesión y lo guarda
 * donde elija el instructor. La interfaz nunca ve el token ni escribe en disco.
 */
export function registrarIpcExportacion(): void {
  ipcMain.handle('exportar:guardar', async (evento, cursoId: number, formato: 'csv' | 'xlsx'): Promise<Resultado> => {
    if (!Number.isInteger(cursoId) || !['csv', 'xlsx'].includes(formato)) return { ruta: null, error: 'Petición inválida.' }
    const extension = formato === 'xlsx' ? 'xlsx' : 'zip'
    const ventana = BrowserWindow.fromWebContents(evento.sender)
    const opciones = {
      title: 'Guardar exportación',
      defaultPath: `clt4bp-curso${cursoId}.${extension}`,
      filters: [{ name: formato === 'xlsx' ? 'Libro de Excel' : 'ZIP con archivos CSV', extensions: [extension] }]
    }
    const eleccion = ventana ? await dialog.showSaveDialog(ventana, opciones) : await dialog.showSaveDialog(opciones)
    if (eleccion.canceled || !eleccion.filePath) return { ruta: null, error: null }

    try {
      const res = await fetch(`${API_URL}/courses/${cursoId}/exportacion?formato=${formato}`, {
        headers: { Authorization: `Bearer ${token() ?? ''}`, Accept: 'application/json' },
        signal: AbortSignal.timeout(300_000) // una exportación grande puede tardar
      })
      if (!res.ok) {
        const json = (await res.json().catch(() => ({}))) as { message?: string }
        return { ruta: null, error: json.message ?? `Error ${res.status}` }
      }
      await writeFile(eleccion.filePath, Buffer.from(await res.arrayBuffer()))
      return { ruta: eleccion.filePath, error: null }
    } catch {
      return { ruta: null, error: 'No hay conexión con el servidor.' }
    }
  })
}
