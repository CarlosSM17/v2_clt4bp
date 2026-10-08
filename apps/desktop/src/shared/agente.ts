// Tipos y reglas del asistente de diseño (Etapa 4), compartidos por main y la interfaz.
import type { ClaseTareas, Objetivo } from './contracts'
import type { Contenido, ElementoLocal, GrupoCurso, TipoElemento } from './diseno'

export type ClavePlantilla =
  | 'objetivos'
  | 'resumen_grupo'
  | 'preseleccion'
  | 'diferenciacion'
  | 'clase_tareas'
  | 'tareas_complementarias'
  | 'ficha_tema'
  | 'info_soporte'
  | 'ejemplo_resuelto_tema'
  | 'mapa_glosario'
  | 'info_procedimental'
  | 'guion_protocolo'
  | 'plan_implementacion'
  | 'items_evaluacion'
  | 'informe_revision'

export interface Validacion {
  nombre: string
  ok: boolean
  bloqueante: boolean
  detalle: string
  elemento_uid: string | null
}

export interface ElementoPropuesto {
  tipo: TipoElemento
  contenido: Contenido
}

/** Lo que devuelve el agente (ResultadoGeneracion en services/agent/app/solicitud.py). */
export interface ResultadoGeneracion {
  plantilla: ClavePlantilla
  elementos: ElementoPropuesto[]
  notas: Record<string, unknown>
  advertencias: string[]
  validaciones: Validacion[]
  intentos: number
  uso: {
    entrada: number
    salida: number
    cache_escritura: number
    cache_lectura: number
    costo_usd: number
  }
  modelo: string
  version_prompt: string
  duracion_ms: number
}

export interface TrabajoAgente {
  id: number
  plantilla: ClavePlantilla
  paso: number
  estado: 'en_cola' | 'procesando' | 'listo' | 'error'
  parametros: {
    alcance: Record<string, unknown>
    indicaciones?: string | null
    calidad?: 'normal' | 'alta' | null
  }
  resultado?: ResultadoGeneracion | null
  error: string | null
  created_at: string
  // En la lista: qué hizo el instructor con la propuesta (null: todavía no decide)
  decision?: 'aceptado' | 'parcial' | 'descartado' | null
  corrida?: {
    id: number
    modelo: string
    costo_usd: number
    duracion_ms: number
    intentos: number
    decision: string | null
  } | null
  // Material del curso que consultó el agente (RAG local)
  material?: { documento: string; pagina: number | null }[]
}

export interface AccionAgente {
  plantilla: ClavePlantilla
  etiqueta: string
  alcance: Record<string, unknown>
  // «Completar el tema»: en vez de una propuesta, se piden todas estas, una por pieza
  piezas?: AccionAgente[]
}

/**
 * «Generar tema completo» (mapa de ruta CLT4BP, ADR 0007): primero la clase y sus tareas; al guardarla, estas piezas
 * llegan una por una como propuestas, en este orden.
 */
export const PIEZAS_DEL_TEMA: ClavePlantilla[] = [
  'ficha_tema',
  'info_soporte',
  'ejemplo_resuelto_tema',
  'mapa_glosario',
  'info_procedimental',
  'guion_protocolo',
  'items_evaluacion'
]

/** Las piezas del tema para una clase ya guardada (la evaluación lleva además sus objetivos). */
export function piezasDelTema(clase: ClaseTareas): AccionAgente[] {
  return PIEZAS_DEL_TEMA.map((plantilla) => ({
    plantilla,
    etiqueta: NOMBRE_PLANTILLA[plantilla],
    alcance:
      plantilla === 'items_evaluacion'
        ? { clase_uid: clase.uid, objetivos: clase.objetivos }
        : { clase_uid: clase.uid }
  }))
}

