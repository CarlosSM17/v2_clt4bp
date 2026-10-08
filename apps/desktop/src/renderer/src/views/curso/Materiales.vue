<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import type { DocumentoCurso } from '@shared/tipos'
import { api } from '../../lib/api'
import PestanasCurso from '../../components/PestanasCurso.vue'

const props = defineProps<{ id: string }>()

const documentos = ref<DocumentoCurso[]>([])
const titulo = ref('')
const error = ref('')
const aviso = ref('')
const subiendo = ref(false)
let reloj: ReturnType<typeof setInterval> | null = null

const ESTADO: Record<DocumentoCurso['estado'], string> = {
  procesando: 'Procesando…',
  listo: 'Listo',
  error: 'Error'
}
const procesando = computed(() => documentos.value.some((d) => d.estado === 'procesando'))

async function cargar(): Promise<void> {
  documentos.value = await api<DocumentoCurso[]>('GET', `/courses/${props.id}/documents`)
  // Mientras algo se indexa, se consulta cada pocos segundos
  if (procesando.value && !reloj) reloj = setInterval(() => void cargar(), 4000)
  if (!procesando.value && reloj) {
    clearInterval(reloj)
    reloj = null
  }
}

async function subir(): Promise<void> {
  error.value = ''
  aviso.value = ''
  subiendo.value = true
  try {
    const r = await window.consola.material.subir(Number(props.id), titulo.value)
    if (!r) return // cerró el diálogo
    if (!r.ok) {
      error.value = r.errors ? Object.values(r.errors).flat().join(' ') : r.message
      return
    }
    titulo.value = ''
    aviso.value = `«${r.data.titulo}» se está procesando.`
    await cargar()
  } finally {
    subiendo.value = false
  }
}

async function eliminar(d: DocumentoCurso): Promise<void> {
  if (!confirm(`¿Eliminar «${d.titulo}»? El agente dejará de consultarlo.`)) return
  error.value = ''
  try {
    await api('DELETE', `/courses/${props.id}/documents/${d.id}`)
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

const tamano = (bytes: number): string =>
  bytes >= 1_048_576
    ? `${(bytes / 1_048_576).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`

onMounted(() => void cargar().catch((e) => (error.value = (e as Error).message)))
onUnmounted(() => reloj && clearInterval(reloj))
</script>

<template>
  <h1 class="mb-2 text-2xl font-semibold">Material del curso</h1>
  <PestanasCurso :id="id" />

  <section class="tarjeta mb-6">
    <p class="mb-3 text-sm text-slate-600">
      El asistente IA consulta este material (apuntes, bibliografía) para que sus propuestas sigan
      la terminología y el alcance del curso. Se procesa en el servidor, sin salir de él. No subas
      datos de estudiantes.
    </p>
    <div class="flex items-end gap-3">
      <div class="flex-1">
        <label class="etiqueta"
          >Título (opcional; si lo dejas vacío se usa el nombre del archivo)</label
        >
        <input
          v-model="titulo"
          class="campo"
          maxlength="200"
          placeholder="Apuntes de arreglos, unidad 3"
        />
      </div>
      <button class="btn" :disabled="subiendo" @click="subir">
        {{ subiendo ? 'Subiendo…' : 'Elegir archivo y subir' }}
      </button>
    </div>
    <p class="mt-2 text-xs text-slate-500">PDF con texto (no escaneos), Markdown o texto plano.</p>
    <p v-if="aviso" class="mt-2 text-sm text-slate-600">{{ aviso }}</p>
    <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
  </section>

  <p v-if="!documentos.length" class="text-sm text-slate-500">Aún no hay material.</p>
  <table v-else class="tarjeta w-full text-sm">
    <thead class="text-left text-slate-500">
      <tr>
        <th class="py-1">Documento</th>
        <th>Tamaño</th>
        <th>Estado</th>
        <th>Fragmentos</th>
        <th />
      </tr>
    </thead>
    <tbody>
      <tr v-for="d in documentos" :key="d.id" class="border-t border-slate-100 align-top">
        <td class="py-2">
          {{ d.titulo }}
          <span class="block text-xs text-slate-400">{{ d.nombre_original }}</span>
        </td>
        <td>{{ tamano(d.bytes) }}</td>
        <td>
          <span
            :class="{
              'text-amber-700': d.estado === 'procesando',
              'text-green-700': d.estado === 'listo',
              'text-red-600': d.estado === 'error'
            }"
          >
            {{ ESTADO[d.estado] }}
          </span>
          <span v-if="d.error" class="block max-w-xs text-xs text-red-600">{{ d.error }}</span>
        </td>
        <td>{{ d.estado === 'listo' ? d.fragmentos : '—' }}</td>
        <td class="text-right">
          <button class="underline" @click="eliminar(d)">Eliminar</button>
        </td>
      </tr>
    </tbody>
  </table>
</template>
