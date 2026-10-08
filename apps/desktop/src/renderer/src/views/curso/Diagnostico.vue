<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import type { Analisis, FilaDiagnostico, GrupoPropuesto } from '@shared/tipos'
import { api } from '../../lib/api'
import PestanasCurso from '../../components/PestanasCurso.vue'

const props = defineProps<{ id: string }>()

const filas = ref<FilaDiagnostico[]>([])
const analisis = ref<Analisis | null>(null)
const grupos = ref<GrupoPropuesto[]>([])
const asignacion = reactive<Record<number, string>>({}) // enrollment_id => clave del grupo
const silueta = ref<number | null>(null)
const decision = reactive({ decision: '', justificacion: '' })
const error = ref('')
const aviso = ref('')

const conPerfil = computed(() => filas.value.filter((f) => f.perfil))
const maxHist = computed(() =>
  Math.max(1, ...Object.values(analisis.value?.resultado.histograma ?? {}))
)

async function cargar(): Promise<void> {
  filas.value = await api<FilaDiagnostico[]>('GET', `/courses/${props.id}/diagnostico`)
  analisis.value = await api<Analisis | null>('GET', `/courses/${props.id}/analisis`)
  if (analisis.value) {
    decision.decision = analisis.value.decision ?? analisis.value.recomendacion
  }
}

async function ejecutar(fn: () => Promise<void>): Promise<void> {
  error.value = ''
  aviso.value = ''
  try {
    await fn()
  } catch (e) {
    error.value = (e as Error).message
  }
}

const preparar = (): Promise<void> =>
  ejecutar(async () => {
    await api('POST', `/courses/${props.id}/diagnostico`)
    aviso.value =
      'Diagnóstico preparado: el MSLQ ya aparece a los estudiantes. Crea también las pruebas pre.'
  })

const decidir = (): Promise<void> =>
  ejecutar(async () => {
    await api('POST', `/courses/${props.id}/analisis/${analisis.value!.id}/decision`, decision)
    await cargar()
    aviso.value = 'Decisión registrada.'
  })

const proponer = (metodo: 'nivel' | 'kmeans'): Promise<void> =>
  ejecutar(async () => {
    const r = await api<{ silueta: number | null; grupos: GrupoPropuesto[] }>(
      'POST',
      `/courses/${props.id}/grupos/propuesta`,
      { metodo }
    )
    grupos.value = r.grupos
    silueta.value = r.silueta
    for (const g of r.grupos) for (const m of g.miembros) asignacion[m] = g.clave
  })

const guardar = (): Promise<void> =>
  ejecutar(async () => {
    const cuerpo = grupos.value.map((g) => ({
      ...g,
      miembros: Object.entries(asignacion)
        .filter(([, c]) => c === g.clave)
        .map(([id]) => Number(id))
    }))
    await api('PUT', `/courses/${props.id}/grupos`, { grupos: cuerpo })
    await cargar()
    aviso.value = 'Grupos guardados.'
  })

onMounted(() => ejecutar(cargar))
</script>

