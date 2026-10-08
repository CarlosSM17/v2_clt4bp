<script setup lang="ts">
import { markdown } from '@codemirror/lang-markdown'
import { EditorView } from 'codemirror'
import { computed, ref } from 'vue'
import { Codemirror } from 'vue-codemirror'
import { api } from '../lib/api'
import { useDiseno } from '../stores/diseno'
import VistaMarkdown from './VistaMarkdown.vue'

defineProps<{ etiqueta: string; alto?: string }>()
const texto = defineModel<string>({ required: true })
const modo = ref<'editar' | 'ver'>('editar')
const extensiones = [markdown(), EditorView.lineWrapping]

// Plantillas listas para llenar: el formato de las trazas no se adivina (services/agent/app/multimedia.py)
const PLANTILLAS = {
  diagrama: [
    '```mermaid',
    'flowchart TD',
    '  A["inicio"] --> B{"¿condición?"}',
    '  B -- "sí" --> C["paso"] --> B',
    '  B -- "no" --> D["fin"]',
    '```'
  ],
  traza: [
    '```traza',
    'titulo: Nombre de la traza',
    'entrada: 3 10 20 30',
    '---',
    '(pega aquí el código y pulsa «Calcular pasos»: los pasos salen de ejecutarlo)',
    '```'
  ]
}
function insertar(tipo: keyof typeof PLANTILLAS): void {
  texto.value = [texto.value.trimEnd(), '', ...PLANTILLAS[tipo], ''].join('\n')
  modo.value = 'editar'
}

// «Calcular pasos»: el servidor ejecuta el código de cada traza (gdb) y devuelve sus pasos reales. Nadie los
// escribe a mano: una traza inventada no sigue el orden en que de verdad se ejecuta el programa
const estudio = useDiseno()
const TRAZA = /```traza[^\n]*\n([\s\S]*?)```/g
const hayTrazas = computed(() => texto.value.includes('```traza'))
const calculando = ref(false)
const avisoTraza = ref('')
async function calcularPasos(): Promise<void> {
  calculando.value = true
  avisoTraza.value = ''
  const errores: string[] = []
  let resultado = texto.value
  for (const m of Array.from(texto.value.matchAll(TRAZA))) {
    try {
      const r = await api<{ bloque: string }>('POST', `/courses/${estudio.cursoId}/trazas`, {
        bloque: m[1]
      })
      resultado = resultado.replace(m[1], r.bloque)
    } catch (e) {
      errores.push((e as Error).message)
    }
  }
  texto.value = resultado
  avisoTraza.value = errores.length
    ? errores.join(' ')
    : 'Pasos calculados al ejecutar el programa.'
  calculando.value = false
}
</script>

<template>
  <div>
    <div class="mb-1 flex items-center justify-between">
      <span class="etiqueta mb-0">{{ etiqueta }}</span>
      <div class="flex gap-1 text-xs">
        <button
          type="button"
          class="rounded px-2 py-0.5 text-indigo-700"
          title="Agrega un diagrama de flujo para editar"
          @click="insertar('diagrama')"
        >
          + Diagrama
        </button>
        <button
          type="button"
          class="rounded px-2 py-0.5 text-indigo-700"
          title="Agrega una traza de código paso a paso para editar"
          @click="insertar('traza')"
        >
          + Traza
        </button>
        <button
          v-if="hayTrazas"
          type="button"
          class="rounded px-2 py-0.5 text-indigo-700"
          title="Ejecuta el código de cada traza y escribe sus pasos reales"
          :disabled="calculando"
          @click="calcularPasos"
        >
          {{ calculando ? 'Calculando…' : 'Calcular pasos' }}
        </button>
        <button
          type="button"
          class="rounded px-2 py-0.5"
          :class="modo === 'editar' ? 'bg-slate-200' : ''"
          @click="modo = 'editar'"
        >
          Markdown
        </button>
        <button
          type="button"
          class="rounded px-2 py-0.5"
          :class="modo === 'ver' ? 'bg-slate-200' : ''"
          @click="modo = 'ver'"
        >
          Vista previa
        </button>
      </div>
    </div>
    <Codemirror
      v-if="modo === 'editar'"
      v-model="texto"
      :extensions="extensiones"
      :style="{ minHeight: alto ?? '160px', fontSize: '13px' }"
      class="overflow-hidden rounded-md border border-slate-300"
    />
    <VistaMarkdown
      v-else
      :md="texto"
      class="rounded-md border border-slate-200 bg-white p-3"
      :style="{ minHeight: alto ?? '160px' }"
    />
    <p v-if="avisoTraza" class="mt-1 text-xs text-indigo-700">{{ avisoTraza }}</p>
    <p class="mt-1 text-xs text-slate-500">
      Diagramas: bloque <code>```mermaid</code> (etiquetas entre comillas). Trazas paso a paso:
      bloque <code>```traza</code>. Código: <code>```c</code> o <code>```python</code>. Medios del
      curso: <code>![título](media:uid)</code>.
    </p>
  </div>
</template>
