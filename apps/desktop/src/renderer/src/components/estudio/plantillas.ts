// Contenido inicial de cada tipo de elemento. Cumple el esquema salvo los textos que el instructor debe escribir.
import type { ClaseTareas, Diseno, InfoProcedimental, InfoSoporte, Lenguaje, Objetivo, PracticaParcial, Tarea } from '@shared/contracts'
import { uidNuevo } from '@shared/diseno'

function diseno(paso: number, componente: Diseno['componente']): Diseno {
  return { paso_clt4bp: paso, componente, efectos: [], interactividad: 'media', tiempo_estimado_min: 10, justificacion: '', advertencias: [] }
}

export function nuevoObjetivo(orden: number): Objetivo {
  return { uid: uidNuevo('ob'), codigo: `OB-${orden}`, orden, descripcion: '', tipo: 'habilidad', evaluacion: ['practica'] }
}

export function nuevaClase(orden: number): ClaseTareas {
  return { uid: uidNuevo('tc'), titulo: `Clase de tareas ${orden}`, descripcion_complejidad: '', orden, objetivos: [], diseno: diseno(5, 'tarea') }
}

export function nuevaTarea(clase: string, orden: number, lenguaje: Lenguaje): Tarea {
  return {
    uid: uidNuevo(`${clase}-t`),
    clase_uid: clase,
    orden,
    titulo: `Tarea ${orden}`,
    // La primera tarea de una clase suele ser un ejemplo resuelto; después se desvanece la guía
    nivel_apoyo: orden === 1 ? 'ejemplo_resuelto' : orden === 2 ? 'por_completar' : 'convencional',
    enunciado_md: '',
    arcs: { atencion: '', relevancia: '', confianza: '', satisfaccion: '' },
    lenguaje,
    codigo_inicial: '',
    solucion: '',
    casos_prueba: [{ entrada: '', salida_esperada: '', oculto: false }],
    pide_autoexplicacion: orden === 1,
    colaborativa: false,
    diseno: diseno(5, 'tarea')
  }
}

export function nuevoSoporte(clase: string): InfoSoporte {
  return { uid: uidNuevo(`${clase}-s`), clase_uid: clase, tipo: 'explicacion', titulo: 'Antes de empezar', cuerpo_md: '', diseno: diseno(6, 'soporte') }
}

export function nuevoProcedimental(tarea: string): InfoProcedimental {
  return { uid: uidNuevo(`${tarea}-p`), tarea_uid: tarea, tipo: 'ficha_sintaxis', titulo: 'Ficha de sintaxis', cuerpo_md: '', diseno: diseno(7, 'procedimental') }
}

/** Información procedimental del tema: cuelga de la clase, no de una tarea (mapa de ruta, ADR 0007). */
export function nuevoProcedimentalTema(clase: string): InfoProcedimental {
  return { uid: uidNuevo(`${clase}-p`), clase_uid: clase, tipo: 'ficha_sintaxis', titulo: 'Tarjeta de sintaxis', cuerpo_md: '', diseno: diseno(7, 'procedimental') }
}

export function nuevaPractica(lenguaje: Lenguaje): PracticaParcial {
  return { uid: uidNuevo('pp'), habilidad: '', lenguaje, ejercicios: [], diseno: diseno(5, 'practica_parcial') }
}
