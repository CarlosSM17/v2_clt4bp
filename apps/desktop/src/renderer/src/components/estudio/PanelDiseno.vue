<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Arcs, Diseno, EfectoId } from '@shared/contracts'
import catalogo from '@shared/catalogo/efectos.json'
import { api } from '../../lib/api'
import { useDiseno } from '../../stores/diseno'

defineProps<{ conArcs: boolean }>()
const d = defineModel<Diseno>('diseno', { required: true })
const arcs = defineModel<Arcs>('arcs')
const estudio = useDiseno()

const GRUPOS = [
  { clave: 'nuevos_conocimientos', titulo: 'Nuevos conocimientos' },
  { clave: 'ambos', titulo: 'Ambos' },
  { clave: 'reforzamiento', titulo: 'Reforzamiento' }
]

// Paso 3: efectos sugeridos por las reglas para el grupo que se está editando (requiere conexión)
const sugeridos = ref<Record<string, string[]>>({})
watch(
  () => [estudio.cursoId, estudio.grupoActivo, d.value.interactividad],
  async () => {
    const q = new URLSearchParams({ interactividad: d.value.interactividad })
    if (estudio.grupoActivo) q.set('grupo', estudio.grupoActivo)
    try {
      const r = await api<{ efectos: { id: string; fundamentos: string[] }[] }>('GET', `/courses/${estudio.cursoId}/preselection?${q}`)
      sugeridos.value = Object.fromEntries(r.efectos.map((e) => [e.id, e.fundamentos]))
    } catch {
      sugeridos.value = {} // sin conexión: sin sugerencias
    }
  },
  { immediate: true }
)

const aplicado = computed(() => new Map(d.value.efectos.map((e) => [e.id, e])))

function alternar(id: EfectoId): void {
  const i = d.value.efectos.findIndex((e) => e.id === id)
  if (i === -1) d.value.efectos.push({ id, como: '' })
  else d.value.efectos.splice(i, 1)
}
</script>

<template>
  <div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="etiqueta">Paso CLT4BP</label>
        <input v-model.number="d.paso_clt4bp" type="number" min="1" max="10" class="campo" />
      </div>
      <div>
        <label class="etiqueta">Tiempo (min)</label>
        <input v-model.number="d.tiempo_estimado_min" type="number" min="1" class="campo" />
      </div>
      <div class="col-span-2">
        <label class="etiqueta">Interactividad de elementos</label>
        <select v-model="d.interactividad" class="campo">
          <option value="baja">Baja</option>
          <option value="media">Media</option>
          <option value="alta">Alta</option>
        </select>
      </div>
    </div>

    <div v-if="conArcs && arcs" class="space-y-2">
      <p class="font-medium">ARCS</p>
      <div v-for="campo in ['atencion', 'relevancia', 'confianza', 'satisfaccion'] as const" :key="campo">
        <label class="etiqueta capitalize">{{
          campo === 'atencion' ? 'Atención' : campo === 'satisfaccion' ? 'Satisfacción' : campo
        }}</label>
        <textarea v-model="arcs[campo]" class="campo h-14" />
      </div>
    </div>

    <div>
      <p class="font-medium">Efectos de la TCC aplicados</p>
      <p class="text-xs text-slate-500">★ = sugerido por las reglas para {{ estudio.grupoActivo ?? 'el curso' }}</p>
      <div v-for="g in GRUPOS" :key="g.clave" class="mt-2">
        <p class="text-xs uppercase tracking-wide text-slate-500">{{ g.titulo }}</p>
        <div v-for="ef in catalogo.filter((e) => e.grupo === g.clave)" :key="ef.id" class="py-0.5">
          <label class="flex items-center gap-2" :title="ef.definicion">
            <input type="checkbox" :checked="aplicado.has(ef.id as EfectoId)" @change="alternar(ef.id as EfectoId)" />
            <span>{{ ef.nombre }}</span>
            <span v-if="sugeridos[ef.id]" class="text-amber-500" :title="sugeridos[ef.id].join(' · ')">★</span>
          </label>
          <input
            v-if="aplicado.has(ef.id as EfectoId)"
            v-model="aplicado.get(ef.id as EfectoId)!.como"
            class="campo mt-1 text-xs"
            placeholder="¿Cómo se aplicó aquí?"
          />
        </div>
      </div>
    </div>

    <div>
      <label class="etiqueta">Justificación del diseño</label>
      <textarea v-model="d.justificacion" class="campo h-20" />
    </div>
    <div v-if="d.advertencias.length" class="rounded-md bg-amber-50 p-2 text-xs text-amber-800">
      <p v-for="(a, i) in d.advertencias" :key="i">⚠ {{ a }}</p>
    </div>
  </div>
</template>
