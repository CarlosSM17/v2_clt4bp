<script setup lang="ts">
import type { Objetivo } from '@shared/contracts'

const o = defineModel<Objetivo>({ required: true })
const metodos: { valor: Objetivo['evaluacion'][number]; texto: string }[] = [
  { valor: 'recall', texto: 'Recuerdo (recall)' },
  { valor: 'comprension', texto: 'Comprensión' },
  { valor: 'practica', texto: 'Práctica de programación' },
  { valor: 'cis_imms', texto: 'CIS/IMMS (motivación)' },
  { valor: 'mslq', texto: 'MSLQ' },
  { valor: 'cs', texto: 'Carga cognitiva (CS)' }
]
</script>

<template>
  <div class="grid grid-cols-4 gap-4">
    <div><label class="etiqueta">Código</label><input v-model="o.codigo" class="campo" /></div>
    <div>
      <label class="etiqueta">Tipo</label>
      <select v-model="o.tipo" class="campo">
        <option value="conocimiento">Conocimiento</option>
        <option value="habilidad">Habilidad</option>
        <option value="actitud">Actitud</option>
      </select>
    </div>
    <div><label class="etiqueta">Orden</label><input v-model.number="o.orden" type="number" min="1" class="campo" /></div>
    <div class="col-span-4">
      <label class="etiqueta">Descripción (verbo observable + condición + criterio)</label>
      <textarea v-model="o.descripcion" class="campo h-20" placeholder="Escribir programas en C que… dado… con…" />
    </div>
    <fieldset class="col-span-4">
      <legend class="etiqueta">Se evaluará con</legend>
      <label v-for="m in metodos" :key="m.valor" class="mr-4 inline-flex items-center gap-1 text-sm">
        <input v-model="o.evaluacion" type="checkbox" :value="m.valor" /> {{ m.texto }}
      </label>
    </fieldset>
  </div>
</template>
