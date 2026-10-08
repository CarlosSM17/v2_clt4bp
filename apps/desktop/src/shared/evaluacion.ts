// Tipos y utilidades de la evaluación final, los dashboards y la revisión de resultados (Etapa 6).

export interface Paso {
  tipo: 'cuestionario' | 'prueba'
  id: number
  titulo: string
  estado: string
}

export interface EstadoEvaluacionFinal {
  ventana: { abre_at: string; cierra_at: string | null } | null
  abierta: boolean
  estudiantes: { enrollment_id: number; nombre: string; seudonimo: string; estado: string; pasos: Paso[] }[]
}

// ---------- Dashboard del grupo ----------

export interface Celda {
  estado: 'en_progreso' | 'completada'
  fraccion: number | null
  intentos: number
  esfuerzo: number | null
}

export interface TareaTablero {
  uid: string
  titulo: string
  clase_uid: string
  clase_orden: number | null
  orden: number
  nivel_apoyo: string
}
export interface Alerta {
  tipo: 'sin_actividad' | 'esfuerzo_sin_desempeno' | 'diagnostico_incompleto' | 'ansiedad_alta'
  texto: string
}
export interface EstudianteTablero {
  enrollment_id: number
  nombre: string
  seudonimo: string
  estado: string
  grupo: { clave: string; nombre: string } | null
  nivel: string | null
  cp: number | null
  ultima_actividad: string | null
  celdas: Record<string, Celda>
  alertas: Alerta[]
}
export interface CargaClase {
  clase_uid: string
  orden: number | null
  titulo: string
  n: number
  intrinseca: number
  extrinseca: number
  germana: number
  extrinseca_alta: boolean
}
export interface ResumenInstrumento {
  n: number
  subescalas: Record<string, number>
}
export interface Tablero {
  tareas: TareaTablero[]
  estudiantes: EstudianteTablero[]
  diagnostico: { faltan: number; niveles: Record<string, number>; grupos: Record<string, number> }
  esfuerzo_por_tarea: { tarea_uid: string; abrieron: number; n_esfuerzo: number; esfuerzo: number | null; desempeno: number | null }[]
  ayudas: { tarea_uid: string; consultas: number; estudiantes: number; abrieron: number }[]
  carga_por_clase: CargaClase[]
  imms: ResumenInstrumento | null
}

export interface FichaEstudiante {
  enrollment_id: number
  nombre: string
  seudonimo: string
  estado: string
  lenguaje: 'c' | 'cpp' | 'python'
  grupo: { clave: string; nombre: string } | null
  perfil: {
    cp_recall: number
    cp_comprension: number
    cp_teorico: number
    cp_practico: number
    cp_global: number
    nivel: string
    mslq: Record<string, number>
    indices: Record<string, number>
    banderas: string[]
  } | null
  puntajes: { pre: Record<string, number | null>; post: Record<string, number | null> } | null
  instrumentos: { instrumento: string; momento: string; clase_uid: string | null; subescalas: Record<string, number>; completado_at: string }[]
  tareas: {
    tarea_uid: string
    titulo: string
    estado: string
    intentos: number
    mejor_fraccion: number | null
    esfuerzo: number | null
    autoexplicacion: string | null
    completado_at: string | null
  }[]
  envios: { id: number; tarea_uid: string; numero: number; estado: string; fraccion: number | null; codigo: string; autoexplicacion: string | null; created_at: string }[]
  trayectoria: { verbo: string; objeto_tipo: string | null; objeto_uid: string | null; resultado: unknown; duracion_ms: number | null; ocurrido_at: string }[]
}

// ---------- Estadísticos (services/agent/app/estadisticas.py) ----------

export interface Descriptivos {
  n: number
  media: number | null
  de: number | null
  mediana: number | null
  minimo: number | null
  maximo: number | null
}
export interface PruebaEstadistica {
  metodo: string
  estadistico: number | null
  gl?: number | null
  p: number | null
}
export interface PrePost {
  n: number
  excluidos: number
  pre: Descriptivos
  post: Descriptivos
  diferencia: Descriptivos
  normalidad: PruebaEstadistica | null
  t_pareada: PruebaEstadistica | null
  ic95: [number, number] | null
  wilcoxon: PruebaEstadistica | null
  d_z: number | null
  g_hake: number | null
  g_individual: number | null
  nivel_g: 'bajo' | 'medio' | 'alto' | null
  prueba_sugerida: 't' | 'wilcoxon' | null
}
export interface DosGrupos {
  a: Descriptivos
  b: Descriptivos
  welch: PruebaEstadistica
  ic95: [number, number] | null
  d_cohen: number | null
  g_hedges: number | null
}
export interface Ancova {
  n: number
  gl_error: number
  grupo: PruebaEstadistica
  covariable: PruebaEstadistica
  eta2_parcial: number | null
  pendiente: number | null
  medias_ajustadas: Record<string, number>
  pendientes_homogeneas: PruebaEstadistica
}
export interface Correlacion {
  n: number
  pearson: PruebaEstadistica
  spearman: PruebaEstadistica
}
/** Si el servicio de estadísticos no respondió, Laravel devuelve { error } en lugar del resultado. */
export type ConError<T> = T | { error: string }