export const NOMBRE_PLANTILLA: Record<ClavePlantilla, string> = {
  objetivos: 'Objetivos de desempeño',
  resumen_grupo: 'Resumen de los grupos',
  preseleccion: 'Preselección de efectos',
  diferenciacion: 'Diferenciación por grupo',
  clase_tareas: 'Clase de tareas',
  tareas_complementarias: 'Tareas T5 a T8',
  ficha_tema: 'Ficha de diseño del tema',
  info_soporte: 'Soporte: conceptos',
  ejemplo_resuelto_tema: 'Soporte: ejemplo resuelto',
  mapa_glosario: 'Soporte: mapa y glosario',
  info_procedimental: 'Información procedimental',
  guion_protocolo: 'Protocolo verbal',
  plan_implementacion: 'Plan de implementación',
  items_evaluacion: 'Evaluación del tema',
  informe_revision: 'Informe de revisión'
}

/**
 * Qué puede pedirle el instructor al agente según lo seleccionado en el árbol.
 * El alcance que se arma aquí lo valida Laravel (AlcancePlantilla) antes de gastar tokens.
 */
export function accionesPara(
  sel: ElementoLocal | null,
  elementos: ElementoLocal[],
  grupos: GrupoCurso[]
): AccionAgente[] {
  const clases = elementos.filter((e) => e.tipo === 'clase').map((e) => e.contenido as ClaseTareas)
  const codigos = elementos
    .filter((e) => e.tipo === 'objetivo')
    .map((e) => (e.contenido as Objetivo).codigo)
  const acciones: AccionAgente[] = [
    { plantilla: 'objetivos', etiqueta: 'Proponer objetivos de desempeño', alcance: {} }
  ]

  if (sel?.tipo === 'clase') {
    const c = sel.contenido as ClaseTareas
    const piezas = piezasDelTema(c)
    acciones.push(
      {
        plantilla: 'info_soporte',
        etiqueta: `Completar el tema de «${c.titulo}» (${piezas.length} piezas)`,
        alcance: { clase_uid: c.uid },
        piezas
      },
      ...piezas.map((p) => ({ ...p, etiqueta: `${p.etiqueta} para «${c.titulo}»` })),
      // Las tareas que siguen a las que ya tiene (solución libre, autoexplicación, imaginación, reto colaborativo)
      {
        plantilla: 'tareas_complementarias',
        etiqueta: `Más tareas para «${c.titulo}» (T5 a T8)`,
        alcance: { clase_uid: c.uid }
      },
      {
        plantilla: 'clase_tareas',
        etiqueta: 'Clase siguiente con los mismos objetivos',
        alcance: { objetivos: c.objetivos, orden: clases.length + 1 }
      }
    )
    if (grupos.length)
      acciones.push({
        plantilla: 'diferenciacion',
        etiqueta: 'Diferenciar esta clase por grupo',
        alcance: { clase_uid: c.uid }
      })
  } else if (sel?.tipo === 'tarea') {
    acciones.push(
      {
        plantilla: 'info_procedimental',
        etiqueta: 'Ayudas justo a tiempo para esta tarea',
        alcance: { tarea_uid: sel.uid }
      },
      {
        plantilla: 'guion_protocolo',
        etiqueta: 'Guion de protocolo verbal',
        alcance: { tarea_uid: sel.uid }
      }
    )
  } else if (codigos.length) {
    // Tema completo: la clase y sus tareas; al guardarlas, el resto del tema llega pieza por pieza (PIEZAS_DEL_TEMA)
    acciones.push(
      {
        plantilla: 'clase_tareas',
        etiqueta: 'Generar tema completo',
        alcance: { objetivos: codigos, orden: clases.length + 1, tema_completo: true }
      },
      {
        plantilla: 'clase_tareas',
        etiqueta: 'Solo la clase de tareas',
        alcance: { objetivos: codigos, orden: clases.length + 1 }
      }
    )
  }

  if (codigos.length)
    acciones.push({
      plantilla: 'items_evaluacion',
      etiqueta: 'Ítems de evaluación',
      alcance: { objetivos: codigos }
    })
    
  if (clases.length)
    acciones.push({
      plantilla: 'plan_implementacion',
      etiqueta: 'Plan de fechas por grupo',
      alcance: {}
    })
  acciones.push({
    plantilla: 'resumen_grupo',
    etiqueta: 'Resumen pedagógico de los grupos',
    alcance: {}
  })
  for (const g of grupos)
    acciones.push({
      plantilla: 'preseleccion',
      etiqueta: `Preselección de efectos: ${g.nombre}`,
      alcance: { grupo_clave: g.clave }
    })

  return acciones
}

