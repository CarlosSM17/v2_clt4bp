<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import type { Usuario } from '@shared/tipos'
import { api } from '../lib/api'

const instructores = ref<Usuario[]>([])
const nuevo = reactive({ name: '', email: '', institucion: '' })
const error = ref('')
const aviso = ref('')

async function cargar(): Promise<void> {
  instructores.value = await api<Usuario[]>('GET', '/admin/instructors')
}

async function invitar(): Promise<void> {
  error.value = ''
  try {
    await api('POST', '/admin/instructors', nuevo)
    aviso.value = `Invitación enviada a ${nuevo.email}. Si el correo no le llega, usa «Copiar enlace de invitación».`
    Object.assign(nuevo, { name: '', email: '', institucion: '' })
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function accion(
  u: Usuario,
  accion: 'suspender' | 'reactivar' | 'reenviar_invitacion'
): Promise<void> {
  error.value = ''
  try {
    await api('PATCH', `/admin/instructors/${u.id}`, { accion })
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

// El enlace para definir la contraseña, para entregarlo por otro medio cuando el correo no sale (plataforma en
// Railway, plan Hobby). Da acceso a esa cuenta: se envía solo al instructor
const enlaceVisible = ref('')

async function copiarEnlace(u: Usuario): Promise<void> {
  error.value = ''
  aviso.value = ''
  enlaceVisible.value = ''
  try {
    const r = await api<{ enlace: string; vence_at: string }>(
      'POST',
      `/admin/instructors/${u.id}/enlace-invitacion`
    )
    const vence = new Date(r.vence_at).toLocaleString('es-MX', {
      dateStyle: 'medium',
      timeStyle: 'short'
    })
    try {
      await navigator.clipboard.writeText(r.enlace)
      aviso.value = `Enlace de ${u.name} copiado; vence el ${vence}. Envíaselo solo a esa persona.`
    } catch {
      enlaceVisible.value = r.enlace // sin portapapeles: se muestra para copiarlo a mano
      aviso.value = `Enlace de ${u.name} (vence el ${vence}). Cópialo y envíaselo solo a esa persona:`
    }
  } catch (e) {
    error.value = (e as Error).message
  }
}

onMounted(cargar)
</script>

<template>
  <h1 class="mb-6 text-2xl font-semibold">Instructores</h1>

  <form class="tarjeta mb-6 grid grid-cols-3 gap-4" @submit.prevent="invitar">
    <div>
      <label class="etiqueta">Nombre</label>
      <input v-model="nuevo.name" class="campo" required />
    </div>
    <div>
      <label class="etiqueta">Correo</label>
      <input v-model="nuevo.email" class="campo" type="email" required />
    </div>
    <div>
      <label class="etiqueta">Institución</label>
      <input v-model="nuevo.institucion" class="campo" />
    </div>
    <div class="col-span-3 flex items-center gap-4">
      <button class="btn">Invitar</button>
      <span class="text-sm text-green-700">{{ aviso }}</span>
    </div>
  </form>
  <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>
  <input
    v-if="enlaceVisible"
    :value="enlaceVisible"
    class="campo mb-4 w-full font-mono text-xs"
    readonly
    @focus="($event.target as HTMLInputElement).select()"
  />

  <table class="tarjeta w-full text-sm">
    <thead class="text-left text-slate-500">
      <tr>
        <th class="py-1">Nombre</th>
        <th>Correo</th>
        <th>Estado</th>
        <th />
      </tr>
    </thead>
    <tbody>
      <tr v-for="u in instructores" :key="u.id" class="border-t border-slate-100">
        <td class="py-2">{{ u.name }}</td>
        <td>{{ u.email }}</td>
        <td>
          <span v-if="u.suspendido" class="text-red-600">Suspendido</span>
          <span v-else-if="!u.invitacion_aceptada" class="text-amber-700"
            >Invitación pendiente</span
          >
          <span v-else-if="!u.dos_pasos" class="text-amber-700">Sin verificación en dos pasos</span>
          <span v-else class="text-green-700">Activo</span>
        </td>
        <td class="space-x-3 text-right">
          <button v-if="!u.invitacion_aceptada" class="underline" @click="copiarEnlace(u)">
            Copiar enlace de invitación
          </button>
          <button
            v-if="!u.invitacion_aceptada"
            class="underline"
            @click="accion(u, 'reenviar_invitacion')"
          >
            Reenviar
          </button>
          <button
            v-if="!u.suspendido"
            class="text-red-600 underline"
            @click="accion(u, 'suspender')"
          >
            Suspender
          </button>
          <button v-else class="underline" @click="accion(u, 'reactivar')">Reactivar</button>
        </td>
      </tr>
    </tbody>
  </table>
</template>
