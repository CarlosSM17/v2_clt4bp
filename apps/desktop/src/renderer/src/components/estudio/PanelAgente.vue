<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { accionesPara, NOMBRE_PLANTILLA, PIEZAS_DEL_TEMA, type AccionAgente } from '@shared/agente'
import { useAgente } from '../../stores/agente'
import { useDiseno } from '../../stores/diseno'
import ComparadorPropuesta from './ComparadorPropuesta.vue'

defineProps<{ lenguaje: string }>()
const agente = useAgente()
const estudio = useDiseno()

const acciones = computed(() => accionesPara(estudio.actual, estudio.elementos, estudio.grupos))
const elegida = ref<AccionAgente | null>(null)
const indicaciones = ref('')
const alta = ref(false)
const campo = ref<HTMLTextAreaElement | null>(null)
const formulario = ref<HTMLDivElement | null>(null)
// Una clave por intención: si el envío falla y se reintenta, el servidor reconoce la misma petición
const clave = ref(crypto.randomUUID())

// Tema completo en preparación: cuántas piezas de cada clase ya llegaron (mientras alguna sigue en cola)
const temas = computed(() => {
  const porClase = new Map<string, { total: number; listas: number; pendientes: number }>()
  for (const t of agente.trabajos) {
    const clase = t.parametros.alcance.clase_uid as string | undefined
    if (!clase || !PIEZAS_DEL_TEMA.includes(t.plantilla)) continue
    const c = porClase.get(clase) ?? { total: 0, listas: 0, pendientes: 0 }
    c.total++
    if (t.estado === 'listo' || t.estado === 'error') c.listas++
    else c.pendientes++
    porClase.set(clase, c)
  }
  return [...porClase].filter(([, c]) => c.pendientes).map(([uid, c]) => ({
    titulo: (estudio.porUid.get(uid)?.contenido as { titulo?: string } | undefined)?.titulo ?? uid,
    ...c
  }))
})

const ESTADO = { en_cola: 'En cola', procesando: 'Generando…', listo: 'Lista para revisar', error: 'Error' } as const
const DECISION = {
  aceptado: ['Guardada en el diseño', 'bg-green-100 text-green-800'],
  parcial: ['Guardada en parte', 'bg-green-100 text-green-800'],
  descartado: ['Descartada', 'bg-slate-100 text-slate-600']
} as const

async function eliminar(id: number): Promise<void> {
  if (window.confirm('¿Eliminar esta propuesta? Lo que ya guardaste en el diseño se queda.')) await agente.eliminar(id)
}

onMounted(() => agente.cargar())
onBeforeUnmount(() => agente.detener())

async function elegir(a: AccionAgente): Promise<void> {
  elegida.value = a
  clave.value = crypto.randomUUID()
  // Con muchas acciones el formulario queda abajo: se lleva a la vista y se deja el cursor en él
  await nextTick()
  formulario.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  campo.value?.focus({ preventScroll: true })
}

async function pedir(): Promise<void> {
  if (!elegida.value) return
  const calidad = alta.value ? 'alta' : 'normal'
  // «Completar el tema»: una propuesta por pieza, cada una con su propia clave
  const ok = elegida.value.piezas
    ? await agente.pedirPiezas(elegida.value.piezas, indicaciones.value, calidad)
    : await agente.solicitar(elegida.value, indicaciones.value, calidad, clave.value)
  if (ok) {
    elegida.value = null
    indicaciones.value = ''
    clave.value = crypto.randomUUID()
  }
}

async function regenerar(texto: string): Promise<void> {
  const t = agente.abierto
  if (!t) return
  const previas = t.parametros.indicaciones ? `${t.parametros.indicaciones}\n` : ''
  agente.abierto = null
  await agente.solicitar({ plantilla: t.plantilla, etiqueta: '', alcance: t.parametros.alcance }, (previas + texto).trim(), t.parametros.calidad ?? 'normal', crypto.randomUUID())
}
</script>

<template>
  <div class="h-full overflow-y-auto text-sm">
    <div class="border-b border-slate-200 p-3">
      <p class="font-semibold">Asistente de diseño (IA)</p>
     
      <p class="mt-1 text-xs text-slate-500">Propone; tú decides. Nada entra al diseño sin que lo aceptes.</p>
    </div>

    <div class="border-b border-slate-200 p-3">
      <p class="mb-1 text-xs uppercase text-slate-400">¿Qué necesitas?</p>
      <button
        v-for="a in acciones.filter(item => ['Generar tema completo', 'Ítems de evaluación', 'Proponer objetivos de desempeño'].includes(item.etiqueta))"
        :key="a.etiqueta"
        class="block w-full rounded px-2 py-1 text-left hover:bg-slate-100"
        :class="{ 'bg-indigo-50 text-indigo-800': elegida?.etiqueta === a.etiqueta }"
        @click="elegir(a)"
      >
        {{ a.etiqueta }}
      </button>
      <div v-if="elegida" ref="formulario" class="mt-2 space-y-2">
        <label class="etiqueta text-xs" for="indicaciones-agente">Contexto adicional (opcional)</label>
        <textarea
          id="indicaciones-agente"
          ref="campo"
          v-model="indicaciones"
          class="campo h-24"
          maxlength="4000"
          placeholder="Opcional: tema, contexto de los estudiantes, estilo.
No escribas nombres ni datos de estudiantes."
        ></textarea>
        <label class="flex items-center gap-2 text-xs"><input v-model="alta" type="checkbox" /> Calidad alta (más lento y más caro)</label>
        <button class="btn w-full justify-center" :disabled="agente.enviando" @click="pedir">
          {{ agente.enviando ? 'Enviando…' : 'Pedir propuesta' }}
        </button>
      </div>
      <p v-if="agente.error" class="mt-2 text-red-600">{{ agente.error }}</p>
    </div>

    <div class="p-3">
      <p
        v-for="t in temas"
        :key="t.titulo"
        class="mb-2 rounded border border-indigo-200 bg-indigo-50 p-2 text-xs text-indigo-900"
      >
        Tema «{{ t.titulo }}» en preparación: {{ t.listas }} de {{ t.total }} piezas listas. Cada una llega como
        propuesta; guárdala o elimínala.
      </p>
      <p class="mb-1 text-xs uppercase text-slate-400">Propuestas recientes</p>
      <div v-for="t in agente.trabajos" :key="t.id" class="mb-2 rounded border border-slate-200 p-2">
        <p class="font-medium">{{ NOMBRE_PLANTILLA[t.plantilla] }}</p>
        <p class="text-xs" :class="t.estado === 'error' ? 'text-red-600' : 'text-slate-500'">
          {{ ESTADO[t.estado] }} · {{ new Date(t.created_at).toLocaleString() }}
        </p>
        <p v-if="t.error" class="text-xs text-red-600">{{ t.error }}</p>
        <span v-if="t.decision" class="mt-1 inline-block rounded px-1 text-xs" :class="DECISION[t.decision][1]">{{ DECISION[t.decision][0] }}</span>
        <div v-if="t.estado === 'listo' || t.estado === 'error'" class="mt-1 flex gap-2">
          <button v-if="t.estado === 'listo'" class="btn-sec" @click="agente.abrir(t.id)">Revisar</button>
          <button class="text-xs text-red-600" @click="eliminar(t.id)">Eliminar</button>
        </div>
      </div>
    </div>

    <ComparadorPropuesta v-if="agente.abierto?.resultado" :key="agente.abierto.id" :trabajo="agente.abierto" :lenguaje="lenguaje" @regenerar="regenerar" />
  </div>
</template>
