<script setup lang="ts">
import { computed } from 'vue'
import type { InfoProcedimental, InfoSoporte } from '@shared/contracts'
import EditorMarkdown from '../EditorMarkdown.vue'

const props = defineProps<{ tipo: 'soporte' | 'procedimental' }>()
const e = defineModel<InfoSoporte | InfoProcedimental>({ required: true })

const opciones = computed(() =>
  props.tipo === 'soporte'
    ? [
        ['modelo_mental', 'Modelo mental'],
        ['sap', 'Enfoque sistemático de solución (SAP)'],
        ['explicacion', 'Explicación'],
        ['mapa_conceptual', 'Mapa conceptual'],
        ['guion_video', 'Guion de video']
      ]
    : [
        ['ficha_sintaxis', 'Ficha de sintaxis'],
        ['ejemplo_isomorfico', 'Ejemplo isomórfico'],
        ['guia_preguntas', 'Guía de preguntas'],
        ['protocolo_verbal', 'Protocolo verbal']
      ]
)
</script>

<template>
  <div class="grid grid-cols-3 gap-4">
    <div class="col-span-2"><label class="etiqueta">Título</label><input v-model="e.titulo" class="campo" /></div>
    <div>
      <label class="etiqueta">Tipo</label>
      <select v-model="e.tipo" class="campo">
        <option v-for="[valor, texto] in opciones" :key="valor" :value="valor">{{ texto }}</option>
      </select>
    </div>
    <EditorMarkdown
      v-model="e.cuerpo_md"
      :etiqueta="tipo === 'soporte' ? 'Contenido (se muestra antes de la clase)' : 'Contenido (ayuda justo a tiempo en la tarea)'"
      alto="260px"
      class="col-span-3"
    />
  </div>
</template>
