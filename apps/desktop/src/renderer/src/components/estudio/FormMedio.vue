<script setup lang="ts">
import { ref } from 'vue'
import type { Medio } from '@shared/contracts'
import { useDiseno } from '../../stores/diseno'

const m = defineModel<Medio>({ required: true })
const estudio = useDiseno()
const aviso = ref('')

async function abrir(): Promise<void> {
  aviso.value = await window.consola.medios.abrir(estudio.cursoId, m.value.uid)
}
</script>

<template>
  <div class="space-y-4">
    <div class="grid grid-cols-3 gap-4">
      <div class="col-span-2"><label class="etiqueta">Título</label><input v-model="m.titulo" class="campo" /></div>
      <div>
        <label class="etiqueta">Tipo</label>
        <select v-model="m.tipo" class="campo">
          <option value="protocolo_verbal">Protocolo verbal</option>
          <option value="video">Video</option>
          <option value="audio">Audio</option>
          <option value="imagen">Imagen</option>
        </select>
      </div>
    </div>
    <div class="flex items-center gap-3 text-sm">
      <button class="btn-sec" @click="abrir">Reproducir</button>
      <span class="text-slate-500">
        Duración: {{ m.duracion_s ?? '—' }} s · Cítalo en Markdown como
        <code>![{{ m.titulo }}](media:{{ m.uid }})</code>
      </span>
      <span v-if="aviso" class="text-red-600">{{ aviso }}</span>
    </div>
    <div>
      <p class="etiqueta">Segmentos</p>
      <div v-for="(s, i) in m.segmentos" :key="i" class="mb-1 grid grid-cols-[6rem_1fr_auto] gap-2">
        <input v-model.number="s.inicio_s" type="number" min="0" class="campo" />
        <input v-model="s.etiqueta" class="campo" />
        <button class="text-xs text-red-600" @click="m.segmentos.splice(i, 1)">Quitar</button>
      </div>
      <button class="btn-sec" @click="m.segmentos.push({ inicio_s: 0, etiqueta: '' })">+ Segmento</button>
    </div>
    <div>
      <label class="etiqueta">Transcripción (editable; en la Etapa 4 el agente puede proponerla)</label>
      <textarea
        :value="m.transcripcion ?? ''"
        class="campo h-40"
        @input="m.transcripcion = ($event.target as HTMLTextAreaElement).value || null"
      />
    </div>
  </div>
</template>
