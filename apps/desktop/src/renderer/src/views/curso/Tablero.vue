<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ARCS, colorCelda, num, type Alerta, type Tablero } from '@shared/evaluacion'
import { api } from '../../lib/api'
import PestanasCurso from '../../components/PestanasCurso.vue'

const props = defineProps<{ id: string }>()

interface Comentario {
  id: number
  tarea_uid: string
  texto: string
  oculto: boolean
  created_at: string
  autor: { name: string }
}

const t = ref<Tablero | null>(null)
const comentarios = ref<Comentario[]>([])
const filtroGrupo = ref('')
const error = ref('')

const TIPOS_ALERTA: Record<Alerta['tipo'], string> = {
  sin_actividad: 'Sin actividad',
  esfuerzo_sin_desempeno: 'Esfuerzo alto, desempeño bajo',
  diagnostico_incompleto: 'Diagnóstico incompleto',
  ansiedad_alta: 'Ansiedad alta'
}

async function cargar(): Promise<void> {
  error.value = ''
  try {
    t.value = await api<Tablero>('GET', `/courses/${props.id}/tablero`)
    comentarios.value = await api<Comentario[]>('GET', `/courses/${props.id}/comments`)
  } catch (e) {
    error.value = (e as Error).message
  }
}

const estudiantes = computed(() => (t.value?.estudiantes ?? []).filter((e) => !filtroGrupo.value || e.grupo?.clave === filtroGrupo.value))
const grupos = computed(() => [...new Set((t.value?.estudiantes ?? []).flatMap((e) => (e.grupo ? [e.grupo.clave] : [])))])
const conAlertas = computed(() => estudiantes.value.filter((e) => e.alertas.length))
const titulo = computed(() => Object.fromEntries((t.value?.tareas ?? []).map((x) => [x.uid, `C${x.clase_orden}·T${x.orden} ${x.titulo}`])))
// Encabezado del mapa de calor: una columna por tarea, agrupadas por clase
const clases = computed(() => {
  const r: { orden: number | null; tareas: number }[] = []
  for (const x of t.value?.tareas ?? []) {
    if (r.at(-1)?.orden === x.clase_orden) r.at(-1)!.tareas++
    else r.push({ orden: x.clase_orden, tareas: 1 })
  }
  return r
})

function detalle(uid: string, e: Tablero['estudiantes'][number]): string {
  const c = e.celdas[uid]
  if (!c) return `${titulo.value[uid]}: sin abrir`
  return `${titulo.value[uid]}: ${c.fraccion === null ? 'sin envíos' : `${Math.round(c.fraccion * 100)} % de casos`}, ${c.intentos} envíos, esfuerzo ${c.esfuerzo ?? '--'}`
}

async function moderar(c: Comentario): Promise<void> {
  try {
    await api('PATCH', `/comments/${c.id}`, { oculto: !c.oculto })
    c.oculto = !c.oculto
  } catch (e) {
    error.value = (e as Error).message
  }
}

onMounted(cargar)
</script>

