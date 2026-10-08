<script setup lang="ts">
import type { CasoPrueba } from '@shared/contracts'

const casos = defineModel<CasoPrueba[]>({ required: true })
</script>

<template>
  <div class="space-y-2">
    <p class="etiqueta">
      Casos de prueba <span class="font-normal text-slate-500">(los ocultos no los ve el estudiante)</span>
    </p>
    <div v-for="(c, i) in casos" :key="i" class="grid grid-cols-[1fr_1fr_auto_auto] items-start gap-2">
      <textarea v-model="c.entrada" class="campo h-16 font-mono" placeholder="Entrada (stdin)" />
      <textarea v-model="c.salida_esperada" class="campo h-16 font-mono" placeholder="Salida esperada" />
      <label class="flex items-center gap-1 pt-2 text-xs"><input v-model="c.oculto" type="checkbox" /> Oculto</label>
      <button type="button" class="pt-2 text-xs text-red-600" @click="casos.splice(i, 1)">Quitar</button>
    </div>
    <button type="button" class="btn-sec" @click="casos.push({ entrada: '', salida_esperada: '', oculto: casos.length > 0 })">
      + Caso
    </button>
  </div>
</template>
