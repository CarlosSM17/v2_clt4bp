import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { Usuario } from '@shared/tipos'

export const useSesion = defineStore('sesion', () => {
  const usuario = ref<Usuario | null>(null)
  const cargada = ref(false)

  const esAdmin = computed(() => usuario.value?.roles.includes('admin') ?? false)
  const esInstructor = computed(() => usuario.value?.roles.includes('instructor') ?? false)

  async function cargar(): Promise<void> {
    usuario.value = await window.consola.sesion.actual()
    cargada.value = true
  }

  async function salir(): Promise<void> {
    await window.consola.sesion.logout()
    usuario.value = null
  }

  return { usuario, cargada, esAdmin, esInstructor, cargar, salir }
})
