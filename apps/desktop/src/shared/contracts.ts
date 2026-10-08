/* GENERADO por packages/contracts — no editar a mano. Ejecuta: npm run gen */

export type Componente = 'tarea' | 'soporte' | 'procedimental' | 'practica_parcial' | 'evaluacion'
/**
 * Identificador de uno de los 17 efectos de la TCC usados por CLT4BP.
 */
export type EfectoId =
  | 'ejemplo_resuelto'
  | 'problema_completar'
  | 'modalidad'
  | 'variabilidad'
  | 'elementos_aislados'
  | 'redundancia'
  | 'atencion_dividida'
  | 'informacion_transitoria'
  | 'interactividad_elementos'
  | 'movimiento_humano'
  | 'auto_gestion'
  | 'auto_explicacion'
  | 'imaginacion'
  | 'solucion_libre'
  | 'memoria_colectiva'
  | 'desvanecimiento_guia'
  | 'reversion_experiencia'
export type Interactividad = 'baja' | 'media' | 'alta'
export type NivelApoyo = 'ejemplo_resuelto' | 'por_completar' | 'convencional' | 'solucion_libre'
export type Lenguaje = 'c' | 'cpp' | 'python'

/**
 * Punto de entrada: reúne todos los esquemas para generar los tipos en un solo archivo.
 */
export interface Contratos {
  objetivo?: Objetivo
  clase?: ClaseTareas
  tarea?: Tarea
  soporte?: InfoSoporte
  procedimental?: InfoProcedimental
  practica_parcial?: PracticaParcial
  variante?: Variante
  medio?: Medio
  diseno?: DisenoCurso
}
/**
 * Objetivo de aprendizaje (paso 1 de CLT4BP; paso 3 de los Diez Pasos).
 */
export interface Objetivo {
  uid: string
  codigo: string
  orden: number
  /**
   * Verbo observable + condición + criterio.
   */
  descripcion: string
  tipo: 'conocimiento' | 'habilidad' | 'actitud'
  /**
   * Métodos con que se comprobará el objetivo.
   *
   * @minItems 1
   */
  evaluacion: ('recall' | 'comprension' | 'practica' | 'cis_imms' | 'mslq' | 'cs')[]
}
export interface ClaseTareas {
  uid: string
  titulo: string
  /**
   * Qué hace a esta clase más compleja que la anterior.
   */
  descripcion_complejidad: string
  /**
   * Ficha de diseño instruccional del tema, para el instructor: objetivos por ruta, métodos de evaluación, efectos por componente, matriz de diferenciación y plan de implementación. El aula no la muestra.
   */
  ficha_md?: string
  orden: number
  /**
   * Códigos de objetivo, p. ej. OB-3.
   */
  objetivos: string[]
  diseno: Diseno
}
/**
 * Metadatos de diseño que acompañan a todo elemento del curso.
 */
export interface Diseno {
  paso_clt4bp: number
  componente: Componente
  efectos: EfectoAplicado[]
  interactividad: Interactividad
  tiempo_estimado_min: number
  justificacion: string
  advertencias: string[]
}
export interface EfectoAplicado {
  id: EfectoId
  /**
   * Una frase: cómo se aplicó el efecto.
   */
  como: string
}
export interface Tarea {
  uid: string
  clase_uid: string
  orden: number
  titulo: string
  nivel_apoyo: NivelApoyo
  /**
   * Enunciado en Markdown, con el escenario auténtico.
   */
  enunciado_md: string
  arcs: Arcs
  lenguaje: Lenguaje
  /**
   * Vacío en convencional; solución comentada en ejemplo resuelto; con huecos '/* HUECO n: ... * /' en por completar.
   */
  codigo_inicial: string
  /**
   * Solución de referencia completa; debe pasar todos los casos de prueba.
   */
  solucion: string
  casos_prueba: CasoPrueba[]
  pide_autoexplicacion: boolean
  colaborativa: boolean
  /**
   * Grupos (rutas) que trabajan esta tarea; vacío = todos. El estudiante solo ve las tareas de su ruta.
   */
  rutas?: string[]
  diseno: Diseno
}
export interface Arcs {
  atencion: string
  relevancia: string
  confianza: string
  satisfaccion: string
}
export interface CasoPrueba {
  /**
   * Texto que recibe el programa por stdin.
   */
  entrada: string
  salida_esperada: string
  oculto: boolean
}
export interface InfoSoporte {
  uid: string
  clase_uid: string
  tipo: 'modelo_mental' | 'sap' | 'explicacion' | 'mapa_conceptual' | 'guion_video'
  titulo: string
  /**
   * Markdown; diagramas en bloques ```mermaid.
   */
  cuerpo_md: string
  diseno: Diseno
}
export interface InfoProcedimental {
  uid: string
  /**
   * Tarea a la que ayuda (ayuda justo a tiempo de una sola tarea). Lleva tarea_uid o clase_uid, no ambos.
   */
  tarea_uid?: string
  /**
   * Clase (tema) a la que pertenece: tarjeta de sintaxis, guía de rutina, errores frecuentes, ejemplo isomórfico o protocolo verbal del tema.
   */
  clase_uid?: string
  tipo: 'ficha_sintaxis' | 'guia_preguntas' | 'errores_frecuentes' | 'ejemplo_isomorfico' | 'protocolo_verbal'
  titulo: string
  cuerpo_md: string
  diseno: Diseno
}
export interface PracticaParcial {
  uid: string
  /**
   * Subhabilidad que se automatiza, p. ej. 'declarar y recorrer arreglos'.
   */
  habilidad: string
  lenguaje: Lenguaje
  ejercicios: {
    enunciado_md: string
    solucion: string
    casos_prueba: CasoPrueba[]
  }[]
  diseno: Diseno
}
/**
 * Versión de un elemento para un grupo diferenciado (paso 4). Solo guarda lo que cambia; el resto se hereda del elemento base.
 */
export interface Variante {
  uid: string
  elemento_uid: string
  grupo_clave: string
  /**
   * Campos del elemento base que cambian para este grupo. No puede cambiar identificadores, la posición, la solución ni los casos de prueba: todos los grupos se evalúan con el mismo criterio.
   */
  cambios: {
    [k: string]: unknown
  }
  diferenciacion: {
    /**
     * @minItems 1
     */
    dimensiones: ('contenido' | 'proceso' | 'producto')[]
    /**
     * Por qué este grupo necesita la variante (Tomlinson).
     */
    razon: string
  }
}
/**
 * Archivo multimedia del curso (imagen, audio, video o grabación de protocolo verbal). En Markdown se cita como media:<uid>.
 */
export interface Medio {
  uid: string
  tipo: 'imagen' | 'audio' | 'video' | 'protocolo_verbal'
  titulo: string
  duracion_s: number | null
  segmentos: {
    inicio_s: number
    etiqueta: string
  }[]
  transcripcion: string | null
}
/**
 * Fotografía completa del diseño de un curso: lo que revisa el verificador y lo que empaqueta una publicación.
 */
export interface DisenoCurso {
  curso: {
    id: number
    titulo: string
    lenguaje: Lenguaje
    grupos: {
      clave: string
      nombre: string
      nivel: string | null
    }[]
  }
  objetivos: Objetivo[]
  clases: ClaseTareas[]
  tareas: Tarea[]
  soporte: InfoSoporte[]
  procedimental: InfoProcedimental[]
  practica_parcial: PracticaParcial[]
  variantes: Variante[]
  medios: Medio[]
}
