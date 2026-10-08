<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Variante } from '@shared/contracts'
import type { Contenido, ElementoLocal, TipoElemento } from '@shared/diseno'
import { diferencias } from '@shared/diseno'
import { plano, useDiseno } from '../../stores/diseno'
import FormClase from './FormClase.vue'
import FormMedio from './FormMedio.vue'
import FormObjetivo from './FormObjetivo.vue'
import FormPractica from './FormPractica.vue'
import FormTarea from './FormTarea.vue'
import FormTexto from './FormTexto.vue'
import PanelDiseno from './PanelDiseno.vue'

const props = defineProps<{ elemento: ElementoLocal }>()
const estudio = useDiseno()

const NOMBRES: Record<TipoElemento, string> = {
  objetivo: 'Objetivo',
  clase: 'Clase de tareas',
  tarea: 'Tarea de aprendizaje',
  soporte: 'Información de soporte',
  procedimental: 'Información procedimental',
  practica_parcial: 'Práctica de tareas parciales',
  variante: 'Variante',
  medio: 'Medio'
}
// Tomlinson: se diferencian las tareas y la información que las acompaña
const CON_VARIANTES: TipoElemento[] = ['tarea', 'soporte', 'procedimental', 'practica_parcial']
const admiteVariantes = computed(
  () => CON_VARIANTES.includes(props.elemento.tipo) && estudio.grupos.length > 0
)
const grupo = computed(() => (admiteVariantes.value ? estudio.grupoActivo : null))
const varianteActual = computed(() =>
  grupo.value ? estudio.variante(props.elemento.uid, grupo.value) : null
)

// Se edita una copia: base o, si hay grupo activo, el contenido efectivo de ese grupo
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const borrador = ref<any>(null)
const razon = ref('')
let ultimo = '' // firma de lo último cargado o programado para guardar

function firma(): string {
  return JSON.stringify([borrador.value, razon.value])
}
function cargar(): void {
  borrador.value = plano(estudio.efectivo(props.elemento, grupo.value))
  razon.value =
    (varianteActual.value?.contenido as Variante | undefined)?.diferenciacion.razon ?? ''
  ultimo = firma()
}

// Autoguardado 800 ms después del último cambio. Se captura el contexto: si el instructor
// cambia de elemento antes de que venza el plazo, se guarda lo pendiente del elemento anterior.
interface Pendiente {
  elemento: ElementoLocal
  grupo: string | null
  datos: Contenido
  razon: string
}
let pendiente: Pendiente | null = null
let temporizador: number | undefined
const guardando = ref(false)

watch(
  [borrador, razon],
  () => {
    const f = firma()
    if (f === ultimo) return
    ultimo = f
    pendiente = {
      elemento: props.elemento,
      grupo: grupo.value,
      datos: plano(borrador.value),
      razon: razon.value
    }
    window.clearTimeout(temporizador)
    temporizador = window.setTimeout(vaciar, 800)
  },
  { deep: true }
)
watch(
  () => [props.elemento.uid, grupo.value],
  async () => {
    await vaciar()
    cargar()
  },
  { immediate: true }
)
onBeforeUnmount(vaciar)

// Con poco ancho (árbol y asistente abiertos, pantalla de laptop) el panel «Diseño» pasa debajo del formulario
const raiz = ref<HTMLElement | null>(null)
const estrecho = ref(false)
const observador = new ResizeObserver(([e]) => (estrecho.value = e.contentRect.width < 900))
onMounted(() => raiz.value && observador.observe(raiz.value))
onBeforeUnmount(() => observador.disconnect())

async function vaciar(): Promise<void> {
  window.clearTimeout(temporizador)
  const p = pendiente
  pendiente = null
  if (!p) return
  guardando.value = true
  try {
    await guardar(p)
  } finally {
    guardando.value = false
  }
}

async function guardar(p: Pendiente): Promise<void> {
  if (!p.grupo) {
    await estudio.guardar(p.elemento.tipo, p.datos)
    return
  }
  const v = estudio.variante(p.elemento.uid, p.grupo)
  const cambios = diferencias(p.elemento.contenido, p.datos)
  if (!Object.keys(cambios).length && !v) return // igual a la base: no hace falta variante

  const variante: Variante = {
    uid: v?.uid ?? `${p.elemento.uid}-${p.grupo}`,
    elemento_uid: p.elemento.uid,
    grupo_clave: p.grupo,
    cambios,
    diferenciacion: {
      dimensiones: (v?.contenido as Variante | undefined)?.diferenciacion.dimensiones ?? [
        'proceso'
      ],
      razon: p.razon
    }
  }
  await estudio.guardar('variante', variante)
}

async function quitarVariante(): Promise<void> {
  if (varianteActual.value) await estudio.eliminar(varianteActual.value.uid)
  cargar()
}

async function eliminar(): Promise<void> {
  if (window.confirm(`¿Eliminar «${props.elemento.uid}» y todo lo que contiene?`))
    await estudio.eliminar(props.elemento.uid)
}
</script>

