<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import type { TrabajoAgente } from '@shared/agente'
import { api } from '../../lib/api'
import PestanasCurso from '../../components/PestanasCurso.vue'

const props = defineProps<{ id: string }>()

interface Publicacion {
  id: number
  numero: number
  nota: string | null
  estado: 'publicada' | 'revertida'
  created_at: string
  autor: { name: string } | null
}
interface Revision {
  problemas: string[]
  conteo: Record<string, number>
}
interface Plan {
  clases: { uid: string; orden: number; titulo: string; abrieron: number }[]
  grupos: { id: number; clave: string; nombre: string }[]
  activaciones: { clase_uid: string; grupo_clave: string | null; abre_at: string; cierra_at: string | null; requiere_anterior: boolean; avisado: boolean }[]
}
interface Celda {
  abre: string // datetime-local (hora local)
  cierra: string
  requiere: boolean
  avisado: boolean
}

const publicaciones = ref<Publicacion[]>([])
const vigente = ref<number | null>(null)
const revision = ref<Revision | null>(null)
const plan = ref<Plan | null>(null)
const nota = ref('')
const clave = ref(crypto.randomUUID()) // idempotencia: un reintento no crea otra versión
const ocupado = ref(false)
const error = ref('')
const aviso = ref('')

// Columnas del plan: «todo el curso» y un grupo por columna
const columnas = computed(() => [{ clave: null as string | null, nombre: 'Todo el curso' }, ...(plan.value?.grupos ?? [])])
const celdas = reactive<Record<string, Celda>>({})
const llave = (clase: string, grupo: string | null): string => `${clase}|${grupo ?? '*'}`

function aLocal(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60_000).toISOString().slice(0, 16)
}

