<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useSesion } from '../stores/sesion'

const router = useRouter()
const sesion = useSesion()

const email = ref('')
const password = ref('')
const code = ref('')
const pideCodigo = ref(false)
const error = ref('')
const enviando = ref(false)

async function entrar(): Promise<void> {
  error.value = ''
  enviando.value = true
  const r = await window.consola.sesion.login({
    email: email.value,
    password: password.value,
    code: pideCodigo.value ? code.value : undefined
  })
  enviando.value = false

  if (r.estado === 'ok') {
    sesion.usuario = r.usuario
    await router.push({ name: 'cursos' })
  } else if (r.estado === 'requiere_codigo') {
    pideCodigo.value = true
  } else {
    error.value = r.errors ? Object.values(r.errors).flat().join(' ') : r.message
  }
}
</script>

<template>
  <div class="flex h-screen items-center justify-center">
    <form class="tarjeta w-96 space-y-4" @submit.prevent="entrar">
      <h1 class="text-xl font-semibold">Consola del instructor</h1>
      <div>
        <label class="etiqueta" for="email">Correo</label>
        <input
          id="email"
          v-model="email"
          class="campo"
          type="email"
          required
          autofocus
          :disabled="pideCodigo"
        />
      </div>
      <div>
        <label class="etiqueta" for="password">Contraseña</label>
        <input
          id="password"
          v-model="password"
          class="campo"
          type="password"
          required
          :disabled="pideCodigo"
        />
      </div>
      <div v-if="pideCodigo">
        <label class="etiqueta" for="code">Código de verificación (6 dígitos)</label>
        <input
          id="code"
          v-model="code"
          class="campo tracking-widest"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          required
          autofocus
        />
      </div>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <button class="btn w-full justify-center" :disabled="enviando">
        {{ pideCodigo ? 'Verificar' : 'Entrar' }}
      </button>
    </form>
  </div>
</template>
