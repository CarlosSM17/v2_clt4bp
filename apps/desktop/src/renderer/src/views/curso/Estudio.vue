<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { Curso } from '@shared/tipos'
import { api } from '../../lib/api'
import { useDiseno } from '../../stores/diseno'
import PestanasCurso from '../../components/PestanasCurso.vue'
import ArbolCurso from '../../components/estudio/ArbolCurso.vue'
import EditorElemento from '../../components/estudio/EditorElemento.vue'
import PanelHallazgos from '../../components/estudio/PanelHallazgos.vue'
import VistaPrevia from '../../components/estudio/VistaPrevia.vue'
import PanelAgente from '../../components/estudio/PanelAgente.vue'

const props = defineProps<{ id: string }>()
const estudio = useDiseno()
const curso = ref<Curso | null>(null)

onMounted(async () => {
  curso.value = await api<Curso>('GET', `/courses/${props.id}`)
  await estudio.abrir(Number(props.id))
})
watch(
  () => props.id,
  (id) => estudio.abrir(Number(id))
)

// Clase de la selección actual (la propia clase, o la de su tarea/soporte/ayuda)
const claseActual = computed(() => {
  let e = estudio.actual
  while (e && e.tipo !== 'clase') e = e.padre_uid ? (estudio.porUid.get(e.padre_uid) ?? null) : null
  return e?.uid ?? null
})
const previa = ref(false)
const asistente = ref(false)
const arbolVisible = ref(true)
// Con el asistente abierto el editor perdería el ancho: en pantallas de laptop el árbol se oculta solo
watch(asistente, (abierto) => {
  if (abierto && window.innerWidth < 1600) arbolVisible.value = false
  if (!abierto) arbolVisible.value = true
})
</script>

<template>
  <h1 class="mb-2 text-2xl font-semibold">{{ curso?.titulo }}</h1>
  <PestanasCurso :id="id" />

  <!-- Etapa 6: la revisión de resultados pidió iterar -->
  <p v-if="curso?.iteracion" class="mt-2 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">
    Iteración {{ curso.iteracion.numero + 1 }}: la revisión de resultados pidió regresar a la
    {{
      curso.iteracion.regresar_a === 'fase1'
        ? 'Fase 1 (objetivos y evaluación)'
        : 'Fase 2 (diseño del material)'
    }}. Notas: {{ curso.iteracion.notas }}
  </p>

  <div class="mb-4 flex flex-wrap items-center gap-3 rounded-md bg-slate-50 p-2 text-sm">
    <span v-if="estudio.sincronizando" class="text-slate-500">Sincronizando…</span>
    <span v-else-if="estudio.sync?.sinConexion" class="text-amber-600"
      >Sin conexión: trabajas en local</span
    >
    <span v-else-if="estudio.sync" class="text-slate-600">
      Última sincronización:
      {{ estudio.sync.ultima ? new Date(estudio.sync.ultima).toLocaleTimeString() : '—' }} ·
      {{ estudio.sync.subidos }} subidos · {{ estudio.sync.bajados }} bajados
    </span>
    <span v-if="estudio.pendientes" class="text-amber-600"
      >{{ estudio.pendientes }} sin sincronizar</span
    >
    <button class="btn-sec py-0.5" :disabled="estudio.sincronizando" @click="estudio.sincronizar">
      Sincronizar ahora
    </button>
    <button class="btn-sec" :disabled="!claseActual" @click="previa = true">Vista previa</button>
    <button class="btn-sec" :class="{ 'bg-indigo-50': asistente }" @click="asistente = !asistente">
      Asistente IA
    </button>
    <button class="btn" :disabled="estudio.verificando" @click="estudio.verificar()">
      {{ estudio.verificando ? 'Verificando…' : 'Verificar CLT4BP' }}
    </button>
    <span v-if="estudio.error" class="text-red-600">{{ estudio.error }}</span>
  </div>

  <div
    v-if="estudio.conflictos.length"
    class="mb-4 space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm"
  >
    <p class="font-medium text-amber-800">
      {{ estudio.conflictos.length }} elemento(s) cambiaron en el servidor y en esta computadora a
      la vez:
    </p>
    <div
      v-for="c in estudio.conflictos"
      :key="c.uid"
      class="flex items-center justify-between gap-2"
    >
      <span class="font-mono text-xs">{{ c.uid }} ({{ c.tipo }})</span>
      <span class="flex gap-2">
        <button class="btn-sec py-0.5" @click="estudio.resolver(c.uid, 'local')">
          Conservar lo mío
        </button>
        <button class="btn-sec py-0.5" @click="estudio.resolver(c.uid, 'servidor')">
          Usar el del servidor
        </button>
      </span>
    </div>
  </div>

  <!-- Ocupa el alto restante de la ventana (antes: 65 % fijo, que en una laptop dejaba el editor sin espacio) -->
  <div
    class="tarjeta flex overflow-hidden p-0"
    style="height: calc(100vh - 12.5rem); min-height: 26rem"
  >
    <div
      v-if="arbolVisible"
      class="flex w-64 shrink-0 flex-col border-r border-slate-200 bg-slate-50"
    >
      <div class="flex justify-end px-2 pt-1">
        <button
          class="text-xs text-slate-500 hover:text-slate-800"
          title="Ocultar el árbol"
          @click="arbolVisible = false"
        >
          « ocultar
        </button>
      </div>
      <div class="min-h-0 flex-1 overflow-y-auto px-3 pb-3">
        <ArbolCurso v-if="curso" :lenguaje="curso.lenguaje" :semaforo="estudio.informe?.semaforo" />
      </div>
    </div>
    <button
      v-else
      class="w-7 shrink-0 border-r border-slate-200 bg-slate-50 text-xs text-slate-500 hover:bg-slate-100"
      title="Mostrar el árbol del curso"
      @click="arbolVisible = true"
    >
      <span class="block [writing-mode:vertical-rl]">» Árbol del curso</span>
    </button>
    <main class="flex min-w-0 flex-1 flex-col bg-slate-50">
      <div class="min-h-0 flex-1">
        <EditorElemento
          v-if="estudio.actual"
          :key="estudio.actual.uid"
          :elemento="estudio.actual"
        />
        <p v-else class="p-8 text-slate-500">
          Elige un elemento del árbol o crea uno nuevo con «+».
        </p>
      </div>
      <PanelHallazgos />
    </main>
    <aside v-if="asistente" class="w-80 shrink-0 border-l border-slate-200 bg-white">
      <PanelAgente :lenguaje="curso?.lenguaje ?? 'c'" />
    </aside>
  </div>

  <VistaPrevia v-if="previa && claseActual" :clase-uid="claseActual" @cerrar="previa = false" />
</template>
