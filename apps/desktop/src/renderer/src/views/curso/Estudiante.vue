<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { MEDIDAS, num, type FichaEstudiante } from '@shared/evaluacion'
import { api } from '../../lib/api'
import PestanasCurso from '../../components/PestanasCurso.vue'
import EditorCodigo from '../../components/EditorCodigo.vue'

const props = defineProps<{ id: string; inscripcion: string }>()

const f = ref<FichaEstudiante | null>(null)
const envioAbierto = ref<number | null>(null)
const error = ref('')

onMounted(async () => {
  try {
    f.value = await api<FichaEstudiante>('GET', `/courses/${props.id}/estudiantes/${props.inscripcion}`)
  } catch (e) {
    error.value = (e as Error).message
  }
})

const fecha = (iso: string | null): string => (iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '--')
</script>

<template>
  <RouterLink :to="{ name: 'curso-tablero', params: { id } }" class="text-sm text-indigo-700 hover:underline">← Seguimiento del grupo</RouterLink>
  <h1 class="mb-2 text-2xl font-semibold">{{ f?.nombre ?? 'Estudiante' }}</h1>
  <PestanasCurso :id="id" />
  <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>

  <template v-if="f">
    <section class="mb-6 grid grid-cols-3 gap-4 text-sm">
      <div class="tarjeta">
        <p class="text-slate-500">Seudónimo · estado</p>
        <p class="font-mono">{{ f.seudonimo }}</p>
        <p>{{ f.estado }}<span v-if="f.grupo"> · {{ f.grupo.nombre }}</span></p>
      </div>
      <div class="tarjeta">
        <p class="text-slate-500">Perfil inicial</p>
        <p v-if="f.perfil">CP {{ f.perfil.cp_global }} ({{ f.perfil.nivel }}) · teórico {{ f.perfil.cp_teorico }} · práctico {{ f.perfil.cp_practico }}</p>
        <p v-if="f.perfil?.banderas.length" class="text-amber-700">{{ f.perfil.banderas.join(', ') }}</p>
      </div>
      <div class="tarjeta">
        <p class="text-slate-500">Pre → post</p>
        <p v-for="m in ['global', 'teorico', 'practico']" :key="m">
          {{ MEDIDAS[m] }}: {{ num(f.puntajes?.pre[m], 1) }} → {{ num(f.puntajes?.post[m], 1) }}
        </p>
      </div>
    </section>

    <section class="tarjeta mb-6">
      <h2 class="mb-2 font-medium">Tareas, auto-explicaciones y código</h2>
      <table class="w-full text-sm">
        <thead class="text-left text-slate-500">
          <tr><th class="py-1">Tarea</th><th>Estado</th><th>Envíos</th><th>Mejor</th><th>Esfuerzo</th><th>Auto-explicación</th></tr>
        </thead>
        <tbody>
          <tr v-for="x in f.tareas" :key="x.tarea_uid" class="border-t border-slate-100 align-top">
            <td class="py-1">{{ x.titulo }}</td>
            <td>{{ x.estado }}</td>
            <td>{{ x.intentos }}</td>
            <td>{{ x.mejor_fraccion === null ? '--' : `${Math.round(x.mejor_fraccion * 100)} %` }}</td>
            <td>{{ x.esfuerzo ?? '--' }}</td>
            <td class="max-w-md whitespace-pre-wrap text-xs">{{ x.autoexplicacion ?? '' }}</td>
          </tr>
        </tbody>
      </table>
      <h3 class="mb-1 mt-4 text-sm font-medium">Últimos envíos</h3>
      <ul class="space-y-1 text-sm">
        <li v-for="e in f.envios" :key="e.id">
          <button class="text-indigo-700 hover:underline" @click="envioAbierto = envioAbierto === e.id ? null : e.id">
            {{ e.tarea_uid }} #{{ e.numero }} · {{ fecha(e.created_at) }} · {{ e.fraccion === null ? e.estado : `${Math.round(e.fraccion * 100)} %` }}
          </button>
          <div v-if="envioAbierto === e.id" class="mt-1">
            <EditorCodigo :model-value="e.codigo" :lenguaje="f.lenguaje" :solo-lectura="true" />
          </div>
        </li>
      </ul>
    </section>

    <section class="mb-6 grid grid-cols-2 gap-6">
      <div class="tarjeta text-sm">
        <h2 class="mb-2 font-medium">Cuestionarios</h2>
        <div v-for="(r, i) in f.instrumentos" :key="i" class="mb-2">
          <p class="font-medium">{{ r.instrumento }} · {{ r.momento }}{{ r.clase_uid ? ` (${r.clase_uid})` : '' }}</p>
          <p class="text-xs text-slate-600">
            <span v-for="(v, k) in r.subescalas" :key="k" class="mr-2">{{ k }} {{ num(v) }}</span>
          </p>
        </div>
      </div>
      <div class="tarjeta text-sm">
        <h2 class="mb-2 font-medium">Trayectoria (últimos 300 eventos)</h2>
        <ol class="max-h-96 space-y-0.5 overflow-y-auto text-xs">
          <li v-for="(ev, i) in f.trayectoria" :key="i">
            <span class="text-slate-500">{{ fecha(ev.ocurrido_at) }}</span> {{ ev.verbo }} {{ ev.objeto_uid ?? '' }}
            <span v-if="ev.duracion_ms" class="text-slate-500">({{ Math.round(ev.duracion_ms / 1000) }} s)</span>
          </li>
        </ol>
      </div>
    </section>
  </template>
</template>
