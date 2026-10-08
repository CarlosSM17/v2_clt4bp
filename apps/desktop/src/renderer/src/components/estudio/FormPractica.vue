<script setup lang="ts">
import type { PracticaParcial } from '@shared/contracts'
import EditorCodigo from '../EditorCodigo.vue'
import EditorMarkdown from '../EditorMarkdown.vue'
import EditorCasos from './EditorCasos.vue'

const p = defineModel<PracticaParcial>({ required: true })

function agregar(): void {
  p.value.ejercicios.push({ enunciado_md: '', solucion: '', casos_prueba: [{ entrada: '', salida_esperada: '', oculto: false }] })
}
</script>

<template>
  <div class="space-y-4">
    <div class="grid grid-cols-4 gap-4">
      <div class="col-span-3">
        <label class="etiqueta">Subhabilidad que se automatiza</label>
        <input v-model="p.habilidad" class="campo" placeholder="Declarar y recorrer arreglos" />
      </div>
      <div>
        <label class="etiqueta">Lenguaje</label>
        <select v-model="p.lenguaje" class="campo">
          <option value="c">C</option>
          <option value="cpp">C++</option>
          <option value="python">Python</option>
        </select>
      </div>
    </div>
    <div v-for="(ej, i) in p.ejercicios" :key="i" class="tarjeta space-y-3">
      <div class="flex justify-between">
        <p class="font-medium">Ejercicio {{ i + 1 }}</p>
        <button type="button" class="text-xs text-red-600" @click="p.ejercicios.splice(i, 1)">Quitar</button>
      </div>
      <EditorMarkdown v-model="ej.enunciado_md" etiqueta="Enunciado" alto="100px" />
      <div><p class="etiqueta">Solución</p><EditorCodigo v-model="ej.solucion" :lenguaje="p.lenguaje" alto="140px" /></div>
      <EditorCasos v-model="ej.casos_prueba" />
    </div>
    <button type="button" class="btn-sec" @click="agregar">+ Ejercicio</button>
  </div>
</template>