async function cargar(): Promise<void> {
  error.value = ''
  try {
    const r = await api<{ vigente: number | null; publicaciones: Publicacion[] }>('GET', `/courses/${props.id}/releases`)
    publicaciones.value = r.publicaciones
    vigente.value = r.vigente
    plan.value = await api<Plan>('GET', `/courses/${props.id}/activations`)
    for (const k of Object.keys(celdas)) delete celdas[k]
    for (const c of plan.value.clases) for (const g of columnas.value) celdas[llave(c.uid, g.clave)] = { abre: '', cierra: '', requiere: false, avisado: false }
    for (const a of plan.value.activaciones) {
      // Solo las celdas de la tabla: una fecha de otra clase o grupo no se vería, pero «Guardar plan» la reenviaría
      if (!celdas[llave(a.clase_uid, a.grupo_clave)]) continue
      celdas[llave(a.clase_uid, a.grupo_clave)] = { abre: aLocal(a.abre_at), cierra: aLocal(a.cierra_at), requiere: a.requiere_anterior, avisado: a.avisado }
    }
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function revisar(): Promise<void> {
  ocupado.value = true
  error.value = ''
  aviso.value = ''
  try {
    // Se publica lo que hay en el servidor: primero se sube lo local
    const s = await window.consola.diseno.sincronizar(Number(props.id))
    if (s.sinConexion) throw new Error('Sin conexión: publicar requiere el servidor.')
    revision.value = await api<Revision>('GET', `/courses/${props.id}/releases/preview`)
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    ocupado.value = false
  }
}

async function publicar(): Promise<void> {
  ocupado.value = true
  error.value = ''
  aviso.value = ''
  try {
    const r = await api<Publicacion>('POST', `/courses/${props.id}/releases`, { nota: nota.value || null, clave_idempotencia: clave.value })
    aviso.value = `Versión ${r.numero} publicada.`
    nota.value = ''
    revision.value = null
    clave.value = crypto.randomUUID()
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    ocupado.value = false
  }
}

async function revertir(p: Publicacion): Promise<void> {
  if (!confirm(`¿Revertir la versión ${p.numero}? Los estudiantes volverán a ver la anterior; su avance se conserva.`)) return
  error.value = ''
  aviso.value = ''
  try {
    const r = await api<{ vigente: { numero: number } }>('POST', `/releases/${p.id}/rollback`)
    aviso.value = `Vigente otra vez: versión ${r.vigente.numero}.`
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

/** Llena las fechas con el último plan de implementación que propuso el agente (paso 8). */
async function usarPlanDelAgente(): Promise<void> {
  error.value = ''
  aviso.value = ''
  try {
    const trabajos = await api<TrabajoAgente[]>('GET', `/courses/${props.id}/agent/jobs`)
    const ultimo = trabajos.find((t) => t.plantilla === 'plan_implementacion' && t.estado === 'listo')
    if (!ultimo) throw new Error('Todavía no hay un plan del agente. Pídelo en el estudio: «Plan de fechas por grupo».')
    const t = await api<TrabajoAgente>('GET', `/agent/jobs/${ultimo.id}`)
    const sesiones = (t.resultado?.notas.plan as { sesiones?: { clase_uid: string; grupo_clave: string; abre: string; cierra: string }[] })?.sesiones ?? []
    let usadas = 0
    for (const s of sesiones) {
      const grupo = plan.value?.grupos.some((g) => g.clave === s.grupo_clave) ? s.grupo_clave : null
      const celda = celdas[llave(s.clase_uid, grupo)]
      if (!celda) continue // la clase no está en la publicación vigente
      Object.assign(celda, { abre: `${s.abre}T08:00`, cierra: `${s.cierra}T23:59` })
      usadas++
    }
    aviso.value = `Se usaron ${usadas} fechas del plan. Revísalas y guarda.`
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function guardarPlan(): Promise<void> {
  error.value = ''
  aviso.value = ''
  const activaciones = Object.entries(celdas)
    .filter(([, c]) => c.abre)
    .map(([k, c]) => {
      const [clase_uid, grupo] = k.split('|')
      return {
        clase_uid,
        grupo_clave: grupo === '*' ? null : grupo,
        abre_at: new Date(c.abre).toISOString(),
        cierra_at: c.cierra ? new Date(c.cierra).toISOString() : null,
        requiere_anterior: c.requiere
      }
    })
  try {
    await api('PUT', `/courses/${props.id}/activations`, { activaciones })
    aviso.value = 'Plan guardado. Los estudiantes reciben el aviso cuando llega la fecha de apertura.'
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

onMounted(cargar)
</script>

<template>
  <div>
    <h1 class="mb-4 text-xl font-semibold">Publicación y activación</h1>
    <PestanasCurso :id="id" />
    <p v-if="error" class="mb-3 whitespace-pre-line text-sm text-red-600">{{ error }}</p>
    <p v-if="aviso" class="mb-3 text-sm text-green-700">{{ aviso }}</p>

    <section class="mb-8 rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="mb-2 font-semibold">Nueva publicación</h2>
      <p class="mb-3 text-sm text-slate-600">Entra todo lo aprobado. Lo que estés editando conserva su versión publicada hasta que lo vuelvas a aprobar.</p>
      <button class="btn-sec" :disabled="ocupado" @click="revisar">{{ ocupado ? 'Revisando…' : 'Sincronizar y revisar' }}</button>
      <div v-if="revision" class="mt-3 text-sm">
        <p>
          {{ revision.conteo.clases }} clases · {{ revision.conteo.tareas }} tareas · {{ revision.conteo.soporte }} de soporte ·
          {{ revision.conteo.procedimental }} ayudas · {{ revision.conteo.variantes }} variantes · {{ revision.conteo.medios }} medios
        </p>
        <ul v-if="revision.problemas.length" class="mt-2 list-disc pl-5 text-red-700">
          <li v-for="(p, i) in revision.problemas" :key="i">{{ p }}</li>
        </ul>
        <template v-else>
          <textarea v-model="nota" class="campo mt-3 h-20" placeholder="Nota de versión: qué cambió (la verán tus registros, no los estudiantes)" />
          <button class="btn mt-2" :disabled="ocupado" @click="publicar">Publicar versión {{ (publicaciones[0]?.numero ?? 0) + 1 }}</button>
        </template>
      </div>
    </section>

    <section class="mb-8">
      <h2 class="mb-2 font-semibold">Historial</h2>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-slate-500"><th>Versión</th><th>Fecha</th><th>Autor</th><th>Nota</th><th>Estado</th><th /></tr></thead>
        <tbody>
          <tr v-for="p in publicaciones" :key="p.id" class="border-t border-slate-100">
            <td>{{ p.numero }}<span v-if="p.id === vigente" class="ml-1 rounded bg-green-100 px-1 text-xs text-green-800">vigente</span></td>
            <td>{{ new Date(p.created_at).toLocaleString() }}</td>
            <td>{{ p.autor?.name }}</td>
            <td class="max-w-xs truncate" :title="p.nota ?? ''">{{ p.nota }}</td>
            <td>{{ p.estado }}</td>
            <td><button v-if="p.id === vigente && publicaciones.length > 1" class="text-xs text-red-600" @click="revertir(p)">Revertir</button></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-if="plan?.clases.length">
      <div class="mb-2 flex items-center justify-between">
        <h2 class="font-semibold">Plan de implementación (paso 8)</h2>
        <div class="flex gap-2">
          <button class="btn-sec" @click="usarPlanDelAgente">Usar el plan del agente</button>
          <button class="btn" @click="guardarPlan">Guardar plan</button>
        </div>
      </div>
      <p class="mb-3 text-sm text-slate-600">
        Si un grupo tiene fechas propias, se usan esas; si no, las de «Todo el curso». «Tras la anterior» abre la clase solo cuando el estudiante ya
        trabajó todas las tareas de la clase previa.
      </p>
      <div class="overflow-x-auto">
        <table class="text-sm">
          <thead>
            <tr><th class="text-left">Clase</th><th v-for="g in columnas" :key="g.clave ?? '*'" class="px-2 text-left">{{ g.nombre }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="c in plan.clases" :key="c.uid" class="border-t border-slate-100 align-top">
              <td class="py-2 pr-3">
                {{ c.orden }}. {{ c.titulo }}
                <p class="text-xs text-slate-500">La abrieron {{ c.abrieron }} estudiantes</p>
              </td>
              <td v-for="g in columnas" :key="g.clave ?? '*'" class="px-2 py-2">
                <template v-if="celdas[llave(c.uid, g.clave)]">
                  <label class="block text-xs text-slate-500">Abre <input v-model="celdas[llave(c.uid, g.clave)].abre" type="datetime-local" class="campo" /></label>
                  <label class="block text-xs text-slate-500">Cierra <input v-model="celdas[llave(c.uid, g.clave)].cierra" type="datetime-local" class="campo" /></label>
                  <label class="flex items-center gap-1 text-xs"><input v-model="celdas[llave(c.uid, g.clave)].requiere" type="checkbox" /> Tras la anterior</label>
                  <span v-if="celdas[llave(c.uid, g.clave)].avisado" class="text-xs text-green-700">Aviso enviado</span>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
    <p v-else class="text-sm text-slate-500">Publica una primera versión para programar las fechas.</p>
  </div>
</template>
