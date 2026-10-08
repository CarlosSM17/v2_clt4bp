import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { Conflicto, Contenido, ElementoLocal, GrupoCurso, InformeVerificacion, ResumenSync, TipoElemento } from '@shared/diseno'
import { api } from '../lib/api'
import { aplicarVariante } from '@shared/diseno'
import type { Variante } from '@shared/contracts'

/** Copia plana: IPC no puede clonar los proxies reactivos de Vue. */
export function plano<T>(v: T): T {
  return JSON.parse(JSON.stringify(v))
}

export const useDiseno = defineStore('diseno', () => {
  const cursoId = ref(0)
  const elementos = ref<ElementoLocal[]>([])
  const grupos = ref<GrupoCurso[]>([])
  const seleccionado = ref<string | null>(null)
  const grupoActivo = ref<string | null>(null) // null = versión base
  const sync = ref<ResumenSync | null>(null)
  const sincronizando = ref(false)
  const conflictos = ref<Conflicto[]>([])
  const error = ref('')
  const informe = ref<InformeVerificacion | null>(null)
  const verificando = ref(false)

  const porUid = computed(() => new Map(elementos.value.map((e) => [e.uid, e])))
  const actual = computed(() => (seleccionado.value ? (porUid.value.get(seleccionado.value) ?? null) : null))
  const pendientes = computed(() => elementos.value.filter((e) => e.sucio).length)

  function deTipo(tipo: TipoElemento, padre?: string | null): ElementoLocal[] {
    return elementos.value
      .filter((e) => e.tipo === tipo && (padre === undefined || e.padre_uid === padre))
      .sort((a, b) => a.orden - b.orden || a.uid.localeCompare(b.uid))
  }

  function variante(uid: string, grupo: string): ElementoLocal | null {
    return elementos.value.find((e) => e.tipo === 'variante' && e.padre_uid === uid && (e.contenido as Variante).grupo_clave === grupo) ?? null
  }

  /** Contenido que verá un grupo (base + su variante, si existe). */
  function efectivo(e: ElementoLocal, grupo: string | null): Contenido {
    const v = grupo ? variante(e.uid, grupo) : null
    return v ? aplicarVariante(e.contenido, (v.contenido as Variante).cambios) : e.contenido
  }

  async function abrir(id: number): Promise<void> {
    if (cursoId.value !== id) {
      cursoId.value = id
      seleccionado.value = null
      grupoActivo.value = null
    }
    await recargar()
    void sincronizar()
  }

  async function recargar(): Promise<void> {
    elementos.value = await window.consola.diseno.listar(cursoId.value)
    const info = await window.consola.diseno.info(cursoId.value)
    grupos.value = info.grupos
  }

  async function guardar(tipo: TipoElemento, contenido: Contenido, agentRunId: number | null = null): Promise<ElementoLocal> {
    const e = await window.consola.diseno.guardar(cursoId.value, tipo, plano(contenido), agentRunId)
    const i = elementos.value.findIndex((x) => x.uid === e.uid)
    if (i === -1) elementos.value.push(e)
    else elementos.value[i] = e
    return e
  }

  async function eliminar(uid: string): Promise<void> {
    const borrados = await window.consola.diseno.eliminar(cursoId.value, uid)
    if (seleccionado.value && borrados.includes(seleccionado.value)) seleccionado.value = null
    await recargar()
  }

  async function sincronizar(): Promise<void> {
    if (sincronizando.value || !cursoId.value) return
    sincronizando.value = true
    error.value = ''
    try {
      sync.value = await window.consola.diseno.sincronizar(cursoId.value)
      conflictos.value = await window.consola.diseno.conflictos(cursoId.value)
      await recargar()
    } catch (e) {
      error.value = (e as Error).message
    } finally {
      sincronizando.value = false
    }
  }

  async function resolver(uid: string, eleccion: 'local' | 'servidor'): Promise<void> {
    await window.consola.diseno.resolver(cursoId.value, uid, eleccion)
    await sincronizar()
  }

  /** Verificador CLT4BP. Revisa lo que hay en el servidor: por eso primero se sincroniza. */
  async function verificar(): Promise<void> {
    verificando.value = true
    error.value = ''
    try {
      await sincronizar()
      if (sync.value?.sinConexion) throw new Error('Sin conexión: el verificador se ejecuta en el servidor.')
      if (pendientes.value) throw new Error('Hay cambios sin subir (incompletos o en conflicto): corrígelos antes de verificar.')
      informe.value = await api<InformeVerificacion>('POST', `/courses/${cursoId.value}/design/verify`)
    } catch (e) {
      error.value = (e as Error).message
    } finally {
      verificando.value = false
    }
  }

  async function aprobar(uids: string[]): Promise<void> {
    error.value = ''
    try {
      await api('POST', `/courses/${cursoId.value}/design/approve`, { uids })
      await sincronizar() // baja el nuevo estado
    } catch (e) {
      error.value = (e as Error).message
    }
  }

  return {
    cursoId, elementos, grupos, seleccionado, grupoActivo, sync, sincronizando, conflictos, error, informe, verificando,
    porUid, actual, pendientes, deTipo, variante, efectivo,
    abrir, recargar, guardar, eliminar, sincronizar, resolver, verificar, aprobar
  }
})
