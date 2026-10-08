<script setup lang="ts">
import { computed, ref } from 'vue'
import type {
  ClaseTareas,
  InfoProcedimental,
  InfoSoporte,
  Lenguaje,
  Medio,
  Objetivo,
  PracticaParcial,
  Tarea
} from '@shared/contracts'
import type { ElementoLocal } from '@shared/diseno'
import { useDiseno } from '../../stores/diseno'
import {
  nuevaClase,
  nuevaPractica,
  nuevaTarea,
  nuevoObjetivo,
  nuevoProcedimental,
  nuevoProcedimentalTema,
  nuevoSoporte
} from './plantillas'
import Grabador from './Grabador.vue'

const props = defineProps<{
  lenguaje: Lenguaje
  semaforo?: Record<string, 'rojo' | 'amarillo' | 'verde'>
}>()
const estudio = useDiseno()

const objetivos = computed(() => estudio.deTipo('objetivo'))
const clases = computed(() => estudio.deTipo('clase'))
const practicas = computed(() => estudio.deTipo('practica_parcial'))
const medios = computed(() => estudio.deTipo('medio'))
const grabando = ref(false)

// Elementos cuyo padre no existe: por ejemplo, tareas aceptadas de una propuesta sin aceptar su clase. Sin este
// grupo no aparecerían en ningún lado aunque se sigan sincronizando y publicando
const huerfanos = computed(() => {
  const clasesUid = new Set(clases.value.map((c) => c.uid))
  const tareas = estudio.deTipo('tarea')
  const tareasUid = new Set(tareas.map((t) => t.uid))
  return [
    ...tareas.filter((t) => !clasesUid.has(t.padre_uid ?? '')),
    ...estudio.deTipo('soporte').filter((s) => !clasesUid.has(s.padre_uid ?? '')),
    // Una ayuda cuelga de una tarea o, si es del tema, de su clase
    ...estudio
      .deTipo('procedimental')
      .filter((p) => !tareasUid.has(p.padre_uid ?? '') && !clasesUid.has(p.padre_uid ?? ''))
  ]
})
const CLASE_HUERFANO: Record<string, string> = {
  tarea: 'Tarea',
  soporte: 'Soporte',
  procedimental: 'Ayuda'
}

function titulo(e: ElementoLocal): string {
  switch (e.tipo) {
    case 'objetivo': {
      const o = e.contenido as Objetivo
      return `${o.codigo} ${o.descripcion}`
    }
    case 'practica_parcial':
      return (e.contenido as PracticaParcial).habilidad || 'Práctica de tareas parciales'
    case 'medio':
      return (e.contenido as Medio).titulo
    default:
      return (e.contenido as { titulo?: string }).titulo || e.uid
  }
}

async function crear(tipo: 'objetivo' | 'clase' | 'practica_parcial'): Promise<void> {
  const contenido =
    tipo === 'objetivo'
      ? nuevoObjetivo(objetivos.value.length + 1)
      : tipo === 'clase'
        ? nuevaClase(clases.value.length + 1)
        : nuevaPractica(props.lenguaje)
  const e = await estudio.guardar(tipo, contenido)
  estudio.seleccionado = e.uid
}
async function crearTarea(clase: string): Promise<void> {
  const e = await estudio.guardar(
    'tarea',
    nuevaTarea(clase, estudio.deTipo('tarea', clase).length + 1, props.lenguaje)
  )
  estudio.seleccionado = e.uid
}
async function crearSoporte(clase: string): Promise<void> {
  estudio.seleccionado = (await estudio.guardar('soporte', nuevoSoporte(clase))).uid
}
async function crearProcedimental(tarea: string): Promise<void> {
  estudio.seleccionado = (await estudio.guardar('procedimental', nuevoProcedimental(tarea))).uid
}
async function crearProcedimentalTema(clase: string): Promise<void> {
  estudio.seleccionado = (await estudio.guardar('procedimental', nuevoProcedimentalTema(clase))).uid
}
const nombreRuta = (clave: string): string =>
  estudio.grupos.find((g) => g.clave === clave)?.nombre ?? clave

