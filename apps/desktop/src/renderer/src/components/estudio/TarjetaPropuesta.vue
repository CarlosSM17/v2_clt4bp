<script setup lang="ts">
import { computed } from 'vue'
import type { ElementoPropuesto } from '@shared/agente'
import VistaMarkdown from '../VistaMarkdown.vue'

// Muestra un elemento (propuesto o actual) con sus campos importantes, no como JSON crudo
const props = defineProps<{ elemento: ElementoPropuesto }>()
const c = computed(() => props.elemento.contenido as unknown as Record<string, unknown>)
const texto = (campo: string): string => String(c.value[campo] ?? '')
const casos = computed(() => (c.value.casos_prueba as { oculto: boolean }[] | undefined) ?? [])
</script>

<template>
  <div class="space-y-2 text-sm">
    <p class="font-medium">
      {{ texto('titulo') || texto('codigo') || elemento.contenido.uid }}
      <span class="ml-1 rounded bg-slate-100 px-1.5 text-xs text-slate-600">{{ elemento.tipo }}</span>
      <span v-if="c.nivel_apoyo" class="ml-1 rounded bg-indigo-50 px-1.5 text-xs text-indigo-700">{{ c.nivel_apoyo }}</span>
    </p>
    <p v-if="c.descripcion">{{ texto('descripcion') }}</p>
    <p v-if="c.descripcion_complejidad" class="text-slate-600">{{ texto('descripcion_complejidad') }}</p>
    <VistaMarkdown v-if="c.enunciado_md" :md="texto('enunciado_md')" />
    <VistaMarkdown v-if="c.cuerpo_md" :md="texto('cuerpo_md')" />
    <pre v-if="c.codigo_inicial" class="overflow-x-auto rounded bg-slate-900 p-2 text-xs text-slate-100">{{ texto('codigo_inicial') }}</pre>
    <details v-if="c.solucion">
      <summary class="cursor-pointer text-slate-600">Solución de referencia · {{ casos.length }} casos ({{ casos.filter((x) => x.oculto).length }} ocultos)</summary>
      <pre class="overflow-x-auto rounded bg-slate-900 p-2 text-xs text-slate-100">{{ texto('solucion') }}</pre>
    </details>
    <template v-if="elemento.tipo === 'variante'">
      <p class="text-slate-600">Grupo {{ c.grupo_clave }} · cambia: {{ Object.keys((c.cambios as object) ?? {}).join(', ') }}</p>
      <pre class="overflow-x-auto rounded bg-slate-50 p-2 text-xs">{{ JSON.stringify(c.cambios, null, 2) }}</pre>
    </template>
    <details v-if="c.diseno" class="text-slate-600">
      <summary class="cursor-pointer">Decisiones de diseño</summary>
      <pre class="overflow-x-auto whitespace-pre-wrap rounded bg-slate-50 p-2 text-xs">{{ JSON.stringify(c.diseno, null, 2) }}</pre>
    </details>
  </div>
</template>
