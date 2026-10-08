<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSesion } from '../stores/sesion'

const sesion = useSesion()
const router = useRouter()
const ruta = useRoute()

// En el Estudio de diseño cada píxel de ancho cuenta: la barra lateral se reduce a una franja.
// Lo que elija el instructor con el botón se recuerda (por pantalla: Estudio o el resto)
const CLAVE = 'consola.marcoColapsado'
const elegido = ref<Record<string, boolean>>(leer())
const zona = computed(() => (ruta.name === 'curso-estudio' ? 'estudio' : 'resto'))
const colapsado = computed(() => elegido.value[zona.value] ?? zona.value === 'estudio')

function leer(): Record<string, boolean> {
  try {
    return JSON.parse(localStorage.getItem(CLAVE) ?? '{}')
  } catch {
    return {}
  }
}
function alternar(): void {
  elegido.value = { ...elegido.value, [zona.value]: !colapsado.value }
  try {
    localStorage.setItem(CLAVE, JSON.stringify(elegido.value))
  } catch {
    // sin almacenamiento local: solo dura esta sesión
  }
}

async function salir(): Promise<void> {
  await sesion.salir()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="flex h-screen">
    <aside
      class="flex shrink-0 flex-col border-r border-slate-200 bg-white transition-[width]"
      :class="colapsado ? 'w-14 items-center px-2 py-4' : 'w-56 p-4'"
    >
      <div class="mb-6 flex w-full items-center justify-between">
        <p v-if="!colapsado" class="text-lg font-semibold">CLT4BP</p>
        <button
          class="rounded px-1.5 py-0.5 text-slate-500 hover:bg-slate-100"
          :title="colapsado ? 'Mostrar el menú' : 'Ocultar el menú'"
          @click="alternar"
        >
          {{ colapsado ? '»' : '«' }}
        </button>
      </div>
      <nav class="flex w-full flex-col gap-1 text-sm">
        <RouterLink
          to="/cursos"
          class="rounded py-1.5 hover:bg-slate-100"
          :class="colapsado ? 'text-center' : 'px-2'"
          active-class="bg-slate-100 font-medium"
          title="Cursos"
        >
          {{ colapsado ? 'Cu' : 'Cursos' }}
        </RouterLink>
        <RouterLink
          v-if="sesion.esAdmin"
          to="/admin/instructores"
          class="rounded py-1.5 hover:bg-slate-100"
          :class="colapsado ? 'text-center' : 'px-2'"
          active-class="bg-slate-100 font-medium"
          title="Instructores"
        >
          {{ colapsado ? 'In' : 'Instructores' }}
        </RouterLink>
      </nav>
      <div v-if="!colapsado" class="mt-auto text-xs text-slate-500">
        <p class="truncate">{{ sesion.usuario?.name }}</p>
        <button class="mt-2 underline" @click="salir">Cerrar sesión</button>
      </div>
    </aside>
    <main class="min-w-0 flex-1 overflow-y-auto" :class="zona === 'estudio' ? 'p-4' : 'p-6'">
      <slot />
    </main>
  </div>
</template>