const APOYO: Record<Tarea['nivel_apoyo'], string> = {
  ejemplo_resuelto: 'ER',
  por_completar: 'PC',
  convencional: 'CV',
  solucion_libre: 'SL'
}
</script>

<template>
  <nav class="space-y-4 text-sm">
    <div>
      <div
        class="mb-1 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-slate-500"
      >
        Objetivos
        <button class="text-indigo-600" title="Nuevo objetivo" @click="crear('objetivo')">+</button>
      </div>
      <button
        v-for="o in objetivos"
        :key="o.uid"
        class="nodo"
        :class="{ activo: estudio.seleccionado === o.uid }"
        @click="estudio.seleccionado = o.uid"
      >
        <span class="truncate">{{ titulo(o) }}</span>
        <span class="marcas"
          ><i v-if="o.sucio" title="Sin sincronizar">●</i>
          <i v-if="o.errores" class="text-red-600" title="Incompleto">!</i></span
        >
      </button>
    </div>

    <div>
      <div
        class="mb-1 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-slate-500"
      >
        Clases de tareas
        <button class="text-indigo-600" title="Nueva clase" @click="crear('clase')">+</button>
      </div>
      <div v-for="c in clases" :key="c.uid" class="mb-2">
        <button
          class="nodo font-medium"
          :class="{ activo: estudio.seleccionado === c.uid }"
          @click="estudio.seleccionado = c.uid"
        >
          <span class="truncate">{{ (c.contenido as ClaseTareas).orden }}. {{ titulo(c) }}</span>
          <span class="marcas">
            <i v-if="semaforo?.[c.uid]" :class="`semaforo-${semaforo[c.uid]}`">●</i>
            <i v-if="c.sucio" title="Sin sincronizar">●</i
            ><i v-if="c.errores" class="text-red-600">!</i>
          </span>
        </button>
        <div class="ml-3 border-l border-slate-200 pl-2">
          <button
            v-for="s in estudio.deTipo('soporte', c.uid)"
            :key="s.uid"
            class="nodo text-slate-600"
            :class="{ activo: estudio.seleccionado === s.uid }"
            @click="estudio.seleccionado = s.uid"
          >
            <span class="truncate">ℹ {{ (s.contenido as InfoSoporte).titulo }}</span>
            <span class="marcas"
              ><i v-if="semaforo?.[s.uid]" :class="`semaforo-${semaforo[s.uid]}`">●</i
              ><i v-if="s.sucio">●</i></span
            >
          </button>
          <!-- Información procedimental del tema y protocolo verbal (cuelgan de la clase) -->
          <button
            v-for="p in estudio.deTipo('procedimental', c.uid)"
            :key="p.uid"
            class="nodo text-violet-700"
            :class="{ activo: estudio.seleccionado === p.uid }"
            @click="estudio.seleccionado = p.uid"
          >
            <span class="truncate"
              >{{ (p.contenido as InfoProcedimental).tipo === 'protocolo_verbal' ? '🎙' : '⚙' }}
              {{ (p.contenido as InfoProcedimental).titulo }}</span
            >
            <span class="marcas"><i v-if="p.sucio">●</i></span>
          </button>
          <template v-for="t in estudio.deTipo('tarea', c.uid)" :key="t.uid">
            <button
              class="nodo"
              :class="{ activo: estudio.seleccionado === t.uid }"
              @click="estudio.seleccionado = t.uid"
            >
              <span class="truncate">
                <span class="mr-1 rounded bg-slate-100 px-1 font-mono text-[10px]">{{
                  APOYO[(t.contenido as Tarea).nivel_apoyo]
                }}</span>
                {{ (t.contenido as Tarea).orden }}. {{ titulo(t) }}
              </span>
              <span class="marcas">
                <i
                  v-for="r in (t.contenido as Tarea).rutas ?? []"
                  :key="`ruta-${r}`"
                  class="text-sky-600"
                  :title="`Solo para ${nombreRuta(r)}`"
                  >{{ nombreRuta(r).slice(-1) }}</i
                >
                <i
                  v-for="g in estudio.grupos.filter((g) => estudio.variante(t.uid, g.clave))"
                  :key="g.clave"
                  class="text-indigo-600"
                  :title="`Variante para ${g.nombre}`"
                  >{{ g.nombre.slice(-1) }}</i
                >
                <i v-if="semaforo?.[t.uid]" :class="`semaforo-${semaforo[t.uid]}`">●</i>
                <i v-if="t.sucio" title="Sin sincronizar">●</i
                ><i v-if="t.errores" class="text-red-600">!</i>
              </span>
            </button>
            <button
              v-for="p in estudio.deTipo('procedimental', t.uid)"
              :key="p.uid"
              class="nodo ml-4 text-slate-600"
              :class="{ activo: estudio.seleccionado === p.uid }"
              @click="estudio.seleccionado = p.uid"
            >
              <span class="truncate">⚙ {{ (p.contenido as InfoProcedimental).titulo }}</span>
              <span class="marcas"><i v-if="p.sucio">●</i></span>
            </button>
            <button
              v-if="estudio.seleccionado === t.uid"
              class="ml-4 text-xs text-indigo-600"
              @click="crearProcedimental(t.uid)"
            >
              + ayuda procedimental
            </button>
          </template>
          <div class="flex gap-3 text-xs text-indigo-600">
            <button @click="crearTarea(c.uid)">+ tarea</button>
            <button @click="crearSoporte(c.uid)">+ soporte</button>
            <button @click="crearProcedimentalTema(c.uid)">+ procedimental del tema</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="huerfanos.length" class="rounded-md border border-amber-300 bg-amber-50 p-2">
      <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">
        Sin clase ({{ huerfanos.length }})
      </p>
      <p class="mb-1 text-xs text-amber-800">
        Su clase o su tarea no existe (¿se aceptaron de una propuesta sin la clase?). Elimínalos o
        crea la clase y asígnalos.
      </p>
      <button
        v-for="h in huerfanos"
        :key="h.uid"
        class="nodo"
        :class="{ activo: estudio.seleccionado === h.uid }"
        @click="estudio.seleccionado = h.uid"
      >
        <span class="truncate">{{ CLASE_HUERFANO[h.tipo] }}: {{ titulo(h) }}</span>
        <span class="marcas"><i v-if="h.sucio">●</i></span>
      </button>
    </div>

    <div>
      <div
        class="mb-1 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-slate-500"
      >
        Práctica de tareas parciales
        <button class="text-indigo-600" @click="crear('practica_parcial')">+</button>
      </div>
      <button
        v-for="p in practicas"
        :key="p.uid"
        class="nodo"
        :class="{ activo: estudio.seleccionado === p.uid }"
        @click="estudio.seleccionado = p.uid"
      >
        <span class="truncate">{{ titulo(p) }}</span
        ><span class="marcas"><i v-if="p.sucio">●</i></span>
      </button>
    </div>

    <div>
      <div
        class="mb-1 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-slate-500"
      >
        Medios
        <button class="text-indigo-600" title="Grabar protocolo verbal" @click="grabando = true">
          ● grabar
        </button>
      </div>
      <button
        v-for="m in medios"
        :key="m.uid"
        class="nodo"
        :class="{ activo: estudio.seleccionado === m.uid }"
        @click="estudio.seleccionado = m.uid"
      >
        <span class="truncate">🎞 {{ titulo(m) }}</span>
        <span class="marcas"
          ><i v-if="semaforo?.[m.uid]" :class="`semaforo-${semaforo[m.uid]}`">●</i
          ><i v-if="m.sucio">●</i></span
        >
      </button>
    </div>
    <Grabador v-if="grabando" @cerrar="grabando = false" />
  </nav>
</template>

<style scoped>
.nodo {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  border-radius: 0.25rem;
  padding: 0.2rem 0.4rem;
  text-align: left;
}
.nodo:hover {
  background: #f1f5f9;
}
.nodo.activo {
  background: #e0e7ff;
}
.marcas {
  display: flex;
  gap: 0.2rem;
  font-style: normal;
  font-size: 0.7rem;
  color: #d97706;
}
.marcas i {
  font-style: normal;
}
.semaforo-rojo {
  color: #dc2626 !important;
}
.semaforo-amarillo {
  color: #f59e0b !important;
}
.semaforo-verde {
  color: #16a34a !important;
}
</style>
