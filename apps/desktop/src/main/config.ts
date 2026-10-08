import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { app } from 'electron'
import { nombrePorServidor, normalizarApi } from '../shared/servidor'

/** {"api_url": "https://tu-app.up.railway.app"} en la carpeta de datos de la consola, si existe. */
function desdeArchivo(): string | null {
  try {
    const datos = JSON.parse(
      readFileSync(join(app.getPath('userData'), 'servidor.json'), 'utf8')
    ) as { api_url?: unknown }
    return typeof datos.api_url === 'string' ? normalizarApi(datos.api_url) : null
  } catch {
    return null // sin archivo (lo normal) o ilegible
  }
}

// La que se fijó al compilar (MAIN_VITE_API_URL; electron-vite expone al proceso main las variables MAIN_VITE_)
const COMPILADA = normalizarApi(import.meta.env.MAIN_VITE_API_URL) ?? 'http://localhost:8000/api/v1'

/**
 * Dirección de la API de la plataforma, en este orden (ADR 0008): la variable CLT4BP_API_URL al abrir la consola, el
 * archivo servidor.json de su carpeta de datos y la que se fijó al compilar. Así un mismo instalador sirve con la
 * plataforma en Railway o en un servidor propio.
 */
export const API_URL: string =
  normalizarApi(process.env.CLT4BP_API_URL) ?? desdeArchivo() ?? COMPILADA

/** Ruta de un archivo de datos locales propio de esta plataforma (la base del diseño, la sesión). */
export const datosLocales = (nombre: string): string =>
  join(app.getPath('userData'), nombrePorServidor(nombre, API_URL, COMPILADA))
