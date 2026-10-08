<script setup lang="ts">
import { computed, ref } from 'vue'
import type { ClaseTareas, InfoProcedimental, InfoSoporte, Tarea } from '@shared/contracts'
import { useDiseno } from '../../stores/diseno'
import EditorCodigo from '../EditorCodigo.vue'
import VistaMarkdown from '../VistaMarkdown.vue'

/** Cómo verá la clase un estudiante de cierto grupo (sin publicar nada). */
const props = defineProps<{ claseUid: string }>()
defineEmits<{ cerrar: [] }>()
const estudio = useDiseno()

const grupo = ref<string | null>(estudio.grupos[0]?.clave ?? null)
const clase = computed(() => estudio.porUid.get(props.claseUid)!.contenido as ClaseTareas)
const soporte = computed(() => estudio.deTipo('soporte', props.claseUid).map((s) => estudio.efectivo(s, grupo.value) as InfoSoporte))
const tareas = computed(() => estudio.deTipo('tarea', props.claseUid).map((t) => estudio.efectivo(t, grupo.value) as Tarea))
const ayudas = (uid: string): InfoProcedimental[] =>
  estudio.deTipo('procedimental', uid).map((p) => estudio.efectivo(p, grupo.value) as InfoProcedimental)
const APOYO = { ejemplo_resuelto: 'Ejemplo resuelto', por_completar: 'Completa el programa', convencional: 'Resuélvelo', solucion_libre: 'Explora' }
</script>

<template>
  <div class="fixed inset-0 z-10 flex justify-end bg-black/30" @click.self="$emit('cerrar')">
    <div class="h-full w-[760px] overflow-y-auto bg-white p-6 shadow-xl">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-lg font-semibold">Vista previa · {{ clase.titulo }}</h2>
        <div class="flex items-center gap-2 text-sm">
          <select v-model="grupo" class="campo w-40">
            <option :value="null">Versión base</option>
            <option v-for="g in estudio.grupos" :key="g.clave" :value="g.clave">{{ g.nombre }}</option>
          </select>
          <button class="btn-sec" @click="$emit('cerrar')">Cerrar</button>
        </div>
      </div>
      <section v-for="s in soporte" :key="s.uid" class="mb-6 rounded-lg bg-sky-50 p-4">
        <p class="text-xs uppercase tracking-wide text-sky-700">Antes de empezar</p>
        <h3 class="font-semibold">{{ s.titulo }}</h3>
        <VistaMarkdown :md="s.cuerpo_md" />
      </section>
      <section v-for="t in tareas" :key="t.uid" class="mb-6 rounded-lg border p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Tarea {{ t.orden }} · {{ APOYO[t.nivel_apoyo] }}</p>
        <h3 class="mb-2 font-semibold">{{ t.titulo }}</h3>
        <VistaMarkdown :md="t.enunciado_md" />
        <EditorCodigo v-if="t.codigo_inicial" :model-value="t.codigo_inicial" :lenguaje="t.lenguaje" solo-lectura class="mt-2" />
        <p v-if="t.pide_autoexplicacion" class="mt-2 text-sm text-indigo-700">✎ Se pedirá explicar tu razonamiento.</p>
        <details v-for="a in ayudas(t.uid)" :key="a.uid" class="mt-2 rounded bg-slate-50 p-2 text-sm">
          <summary class="cursor-pointer">Ayuda: {{ a.titulo }}</summary>
          <VistaMarkdown :md="a.cuerpo_md" />
        </details>
      </section>
    </div>
  </div>
</template>
