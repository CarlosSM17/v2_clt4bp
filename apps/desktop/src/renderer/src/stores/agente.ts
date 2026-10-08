import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { ClaseTareas } from '@shared/contracts'
import { piezasDelTema, type AccionAgente, type TrabajoAgente } from '@shared/agente'
import { api } from '../lib/api'
import { useDiseno } from './diseno'

interface Uso {
  periodo: string
  limite_usd: number
  usado_usd: number
}

/** Trabajos del agente del curso abierto. Los resultados viven en el servidor; aquí solo se consultan. */
export const useAgente = defineStore('agente', () => {
  const trabajos = ref<TrabajoAgente[]>([])
  const abierto = ref<TrabajoAgente | null>(null) // propuesta en revisión
  const uso = ref<Uso | null>(null)
  const error = ref('')
  const enviando = ref(false)
  let temporizador: number | undefined

  const estudio = useDiseno()

  async function cargar(): Promise<void> {
    try {
      trabajos.value = await api<TrabajoAgente[]>('GET', `/courses/${estudio.cursoId}/agent/jobs`)
      uso.value = await api<Uso>('GET', '/agent/usage')
      vigilar()
    } catch (e) {
      error.value = (e as Error).message
    }
  }

  /**
   * Pide una propuesta. La clave de idempotencia la genera el panel y se reutiliza si hay que
   * reintentar: así un reintento tras un corte de red no crea (ni cobra) un segundo trabajo.
   */
  async function solicitar(accion: AccionAgente, indicaciones: string, calidad: 'normal' | 'alta', clave: string): Promise<boolean> {
    enviando.value = true
    error.value = ''
    try {
      // El agente trabaja con lo que hay en el servidor: primero se sube lo local
      await estudio.sincronizar()
      if (estudio.sync?.sinConexion) throw new Error('Sin conexión: el asistente necesita el servidor.')
      const t = await api<TrabajoAgente>('POST', `/courses/${estudio.cursoId}/agent/jobs`, {
        plantilla: accion.plantilla, alcance: accion.alcance, indicaciones, calidad, clave_idempotencia: clave
      })
      if (!trabajos.value.some((x) => x.id === t.id)) trabajos.value.unshift(t)
      vigilar()
      return true
    } catch (e) {
      error.value = (e as Error).message
      return false
    } finally {
      enviando.value = false
    }
  }

  /** Consulta cada 3 s los trabajos que siguen en cola o en proceso. */
  function vigilar(): void {
    window.clearTimeout(temporizador)
    const pendientes = trabajos.value.filter((t) => t.estado === 'en_cola' || t.estado === 'procesando')
    if (!pendientes.length) return
    temporizador = window.setTimeout(async () => {
      for (const t of pendientes) {
        const nuevo = await api<TrabajoAgente>('GET', `/agent/jobs/${t.id}`).catch(() => null)
        if (nuevo) trabajos.value = trabajos.value.map((x) => (x.id === nuevo.id ? nuevo : x))
      }
      vigilar()
    }, 3000)
  }

  function detener(): void {
    window.clearTimeout(temporizador)
  }

  async function abrir(id: number): Promise<void> {
    error.value = ''
    try {
      abierto.value = await api<TrabajoAgente>('GET', `/agent/jobs/${id}`)
    } catch (e) {
      error.value = (e as Error).message
    }
  }

  /**
   * Guarda en el estudio los elementos aceptados (con su corrida de origen) y registra la decisión.
   * Si se aceptan con cambios, el instructor los edita después en el editor como cualquier otro elemento.
   */
  async function decidir(uids: string[]): Promise<void> {
    const t = abierto.value
    const r = t?.resultado
    if (!t || !r) return
    const corrida = t.corrida?.id ?? null
    for (const e of r.elementos) {
      if (uids.includes(e.contenido.uid)) await estudio.guardar(e.tipo, e.contenido, corrida)
    }
    const decision = uids.length === 0 ? 'descartado' : uids.length === r.elementos.length ? 'aceptado' : 'parcial'
    await api('POST', `/agent/jobs/${t.id}/decision`, { decision: r.elementos.length ? decision : 'aceptado', uids })
    abierto.value = null
    if (uids.length) {
      estudio.seleccionado = uids[0]
      void estudio.sincronizar()
    }
    await cargar()
    // Tema completo (ADR 0007): guardada la clase, llegan sus piezas una por una como propuestas
    const clase = r.elementos.find((e) => e.tipo === 'clase' && uids.includes(e.contenido.uid))
    if (t.plantilla === 'clase_tareas' && t.parametros.alcance.tema_completo && clase) {
      await pedirPiezas(piezasDelTema(clase.contenido as ClaseTareas), t.parametros.indicaciones ?? '', t.parametros.calidad ?? 'normal')
    }
  }

  /** Pide cada pieza del tema; se detiene en la primera que no se pueda pedir (el error queda a la vista). */
  async function pedirPiezas(piezas: AccionAgente[], indicaciones: string, calidad: 'normal' | 'alta'): Promise<boolean> {
    for (const p of piezas) {
      if (!(await solicitar(p, indicaciones, calidad, crypto.randomUUID()))) return false
    }
    return true
  }

  /** Elimina la propuesta del servidor. Lo que ya se guardó en el diseño se queda. */
  async function eliminar(id: number): Promise<void> {
    error.value = ''
    try {
      await api('DELETE', `/agent/jobs/${id}`)
      trabajos.value = trabajos.value.filter((t) => t.id !== id)
      if (abierto.value?.id === id) abierto.value = null
    } catch (e) {
      error.value = (e as Error).message
    }
  }

  return { trabajos, abierto, uso, error, enviando, cargar, solicitar, pedirPiezas, detener, abrir, decidir, eliminar }
})
