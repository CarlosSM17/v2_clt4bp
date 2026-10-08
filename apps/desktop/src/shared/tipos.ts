export interface Usuario {
  id: number
  name: string
  email: string
  institucion: string | null
  roles: string[]
  dos_pasos: boolean
  invitacion_aceptada: boolean
  suspendido: boolean
}

export interface Curso {
  id: number
  titulo: string
  descripcion: string | null
  lenguaje: 'c' | 'cpp' | 'python'
  nivel_educativo: 'secundaria' | 'preparatoria' | 'universidad'
  codigo_inscripcion: string
  estado: 'diseno' | 'activo' | 'concluido' | 'archivado'
  inicia_el: string | null
  termina_el: string | null
  pendientes?: number
  inscritos?: number
  // Etapa 6: última revisión de resultados que decidió iterar (solo en GET /courses/{id})
  iteracion?: { numero: number; regresar_a: 'fase1' | 'fase2'; notas: string; created_at: string } | null
}

export interface Inscripcion {
  id: number
  estado: string
  seudonimo: string
  estudiante: { id: number; name: string; email: string }
  solicitado_at: string | null
  inscrito_at: string | null
}

/** Respuesta uniforme de toda llamada a la API hecha desde el proceso main. */
export type Respuesta<T> =
  | { ok: true; status: number; data: T }
  | { ok: false; status: number; message: string; errors?: Record<string, string[]> }

export type ResultadoLogin =
  | { estado: 'ok'; usuario: Usuario }
  | { estado: 'requiere_codigo'; message: string }
  | { estado: 'error'; message: string; errors?: Record<string, string[]> }

export type Metodo = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

// ---- Diagnóstico (Etapa 2) ----

export type TipoItem =
  'opcion_multiple' | 'respuesta_corta' | 'prediccion_salida' | 'parsons' | 'programacion'

export interface CasoPrueba {
  entrada: string
  salida_esperada: string
  oculto: boolean
}

export interface Item {
  id: number
  tipo: TipoItem
  nivel: 'recall' | 'comprension' | 'practica'
  objetivo: string | null
  enunciado: {
    md: string
    opciones?: { id: string; texto: string }[]
    lineas?: { id: string; texto: string }[]
    codigo_inicial?: string
  }
  clave: Record<string, unknown> | null
  lenguaje: 'c' | 'cpp' | 'python' | null
  casos_prueba: CasoPrueba[] | null
  solucion: string | null
  estado: 'borrador' | 'aprobado'
  verificado_at: string | null
}

export interface Prueba {
  id: number
  nombre: string
  momento: 'pre' | 'post'
  tipo: 'teorica' | 'practica'
  forma: 'A' | 'B'
  tiempo_limite_min: number | null
  items_count?: number
}

export interface Perfil {
  cp_teorico: number
  cp_practico: number
  cp_global: number
  nivel: 'basico' | 'intermedio' | 'avanzado'
  mslq: Record<string, number>
  indices: Record<string, number>
  banderas: string[]
}

export interface FilaDiagnostico {
  enrollment_id: number
  nombre: string
  seudonimo: string
  estado: string
  pasos: { tipo: string; id: number; titulo: string; estado: string }[]
  perfil: Perfil | null
  grupo: { clave: string; nombre: string } | null
}

export interface Analisis {
  id: number
  recomendacion: 'homogeneo' | 'heterogeneo'
  decision: 'homogeneo' | 'heterogeneo' | null
  justificacion: string | null
  resultado: {
    n: number
    media: number
    desviacion: number
    cv: number | null
    nivel_modal: string | null
    proporcion_modal: number
    confiable: boolean
    histograma: Record<string, number>
    niveles: Record<string, number>
  }
}

export interface GrupoPropuesto {
  clave: string
  nombre: string
  nivel: string | null
  miembros: number[] // ids de inscripción
}

/** Material del curso que consulta el agente (RAG local): apuntes y bibliografía. */
export interface DocumentoCurso {
  id: number
  titulo: string
  nombre_original: string
  mime: string
  bytes: number
  estado: 'procesando' | 'listo' | 'error'
  error: string | null
  fragmentos: number
  created_at: string
}