<template>
  <h1 class="mb-2 text-2xl font-semibold">Seguimiento del grupo</h1>
  <PestanasCurso :id="id" />
  <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>

  <template v-if="t">
    <section class="mb-6 grid grid-cols-4 gap-4">
      <div class="tarjeta">
        <p class="text-sm text-slate-500">Estudiantes</p>
        <p class="text-2xl font-semibold">{{ t.estudiantes.length }}</p>
      </div>
      <div class="tarjeta">
        <p class="text-sm text-slate-500">Sin terminar el diagnóstico</p>
        <p class="text-2xl font-semibold" :class="t.diagnostico.faltan ? 'text-amber-700' : ''">{{ t.diagnostico.faltan }}</p>
      </div>
      <div class="tarjeta text-sm">
        <p class="mb-1 text-slate-500">Nivel inicial</p>
        <p v-for="(n, nivel) in t.diagnostico.niveles" :key="nivel">{{ nivel }}: {{ n }}</p>
      </div>
      <div class="tarjeta text-sm">
        <p class="mb-1 text-slate-500">Grupos</p>
        <p v-for="(n, g) in t.diagnostico.grupos" :key="g">{{ g }}: {{ n }}</p>
      </div>
    </section>

    <section v-if="conAlertas.length" class="tarjeta mb-6">
      <h2 class="mb-2 font-medium">Alertas: a quién apoyar</h2>
      <ul class="space-y-1 text-sm">
        <li v-for="e in conAlertas" :key="e.enrollment_id">
          <RouterLink :to="{ name: 'curso-estudiante', params: { id, inscripcion: e.enrollment_id } }" class="font-medium text-indigo-700 hover:underline">
            {{ e.nombre }}
          </RouterLink>
          <span v-for="a in e.alertas" :key="a.tipo" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900" :title="a.texto">
            {{ TIPOS_ALERTA[a.tipo] }}
          </span>
        </li>
      </ul>
    </section>

    <section class="tarjeta mb-6 overflow-x-auto">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="font-medium">Mapa de calor: estudiantes × tareas</h2>
        <select v-if="grupos.length" v-model="filtroGrupo" class="campo w-48 py-0.5">
          <option value="">Todos los grupos</option>
          <option v-for="g in grupos" :key="g" :value="g">{{ g }}</option>
        </select>
      </div>
      <table class="text-xs">
        <thead>
          <tr>
            <th />
            <th v-for="c in clases" :key="String(c.orden)" :colspan="c.tareas" class="border-l border-slate-200 px-1 text-slate-500">Clase {{ c.orden }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in estudiantes" :key="e.enrollment_id">
            <td class="whitespace-nowrap pr-3">
              <RouterLink :to="{ name: 'curso-estudiante', params: { id, inscripcion: e.enrollment_id } }" class="hover:underline">{{ e.nombre }}</RouterLink>
            </td>
            <td v-for="x in t.tareas" :key="x.uid" class="p-0.5">
              <div class="size-5 rounded-sm" :class="colorCelda(e.celdas[x.uid], x.nivel_apoyo)" :title="detalle(x.uid, e)" />
            </td>
          </tr>
        </tbody>
      </table>
      <p class="mt-2 flex flex-wrap gap-3 text-xs text-slate-500">
        <span><span class="inline-block size-3 rounded-sm bg-emerald-500 align-middle" /> todos los casos</span>
        <span><span class="inline-block size-3 rounded-sm bg-amber-300 align-middle" /> la mitad o más</span>
        <span><span class="inline-block size-3 rounded-sm bg-rose-400 align-middle" /> menos de la mitad</span>
        <span><span class="inline-block size-3 rounded-sm bg-slate-200 align-middle" /> abierta sin envíos</span>
        <span><span class="inline-block size-3 rounded-sm bg-sky-300 align-middle" /> ejemplo estudiado</span>
      </p>
    </section>

    <section class="mb-6 grid grid-cols-2 gap-6">
      <div class="tarjeta">
        <h2 class="mb-2 font-medium">Carga cognitiva por clase (escala CS, 0–10)</h2>
        <p v-if="!t.carga_por_clase.length" class="text-sm text-slate-500">Aún nadie responde la escala al terminar una clase.</p>
        <div v-for="c in t.carga_por_clase" :key="c.clase_uid" class="mb-3 text-sm">
          <p>
            Clase {{ c.orden }} · {{ c.titulo }} <span class="text-slate-500">(n = {{ c.n }})</span>
            <span v-if="c.extrinseca_alta" class="ml-1 rounded bg-rose-100 px-1.5 text-xs text-rose-800">carga extrínseca alta: revisa el diseño</span>
          </p>
          <div v-for="k in (['intrinseca', 'extrinseca', 'germana'] as const)" :key="k" class="flex items-center gap-2">
            <span class="w-20 text-xs text-slate-500">{{ k }}</span>
            <div class="h-2 flex-1 rounded bg-slate-100">
              <div class="h-2 rounded" :class="k === 'extrinseca' ? 'bg-rose-400' : 'bg-indigo-400'" :style="{ width: `${c[k] * 10}%` }" />
            </div>
            <span class="w-8 text-right text-xs">{{ num(c[k], 1) }}</span>
          </div>
        </div>
      </div>
      <div class="tarjeta">
        <h2 class="mb-2 font-medium">Motivación con el material (IMMS, 1–5)</h2>
        <p v-if="!t.imms" class="text-sm text-slate-500">Se llena con la evaluación final.</p>
        <template v-else>
          <p class="mb-2 text-xs text-slate-500">n = {{ t.imms.n }}</p>
          <div v-for="(v, k) in t.imms.subescalas" :key="k" class="flex items-center gap-2 text-sm">
            <span class="w-24">{{ ARCS[k] ?? k }}</span>
            <div class="h-2 flex-1 rounded bg-slate-100"><div class="h-2 rounded bg-indigo-500" :style="{ width: `${((v - 1) / 4) * 100}%` }" /></div>
            <span class="w-10 text-right text-xs">{{ num(v) }}</span>
          </div>
        </template>
      </div>
    </section>

    <section class="tarjeta mb-6">
      <h2 class="mb-2 font-medium">Esfuerzo mental y uso de la ayuda por tarea</h2>
      <table class="w-full text-sm">
        <thead class="text-left text-slate-500">
          <tr><th class="py-1">Tarea</th><th>Abrieron</th><th>Esfuerzo (1–9)</th><th>Desempeño</th><th>Consultaron ayuda</th></tr>
        </thead>
        <tbody>
          <tr v-for="x in t.esfuerzo_por_tarea" :key="x.tarea_uid" class="border-t border-slate-100">
            <td class="py-1">{{ titulo[x.tarea_uid] }}</td>
            <td>{{ x.abrieron }}</td>
            <td :class="(x.esfuerzo ?? 0) >= 7 ? 'font-medium text-rose-700' : ''">{{ num(x.esfuerzo, 1) }} <span class="text-xs text-slate-400">(n = {{ x.n_esfuerzo }})</span></td>
            <td>{{ x.desempeno === null ? '--' : `${Math.round(x.desempeno * 100)} %` }}</td>
            <td>
              <template v-for="a in t.ayudas.filter((y) => y.tarea_uid === x.tarea_uid)" :key="a.tarea_uid">
                {{ a.estudiantes }} de {{ a.abrieron }} ({{ a.consultas }} consultas)
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="tarjeta">
      <h2 class="mb-2 font-medium">Discusiones de las tareas</h2>
      <p v-if="!comentarios.length" class="text-sm text-slate-500">Sin comentarios.</p>
      <ul class="space-y-2 text-sm">
        <li v-for="c in comentarios" :key="c.id" class="flex items-start justify-between gap-3" :class="c.oculto ? 'opacity-50' : ''">
          <p>
            <span class="font-medium">{{ c.autor.name }}</span> <span class="text-xs text-slate-500">en {{ titulo[c.tarea_uid] ?? c.tarea_uid }}</span><br />
            {{ c.texto }}
          </p>
          <button class="btn-sec shrink-0" @click="moderar(c)">{{ c.oculto ? 'Mostrar' : 'Ocultar' }}</button>
        </li>
      </ul>
    </section>
  </template>
</template>
