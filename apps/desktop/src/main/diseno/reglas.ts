// Decisiones de sincronización del lado de la consola. Sin Electron ni SQLite: se prueban con Vitest.

export type DecisionRemoto = 'insertar' | 'reemplazar' | 'borrar' | 'ignorar' | 'igualar' | 'conflicto'

interface LocalMinimo {
  version: number
  sucio: boolean
  eliminado: boolean
  contenido: unknown
}
interface RemotoMinimo {
  version: number
  eliminado: boolean
  contenido: unknown
}

/** Qué hacer con un elemento que llega del servidor, según lo que hay en esta computadora. */
export function decidirRemoto(local: LocalMinimo | null, remoto: RemotoMinimo): DecisionRemoto {
  if (!local) return remoto.eliminado ? 'ignorar' : 'insertar'
  if (!local.sucio) {
    if (remoto.eliminado) return 'borrar'
    return remoto.version >= local.version ? 'reemplazar' : 'ignorar'
  }
  // Hay cambios locales sin subir
  if (remoto.version <= local.version) return 'ignorar' // es algo que ya conocíamos
  if (local.eliminado && remoto.eliminado) return 'borrar'
  if (!local.eliminado && !remoto.eliminado && iguales(local.contenido, remoto.contenido)) return 'igualar'
  return 'conflicto'
}

/** Igualdad estructural sin importar el orden de las llaves (jsonb lo cambia). */
export function iguales(a: unknown, b: unknown): boolean {
  return JSON.stringify(canonico(a)) === JSON.stringify(canonico(b))
}

function canonico(v: unknown): unknown {
  if (Array.isArray(v)) return v.map(canonico)
  if (v && typeof v === 'object') {
    return Object.fromEntries(
      Object.keys(v as object)
        .sort()
        .map((k) => [k, canonico((v as Record<string, unknown>)[k])])
    )
  }
  return v
}
