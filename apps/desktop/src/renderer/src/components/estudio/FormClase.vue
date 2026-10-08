<script setup lang="ts">
import { computed } from 'vue'
import type { ClaseTareas, Objetivo } from '@shared/contracts'
import { useDiseno } from '../../stores/diseno'
import EditorMarkdown from '../EditorMarkdown.vue'

const c = defineModel<ClaseTareas>({ required: true })
const diseno = useDiseno()
const objetivos = computed(() => diseno.deTipo('objetivo').map((e) => e.contenido as Objetivo))
// Campo opcional del contrato: vacío no se guarda
const ficha = computed({
  get: () => c.value.ficha_md ?? '',
  set: (v: string) => {
    if (v.trim()) c.value.ficha_md = v
    else delete c.value.ficha_md
  }
})
</script>

<template>
  <div class="grid grid-cols-4 gap-4">
    <div class="col-span-3"><label class="etiqueta">Título</label><input v-model="c.titulo" class="campo" /></div>
    <div><label class="etiqueta">Orden</label><input v-model.number="c.orden" type="number" min="1" class="campo" /></div>
    <div class="col-span-4">
      <label class="etiqueta">¿Qué la hace más compleja que la clase anterior?</label>
      <textarea v-model="c.descripcion_complejidad" class="campo h-16" />
    </div>
    <fieldset class="col-span-4">
      <legend class="etiqueta">Objetivos que trabaja</legend>
      <p v-if="!objetivos.length" class="text-sm text-slate-500">Primero crea los objetivos del curso.</p>
      <label v-for="o in objetivos" :key="o.uid" class="flex items-start gap-2 text-sm">
        <input v-model="c.objetivos" type="checkbox" :value="o.codigo" class="mt-1" />
        <span><b>{{ o.codigo }}</b> {{ o.descripcion }}</span>
      </label>
    </fieldset>
    <!-- Solo para el instructor: el aula no la muestra (mapa de ruta, ADR 0007) -->
    <EditorMarkdown v-model="ficha" etiqueta="Ficha de diseño instruccional (solo la ves tú)" class="col-span-4" />
  </div>
</template>
