<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { Curso, Inscripcion } from '@shared/tipos'
import { api } from '../lib/api'
import PestanasCurso from '../components/PestanasCurso.vue'

const props = defineProps<{ id: string }>()

const curso = ref<Curso | null>(null)
const inscripciones = ref<Inscripcion[]>([])
const correos = ref('')
const mensaje = ref('')
const error = ref('')

const pendientes = computed(() => inscripciones.value.filter((i) => i.estado === 'solicitud'))
const activas = computed(() =>
  inscripciones.value.filter((i) => !['solicitud', 'rechazada'].includes(i.estado))
)

async function cargar(): Promise<void> {
  try {
    curso.value = await api<Curso>('GET', `/courses/${props.id}`)
    inscripciones.value = await api<Inscripcion[]>('GET', `/courses/${props.id}/enrollments`)
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function cambiar(
  inscripcion: Inscripcion,
  accion: 'aprobar' | 'rechazar' | 'baja'
): Promise<void> {
  if (accion === 'baja' && !confirm(`¿Dar de baja a ${inscripcion.estudiante.name}?`)) return
  try {
    await api('PATCH', `/enrollments/${inscripcion.id}`, { accion })
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

/** Acepta correos separados por comas, espacios o saltos de línea (útil al pegar una columna de Excel). */
async function inscribirPorCorreo(): Promise<void> {
  const emails = correos.value.split(/[\s,;]+/).filter((c) => c.includes('@'))
  if (!emails.length) return
  try {
    const r = await api<{ inscritos: string[]; invitados: string[]; omitidos: string[] }>(
      'POST',
      `/courses/${props.id}/enrollments`,
      { emails }
    )
    mensaje.value = `Inscritos: ${r.inscritos.length} · Invitados a registrarse: ${r.invitados.length} · Omitidos: ${r.omitidos.length}`
    correos.value = ''
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

onMounted(cargar)
</script>

<template>
  <RouterLink to="/cursos" class="text-sm text-indigo-600">← Cursos</RouterLink>
  <h1 class="mt-2 text-2xl font-semibold">{{ curso?.titulo }}</h1>
  <p v-if="curso" class="mb-6 text-sm text-slate-500">
    Código de inscripción:
    <span class="font-mono text-base text-slate-900">{{ curso.codigo_inscripcion }}</span>
  </p>
  <PestanasCurso :id="id" />
  <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>

  <section class="tarjeta mb-6">
    <h2 class="mb-3 font-medium">Solicitudes pendientes ({{ pendientes.length }})</h2>
    <p v-if="!pendientes.length" class="text-sm text-slate-500">No hay solicitudes.</p>
    <ul class="divide-y divide-slate-100">
      <li
        v-for="i in pendientes"
        :key="i.id"
        class="flex items-center justify-between py-2 text-sm"
      >
        <span>{{ i.estudiante.name }} · {{ i.estudiante.email }}</span>
        <span class="flex gap-2">
          <button class="btn" @click="cambiar(i, 'aprobar')">Aprobar</button>
          <button class="btn-sec" @click="cambiar(i, 'rechazar')">Rechazar</button>
        </span>
      </li>
    </ul>
  </section>

  <section class="tarjeta mb-6">
    <h2 class="mb-3 font-medium">Inscribir por correo</h2>
    <textarea
      v-model="correos"
      class="campo h-24"
      placeholder="Pega aquí los correos (uno por línea o separados por comas)"
    />
    <div class="mt-2 flex items-center gap-4">
      <button class="btn" @click="inscribirPorCorreo">Inscribir</button>
      <span class="text-sm text-slate-600">{{ mensaje }}</span>
    </div>
  </section>

  <section class="tarjeta">
    <h2 class="mb-3 font-medium">Estudiantes ({{ activas.length }})</h2>
    <table class="w-full text-sm">
      <thead class="text-left text-slate-500">
        <tr>
          <th class="py-1">Nombre</th>
          <th>Seudónimo</th>
          <th>Estado</th>
          <th />
        </tr>
      </thead>
      <tbody>
        <tr v-for="i in activas" :key="i.id" class="border-t border-slate-100">
          <td class="py-2">{{ i.estudiante.name }}</td>
          <td class="font-mono">{{ i.seudonimo }}</td>
          <td>{{ i.estado }}</td>
          <td class="text-right">
            <button
              v-if="i.estado !== 'baja'"
              class="text-red-600 underline"
              @click="cambiar(i, 'baja')"
            >
              Dar de baja
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