<template>
  <div v-if="borrador" ref="raiz" class="flex h-full">
    <section class="min-w-0 flex-1 overflow-y-auto p-5">
      <div class="mb-4 flex items-center justify-between">
        <div>
          <p class="text-xs uppercase tracking-wide text-slate-500">{{ NOMBRES[elemento.tipo] }}</p>
          <p class="font-mono text-xs text-slate-400">
            {{ elemento.uid }} · v{{ elemento.version }} · {{ elemento.estado }}
          </p>
        </div>
        <div class="flex items-center gap-3 text-xs">
          <span v-if="guardando" class="text-slate-500">Guardando…</span>
          <span v-else-if="elemento.sucio" class="text-amber-600">Sin sincronizar</span>
          <button
            v-if="elemento.estado === 'borrador' && !elemento.sucio && elemento.tipo !== 'variante'"
            class="btn-sec py-0.5"
            title="Pasa el verificador y aprueba este elemento (y sus variantes)"
            @click="estudio.aprobar([elemento.uid])"
          >
            Aprobar
          </button>
          <span
            v-if="elemento.estado === 'aprobado'"
            class="rounded bg-green-100 px-2 text-green-800"
            >Aprobado</span
          >
          <button class="text-red-600" @click="eliminar">Eliminar</button>
        </div>
      </div>

      <!-- Selector de versión: base o la variante de un grupo -->
      <div
        v-if="admiteVariantes"
        class="mb-4 flex flex-wrap items-center gap-2 rounded-md bg-slate-100 p-2 text-sm"
      >
        <span class="text-slate-600">Versión:</span>
        <button
          class="rounded px-2 py-0.5"
          :class="!estudio.grupoActivo ? 'bg-white shadow' : ''"
          @click="estudio.grupoActivo = null"
        >
          Base
        </button>
        <button
          v-for="g in estudio.grupos"
          :key="g.clave"
          class="rounded px-2 py-0.5"
          :class="estudio.grupoActivo === g.clave ? 'bg-white shadow' : ''"
          @click="estudio.grupoActivo = g.clave"
        >
          {{ g.nombre
          }}<span v-if="estudio.variante(elemento.uid, g.clave)" class="ml-1 text-indigo-600"
            >●</span
          >
        </button>
      </div>

      <div v-if="grupo" class="mb-4 rounded-md border border-indigo-200 bg-indigo-50 p-3 text-sm">
        <p>
          Editas la variante de <b>{{ estudio.grupos.find((g) => g.clave === grupo)?.nombre }}</b
          >: solo se guarda lo que cambies respecto a la base. La solución y los casos de prueba
          siempre son los de la base: todos los grupos se evalúan igual.
        </p>
        <input
          v-model="razon"
          class="campo mt-2"
          placeholder="¿Por qué este grupo necesita una versión distinta?"
        />
        <button v-if="varianteActual" class="mt-2 text-xs text-red-600" @click="quitarVariante">
          Quitar la variante (usar la base)
        </button>
      </div>

      <FormObjetivo v-if="elemento.tipo === 'objetivo'" v-model="borrador" />
      <FormClase v-else-if="elemento.tipo === 'clase'" v-model="borrador" />
      <FormTarea v-else-if="elemento.tipo === 'tarea'" v-model="borrador" />
      <FormTexto
        v-else-if="elemento.tipo === 'soporte' || elemento.tipo === 'procedimental'"
        v-model="borrador"
        :tipo="elemento.tipo"
      />
      <FormPractica v-else-if="elemento.tipo === 'practica_parcial'" v-model="borrador" />
      <FormMedio v-else-if="elemento.tipo === 'medio'" v-model="borrador" />

      <div v-if="elemento.errores" class="mt-4 rounded-md bg-red-50 p-3 text-xs text-red-700">
        <p class="font-medium">Incompleto (no se subirá hasta corregirlo):</p>
        <p v-for="(msgs, ruta) in elemento.errores" :key="ruta">{{ ruta }}: {{ msgs.join(' ') }}</p>
      </div>
      <div
        v-if="elemento.error_servidor"
        class="mt-4 rounded-md bg-red-50 p-3 text-xs text-red-700"
      >
        <p class="font-medium">El servidor lo rechazó:</p>
        <p v-for="(msgs, ruta) in elemento.error_servidor" :key="ruta">
          {{ ruta }}: {{ msgs.join(' ') }}
        </p>
      </div>

      <div
        v-if="borrador.diseno && estrecho"
        class="mt-6 rounded-md border border-slate-200 bg-white p-4"
      >
        <p class="mb-3 font-semibold">Diseño</p>
        <PanelDiseno
          v-model:diseno="borrador.diseno"
          v-model:arcs="borrador.arcs"
          :con-arcs="elemento.tipo === 'tarea'"
        />
      </div>
    </section>

    <aside
      v-if="borrador.diseno && !estrecho"
      class="w-80 shrink-0 overflow-y-auto border-l border-slate-200 bg-white p-4"
    >
      <p class="mb-3 font-semibold">Diseño</p>
      <PanelDiseno
        v-model:diseno="borrador.diseno"
        v-model:arcs="borrador.arcs"
        :con-arcs="elemento.tipo === 'tarea'"
      />
    </aside>
  </div>
</template>
