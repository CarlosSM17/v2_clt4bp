<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import type { Curso } from '@shared/tipos'
import { api } from '../lib/api'
import { useSesion } from '../stores/sesion'

const sesion = useSesion()
const cursos = ref<Curso[]>([])
const cargando = ref(true)
const error = ref('')
const mostrarForm = ref(false)
const nuevo = reactive({
  titulo: '',
  lenguaje: 'c',
  nivel_educativo: 'preparatoria',
  inicia_el: '',
  termina_el: ''
})

async function cargar(): Promise<void> {
  cargando.value = true
  try {
    cursos.value = await api<Curso[]>('GET', '/courses')
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    cargando.value = false
  }
}

async function crear(): Promise<void> {
  error.value = ''
  try {
    await api<Curso>('POST', '/courses', {
      ...nuevo,
      inicia_el: nuevo.inicia_el || null,
      termina_el: nuevo.termina_el || null
    })
    mostrarForm.value = false
    Object.assign(nuevo, { titulo: '', inicia_el: '', termina_el: '' })
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

onMounted(cargar)
</script>

<template>
  <div class="mb-6 flex items-center justify-between">
    <h1 class="text-2xl font-semibold">Cursos</h1>
    <button v-if="sesion.esInstructor" class="btn" @click="mostrarForm = !mostrarForm">
      Nuevo curso
    </button>
  </div>

  <form v-if="mostrarForm" class="tarjeta mb-6 grid grid-cols-2 gap-4" @submit.prevent="crear">
    <div class="col-span-2">
      <label class="etiqueta">Título</label>
      <input v-model="nuevo.titulo" class="campo" required maxlength="160" />
    </div>
    <div>
      <label class="etiqueta">Lenguaje</label>
      <select v-model="nuevo.lenguaje" class="campo">
        <option value="c">C</option>
        <option value="cpp">C++</option>
        <option value="python">Python</option>
      </select>
    </div>
    <div>
      <label class="etiqueta">Nivel educativo</label>
      <select v-model="nuevo.nivel_educativo" class="campo">
        <option value="secundaria">Secundaria</option>
        <option value="preparatoria">Preparatoria</option>
        <option value="universidad">Universidad</option>
      </select>
    </div>
    <div>
      <label class="etiqueta">Inicia</label>
      <input v-model="nuevo.inicia_el" type="date" class="campo" />
    </div>
    <div>
      <label class="etiqueta">Termina</label>
      <input v-model="nuevo.termina_el" type="date" class="campo" />
    </div>
    <div class="col-span-2 flex gap-2">
      <button class="btn">Crear</button>
      <button type="button" class="btn-sec" @click="mostrarForm = false">Cancelar</button>
    </div>
  </form>

  <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>
  <p v-if="cargando" class="text-sm text-slate-500">Cargando…</p>
  <p v-else-if="!cursos.length" class="text-sm text-slate-500">Aún no hay cursos.</p>

  <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <RouterLink
      v-for="c in cursos"
      :key="c.id"
      :to="{ name: 'curso', params: { id: c.id } }"
      class="tarjeta block hover:border-indigo-300"
    >
      <p class="font-medium">{{ c.titulo }}</p>
      <p class="mt-1 text-sm text-slate-500">
        {{ c.lenguaje.toUpperCase() }} · {{ c.estado }} · código
        <span class="font-mono">{{ c.codigo_inscripcion }}</span>
      </p>
      <p class="mt-2 text-sm">
        {{ c.inscritos ?? 0 }} inscritos
        <span v-if="c.pendientes" class="ml-2 rounded bg-amber-100 px-2 py-0.5 text-amber-800">
          {{ c.pendientes }} solicitudes
        </span>
      </p>
    </RouterLink>
  </div>
</template>
