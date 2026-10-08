import { app, safeStorage } from 'electron'
import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import type { Usuario } from '../shared/tipos'

// El token vive solo en el proceso main, cifrado con la API del sistema operativo
// (DPAPI en Windows). La interfaz nunca lo ve.

interface SesionGuardada {
  token: string
  expiraEn: string | null
  usuario: Usuario
}

const archivo = (): string => join(app.getPath('userData'), 'sesion.bin')
let actual: SesionGuardada | null = null

export function guardarSesion(sesion: SesionGuardada): void {
  actual = sesion
  if (!safeStorage.isEncryptionAvailable()) return // sin cifrado disponible, solo en memoria
  writeFileSync(archivo(), safeStorage.encryptString(JSON.stringify(sesion)))
}

export function cargarSesion(): SesionGuardada | null {
  if (actual) return actual
  if (!existsSync(archivo()) || !safeStorage.isEncryptionAvailable()) return null
  try {
    const sesion = JSON.parse(safeStorage.decryptString(readFileSync(archivo()))) as SesionGuardada
    if (sesion.expiraEn && new Date(sesion.expiraEn) < new Date()) {
      borrarSesion()
      return null
    }
    actual = sesion
    return sesion
  } catch {
    borrarSesion()
    return null
  }
}

export function borrarSesion(): void {
  actual = null
  rmSync(archivo(), { force: true })
}

export function token(): string | null {
  return cargarSesion()?.token ?? null
}