/** Un ítem de la evaluación del tema, tal como lo propone el agente (ItemGenerado en services/agent). */
export interface ItemGenerado {
  tipo: 'opcion_multiple' | 'respuesta_corta' | 'prediccion_salida' | 'parsons' | 'programacion'
  nivel: 'recall' | 'comprension' | 'practica'
  objetivo: string
  forma: 'A' | 'B'
  enunciado_md: string
  opciones: string[]
  correcta: number
  aceptadas: string[]
  salida: string
  lineas: string[]
  codigo_inicial: string
  solucion: string
  casos_prueba: { entrada: string; salida_esperada: string; oculto: boolean }[]
}

/** El ítem en el formato del Banco de ítems (el mismo que arma la pestaña «Banco de ítems»). */
export function itemAlBanco(it: ItemGenerado, lenguaje: string): Record<string, unknown> {
  const base = {
    tipo: it.tipo,
    nivel: it.tipo === 'programacion' ? 'practica' : it.nivel,
    objetivo: it.objetivo || null
  }
  const letra = (i: number): string => String.fromCharCode(97 + i)
  switch (it.tipo) {
    case 'opcion_multiple':
      return {
        ...base,
        enunciado: {
          md: it.enunciado_md,
          opciones: it.opciones.map((texto, i) => ({ id: letra(i), texto }))
        },
        clave: { correcta: letra(it.correcta) }
      }
    case 'respuesta_corta':
      return { ...base, enunciado: { md: it.enunciado_md }, clave: { aceptadas: it.aceptadas } }
    case 'prediccion_salida': {
      // El programa a predecir va en el enunciado (en el ítem del agente viene aparte, en «codigo_inicial»)
      const codigo = it.codigo_inicial.trim()
      const cerca = '```'
      const programa =
        codigo && !it.enunciado_md.includes(codigo)
          ? `\n\n${cerca}${lenguaje}\n${codigo}\n${cerca}`
          : ''
      return {
        ...base,
        enunciado: { md: it.enunciado_md + programa },
        clave: { salida: it.salida }
      }
    }
    case 'parsons': {
      const lineas = it.lineas.map((texto, i) => ({ id: `l${i + 1}`, texto }))
      return {
        ...base,
        enunciado: { md: it.enunciado_md, lineas },
        clave: { orden: lineas.map((l) => l.id) }
      }
    }
    case 'programacion':
      return {
        ...base,
        enunciado: { md: it.enunciado_md, codigo_inicial: it.codigo_inicial || undefined },
        lenguaje,
        solucion: it.solucion,
        casos_prueba: it.casos_prueba
      }
  }
}

/** Elementos que el instructor acepta por defecto: los que no tienen problemas bloqueantes. */
export function aceptablesPorDefecto(r: ResultadoGeneracion): Set<string> {
  const conProblema = new Set(
    r.validaciones.filter((v) => !v.ok && v.bloqueante && v.elemento_uid).map((v) => v.elemento_uid)
  )
  const hayGeneral = r.validaciones.some((v) => !v.ok && v.bloqueante && !v.elemento_uid)
  return new Set(
    hayGeneral ? [] : r.elementos.map((e) => e.contenido.uid).filter((u) => !conProblema.has(u))
  )
}
