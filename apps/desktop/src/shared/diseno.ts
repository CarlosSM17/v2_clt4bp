// Modelo del diseño 4C/ID que comparten el proceso main (SQLite, sincronización) y la interfaz.
import type {
  ClaseTareas,
  InfoProcedimental,
  InfoSoporte,
  Medio,
  Objetivo,
  PracticaParcial,
  Tarea,
  Variante
} from './contracts'

export type TipoElemento =
  | 'objetivo'
  | 'clase'
  | 'tarea'
  | 'soporte'
  | 'procedimental'
  | 'practica_parcial'
  | 'variante'
  | 'medio'

export interface ContenidoPorTipo {
  objetivo: Objetivo
  clase: ClaseTareas
  tarea: Tarea
  soporte: InfoSoporte
  procedimental: InfoProcedimental
  practica_parcial: PracticaParcial
  variante: Variante
  medio: Medio
}
export type Contenido = ContenidoPorTipo[TipoElemento]

/** Un elemento tal como lo guarda la consola. */
export interface ElementoLocal {
  uid: string
  tipo: TipoElemento
  padre_uid: string | null
  orden: number
  contenido: Contenido
  estado: 'propuesta' | 'borrador' | 'aprobado' | 'publicado' | 'archivado'
  version: number // versión del servidor sobre la que se trabaja (0 = nunca subido)
  sucio: boolean // hay cambios locales sin subir
  errores: Record<string, string[]> | null // no cumple el esquema: no se sube hasta corregirlo
  error_servidor: Record<string, string[]> | null
}

/** Lo que devuelve el servidor por cada elemento al sincronizar. */
export interface ElementoRemoto {
  uid: string
  tipo: TipoElemento
  padre_uid: string | null
  orden: number
  contenido: Contenido
  estado: ElementoLocal['estado']
  version: number
  eliminado: boolean
  seq: number
}

export interface GrupoCurso {
  clave: string
  nombre: string
  nivel: string | null
}

export interface Conflicto {
  uid: string
  tipo: TipoElemento
  local: Contenido | null // null = lo eliminaste en esta computadora
  servidor: ElementoRemoto
}

export interface ResumenSync {
  sinConexion: boolean
  subidos: number
  bajados: number
  conflictos: number
  invalidos: number
  pendientes: number
  ultima: string | null
}

const CAMPO_PADRE: Partial<Record<TipoElemento, string>> = {
  tarea: 'clase_uid',
  soporte: 'clase_uid',
  procedimental: 'tarea_uid',
  variante: 'elemento_uid'
}

export function padreDe(tipo: TipoElemento, contenido: Contenido): string | null {
  const datos = contenido as unknown as Record<string, unknown>
  // Una ayuda es de una tarea o, si es del tema (tarjeta de sintaxis, errores frecuentes…), de su clase
  if (tipo === 'procedimental' && !datos.tarea_uid) return (datos.clase_uid as string) ?? null
  const campo = CAMPO_PADRE[tipo]
  return campo ? ((datos[campo] as string) ?? null) : null
}

export function ordenDe(contenido: Contenido): number {
  return Number((contenido as unknown as Record<string, unknown>).orden ?? 1)
}

/**
 * Campos que una variante nunca cambia (mismo criterio que variante.schema.json): identificadores,
 * posición, y la solución y los casos, para que todos los grupos se evalúen con el mismo criterio.
 */
export const CAMPOS_FIJOS = ['uid', 'clase_uid', 'tarea_uid', 'orden', 'solucion', 'casos_prueba'] as const

/**
 * Contenido efectivo para un grupo: el base con los cambios de la variante encima.
 * `diseno` se mezcla un nivel (una variante puede cambiar solo los efectos, por ejemplo).
 * La misma regla existe en PHP (publicación) y en Python (verificador).
 */
export function aplicarVariante<T extends object>(base: T, cambios: Record<string, unknown> | undefined): T {
  if (!cambios) return base
  const resultado: Record<string, unknown> = { ...(base as Record<string, unknown>) }
  for (const [campo, valor] of Object.entries(cambios)) {
    if ((CAMPOS_FIJOS as readonly string[]).includes(campo)) continue
    const actual = resultado[campo]
    resultado[campo] =
      campo === 'diseno' && esObjeto(actual) && esObjeto(valor) ? { ...actual, ...valor } : valor
  }
  return resultado as T
}

/** Lo contrario: qué campos de `efectivo` difieren del base (para guardar solo eso en la variante). */
export function diferencias(base: object, efectivo: object): Record<string, unknown> {
  const b = base as Record<string, unknown>
  const cambios: Record<string, unknown> = {}
  for (const [campo, valor] of Object.entries(efectivo as Record<string, unknown>)) {
    if ((CAMPOS_FIJOS as readonly string[]).includes(campo)) continue
    if (JSON.stringify(valor) !== JSON.stringify(b[campo])) cambios[campo] = valor
  }
  return cambios
}

function esObjeto(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

export function uidNuevo(prefijo: string): string {
  return `${prefijo}-${crypto.randomUUID().slice(0, 8)}`
}

/** Resultado del verificador CLT4BP (servicio del agente, vía Laravel). */
export interface Hallazgo {
  regla: string
  nivel: 'error' | 'advertencia' | 'info'
  elemento_uid: string
  mensaje: string
  grupo: string | null
}

export interface InformeVerificacion {
  hallazgos: Hallazgo[]
  errores: number
  advertencias: number
  semaforo: Record<string, 'rojo' | 'amarillo' | 'verde'>
}