export interface LogroObjetivo {
  codigo: string
  descripcion: string | null
  items: number
  estudiantes: number
  media: number
  logran: number
  criterio: number
}
export interface RevisionGuardada {
  id: number
  numero: number
  decision: 'cerrar' | 'iterar'
  regresar_a: 'fase1' | 'fase2' | null
  notas: string
  agent_job_id: number | null
  created_at: string
  autor: { name: string } | null
}
export interface RevisionResultados {
  n: number
  con_pre_y_post: number
  logro_objetivos: LogroObjetivo[]
  pre_post: ConError<Record<string, PrePost>> | null
  por_grupo: ConError<Record<string, PrePost>> | null
  eficiencia_ganancia: ConError<Correlacion> | null
  imms: ResumenInstrumento | null
  carga_por_clase: CargaClase[]
  comparacion: {
    curso_control: { id: number; titulo: string }
    n: { experimental: number; control: number }
    error?: string
    post?: ConError<DosGrupos> | null
    ganancia?: ConError<DosGrupos> | null
    ancova?: ConError<Ancova> | null
  } | null
  revisiones: RevisionGuardada[]
}

/** Salida de la plantilla informe_revision del agente (notas.informe). */
export interface Informe {
  resumen: string
  objetivos: { codigo: string; logrado: boolean; evidencia: string }[]
  hallazgos: string[]
  recomendacion: 'cerrar' | 'iterar'
  regresar_a: 'ninguna' | 'fase1' | 'fase2'
  cambios: { paso: number; elemento_uid: string; sugerencia: string }[]
  limitaciones: string[]
}

// ---------- Utilidades de presentación ----------

export const MEDIDAS: Record<string, string> = {
  global: 'Conocimiento global',
  teorico: 'Prueba teórica',
  practico: 'Prueba práctica',
  recall: 'Recuerdo',
  comprension: 'Comprensión',
  mslq_motivacion: 'MSLQ – motivación',
  mslq_estrategias_cognitivas: 'MSLQ – estrategias cognitivas',
  mslq_autorregulacion: 'MSLQ – autorregulación'
}

export const ARCS: Record<string, string> = {
  atencion: 'Atención',
  relevancia: 'Relevancia',
  confianza: 'Confianza',
  satisfaccion: 'Satisfacción',
  total: 'Total'
}

export function sinError<T>(x: ConError<T> | null | undefined): T | null {
  if (x === null || x === undefined) return null
  return typeof x === 'object' && 'error' in (x as object) ? null : (x as T)
}

export function errorDe(x: unknown): string | null {
  return x !== null && typeof x === 'object' && 'error' in x ? String((x as { error: string }).error) : null
}

export function num(x: number | null | undefined, dec = 2): string {
  return x === null || x === undefined ? '--' : x.toFixed(dec)
}

/** p con tres decimales, como en APA: p < .001 cuando es menor. */
export function formatoP(p: number | null | undefined): string {
  if (p === null || p === undefined) return '--'
  return p < 0.001 ? '< .001' : p.toFixed(3).replace(/^0/, '')
}

/** Magnitud de un tamaño del efecto (Cohen, 1988): 0.2 pequeño, 0.5 mediano, 0.8 grande. */
export function magnitud(d: number | null | undefined): string {
  if (d === null || d === undefined) return '--'
  const a = Math.abs(d)
  return a < 0.2 ? 'trivial' : a < 0.5 ? 'pequeño' : a < 0.8 ? 'mediano' : 'grande'
}

/** Color de una celda del mapa de calor según el mejor resultado de la tarea. */
export function colorCelda(c: Celda | undefined, nivelApoyo: string): string {
  if (!c) return 'bg-slate-100'
  if (nivelApoyo === 'ejemplo_resuelto') return c.estado === 'completada' ? 'bg-sky-300' : 'bg-sky-100'
  if (c.fraccion === null) return 'bg-slate-200' // la abrió pero no ha enviado
  if (c.fraccion >= 1) return 'bg-emerald-500'
  if (c.fraccion >= 0.5) return 'bg-amber-300'
  return 'bg-rose-400'
}
