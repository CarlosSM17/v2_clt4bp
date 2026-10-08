<script setup lang="ts">
import { computed } from 'vue'
import { useDiseno } from '../../stores/diseno'

const estudio = useDiseno()
const ICONO = { error: ' ⛔ ', advertencia: '⚠', info: ' ℹ ' } as const
const ordenados = computed(() =>
  [...(estudio.informe?.hallazgos ?? [])].sort(
    (a, b) => ['error', 'advertencia', 'info'].indexOf(a.nivel) - ['error', 'advertencia', 'info'].indexOf(b.nivel)
  )
)
</script>

<template>
  <div v-if="estudio.informe" class="max-h-56 overflow-y-auto border-t border-slate-200 bg-white px-4 py-2 text-sm">
    <p class="mb-1 font-medium">
      Verificador CLT4BP: {{ estudio.informe.errores }} errores · {{ estudio.informe.advertencias }} advertencias
      <span v-if="!estudio.informe.hallazgos.length" class="text-green-700">· todo en verde</span>
    </p>
    <button
      v-for="(h, i) in ordenados"
      :key="i"
      class="block w-full rounded px-2 py-0.5 text-left hover:bg-slate-100"
      @click="(estudio.seleccionado = h.elemento_uid), (estudio.grupoActivo = h.grupo)"
    >
      {{ ICONO[h.nivel] }} <span class="font-mono text-xs text-slate-500">{{ h.elemento_uid }}</span>
      <span v-if="h.grupo" class="text-xs text-indigo-600"> [{{ estudio.grupos.find((g) => g.clave === h.grupo)?.nombre ?? h.grupo }}]</span>
      {{ h.mensaje }}
    </button>
  </div>
</template>