<template>
  <h1 class="mb-2 text-2xl font-semibold">Diagnóstico y grupos</h1>
  <PestanasCurso :id="id" />

  <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>
  <p v-if="aviso" class="mb-4 text-sm text-green-700">{{ aviso }}</p>

  <section class="tarjeta mb-6">
    <div class="mb-3 flex items-center justify-between">
      <h2 class="font-medium">
        Avance del diagnóstico ({{ conPerfil.length }} de {{ filas.length }} con perfil)
      </h2>
      <button class="btn-sec" @click="preparar">Preparar diagnóstico (MSLQ)</button>
    </div>
    <table class="w-full text-sm">
      <thead class="text-left text-slate-500">
        <tr>
          <th class="py-1">Estudiante</th>
          <th>Pasos</th>
          <th>CP teórico</th>
          <th>CP práctico</th>
          <th>CP</th>
          <th>Nivel</th>
          <th>Banderas</th>
          <th>Grupo</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="f in filas" :key="f.enrollment_id" class="border-t border-slate-100">
          <td class="py-2">
            {{ f.nombre }}
            <span class="font-mono text-xs text-slate-500">{{ f.seudonimo }}</span>
          </td>
          <td>{{ f.pasos.filter((p) => p.estado === 'completo').length }}/{{ f.pasos.length }}</td>
          <td>{{ f.perfil?.cp_teorico ?? '—' }}</td>
          <td>{{ f.perfil?.cp_practico ?? '—' }}</td>
          <td class="font-medium">{{ f.perfil?.cp_global ?? '—' }}</td>
          <td>{{ f.perfil?.nivel ?? '—' }}</td>
          <td class="text-xs text-amber-700">{{ f.perfil?.banderas.join(', ') }}</td>
          <td>
            <select
              v-if="grupos.length && f.perfil"
              v-model="asignacion[f.enrollment_id]"
              class="campo py-0.5"
            >
              <option v-for="g in grupos" :key="g.clave" :value="g.clave">{{ g.nombre }}</option>
            </select>
            <span v-else>{{ f.grupo?.nombre ?? '—' }}</span>
          </td>
        </tr>
      </tbody>
    </table>
  </section>

  <section v-if="analisis" class="tarjeta mb-6 grid grid-cols-2 gap-6">
    <div>
      <h2 class="mb-3 font-medium">¿Conocimientos homogéneos?</h2>
      <dl class="grid grid-cols-2 gap-1 text-sm">
        <dt class="text-slate-500">Estudiantes con perfil</dt>
        <dd>{{ analisis.resultado.n }}</dd>
        <dt class="text-slate-500">Media · desviación</dt>
        <dd>{{ analisis.resultado.media }} · {{ analisis.resultado.desviacion }}</dd>
        <dt class="text-slate-500">Coeficiente de variación</dt>
        <dd>{{ analisis.resultado.cv ?? '—' }}</dd>
        <dt class="text-slate-500">Nivel más frecuente</dt>
        <dd>
          {{ analisis.resultado.nivel_modal }} ({{
            Math.round(analisis.resultado.proporcion_modal * 100)
          }}
          %)
        </dd>
        <dt class="text-slate-500">Recomendación</dt>
        <dd class="font-medium">{{ analisis.recomendacion }}</dd>
      </dl>
      <p v-if="!analisis.resultado.confiable" class="mt-2 text-sm text-amber-700">
        Menos de 10 estudiantes: la recomendación es poco confiable; decide con tu criterio.
      </p>

      <form class="mt-4 space-y-2" @submit.prevent="decidir">
        <select v-model="decision.decision" class="campo">
          <option value="homogeneo">
            Homogéneo: una sola versión del material (Fase 2, paso 5)
          </option>
          <option value="heterogeneo">
            Heterogéneo: estrategias diferenciadas (Fase 2, paso 4)
          </option>
        </select>
        <textarea
          v-if="decision.decision !== analisis.recomendacion"
          v-model="decision.justificacion"
          class="campo h-16"
          placeholder="Justifica por qué te apartas de la recomendación"
        />
        <button class="btn">Registrar decisión</button>
        <p v-if="analisis.decision" class="text-xs text-slate-500">
          Decisión vigente: {{ analisis.decision }}
        </p>
      </form>
    </div>

    <div>
      <h2 class="mb-3 font-medium">Distribución del conocimiento previo</h2>
      <div class="flex h-40 items-end gap-1">
        <div
          v-for="(v, tramo) in analisis.resultado.histograma"
          :key="tramo"
          class="flex flex-1 flex-col items-center"
        >
          <div
            class="w-full rounded-t bg-indigo-400"
            :style="{ height: `${(v / maxHist) * 120}px` }"
            :title="`${v}`"
          />
          <span class="mt-1 text-[10px] text-slate-500">{{ String(tramo).split('-')[0] }}</span>
        </div>
      </div>
    </div>
  </section>

  <section v-if="analisis?.decision === 'heterogeneo'" class="tarjeta">
    <h2 class="mb-3 font-medium">Grupos diferenciados</h2>
    <div class="mb-3 flex gap-2">
      <button class="btn-sec" @click="proponer('nivel')">Proponer por nivel</button>
      <button class="btn-sec" @click="proponer('kmeans')">Proponer por k-means</button>
      <span v-if="silueta !== null" class="self-center text-sm text-slate-500">
        Silueta: {{ silueta }}
      </span>
    </div>
    <p v-if="grupos.length" class="mb-3 text-sm text-slate-600">
      Ajusta la columna «Grupo» de la tabla de arriba y guarda. Los estudiantes solo ven el nombre
      «Ruta».
    </p>
    <button v-if="grupos.length" class="btn" @click="guardar">Guardar grupos</button>
  </section>
</template>
