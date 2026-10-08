<script setup lang="ts">
import { computed, ref } from 'vue'
import { aceptablesPorDefecto, itemAlBanco, NOMBRE_PLANTILLA, type ItemGenerado, type TrabajoAgente } from '@shared/agente'
import { api } from '../../lib/api'
import { useAgente } from '../../stores/agente'
import { useDiseno } from '../../stores/diseno'
import TarjetaPropuesta from './TarjetaPropuesta.vue'

const props = defineProps<{ trabajo: TrabajoAgente; lenguaje: string }>()
const emit = defineEmits<{ regenerar: [indicaciones: string] }>()
const agente = useAgente()
const estudio = useDiseno()

const r = computed(() => props.trabajo.resultado!)

// Evaluación del tema: sus ítems se guardan en el Banco de ítems como borradores (las formas A y B se arman en Pruebas)
const items = computed(() =>
  props.trabajo.plantilla === 'items_evaluacion' ? ((r.value.notas.items as ItemGenerado[] | undefined) ?? []) : []
)
const enBanco = ref(0)
const errorBanco = ref('')
async function guardarEnBanco(): Promise<void> {
  guardando.value = true
  errorBanco.value = ''
  try {
    for (const it of items.value.slice(enBanco.value)) {
      await api('POST', `/courses/${estudio.cursoId}/items`, itemAlBanco(it, props.lenguaje))
      enBanco.value++
    }
    await agente.decidir([]) // sin elementos de diseño: queda registrada como aceptada
  } catch (e) {
    errorBanco.value = `Se guardaron ${enBanco.value} de ${items.value.length}: ${(e as Error).message}`
  } finally {
    guardando.value = false
  }
}
const actual = (uid: string): ReturnType<typeof estudio.porUid.get> | null =>
  estudio.porUid.get(uid) ?? null

// Una tarea depende de su clase y una ayuda de su tarea: aceptar el hijo sin su padre nuevo dejaba elementos
// huérfanos, invisibles en el árbol. Al elegir un hijo se elige su padre (si es nuevo); al quitar un padre, sus hijos
function padreDe(uid: string): string | null {
  const c = r.value.elementos.find((e) => e.contenido.uid === uid)?.contenido as
    { clase_uid?: string; tarea_uid?: string } | undefined
  return c?.tarea_uid ?? c?.clase_uid ?? null
}
const propuesto = (uid: string): boolean => r.value.elementos.some((e) => e.contenido.uid === uid)
function conPadres(uids: Set<string>): Set<string> {
  for (const uid of [...uids]) {
    for (let p = padreDe(uid); p && propuesto(p) && !actual(p); p = padreDe(p)) uids.add(p)
  }
  return uids
}

const elegidos = ref(conPadres(aceptablesPorDefecto(props.trabajo.resultado!)))
const indicaciones = ref('')
const guardando = ref(false)

const generales = computed(() => r.value.validaciones.filter((v) => !v.elemento_uid))
const deElemento = (uid: string): typeof r.value.validaciones =>
  r.value.validaciones.filter((v) => v.elemento_uid === uid)

function alternar(uid: string): void {
  if (!elegidos.value.has(uid)) {
    elegidos.value = conPadres(new Set([...elegidos.value, uid]))
    return
  }
  const quitar = (u: string): void => {
    elegidos.value.delete(u)
    for (const e of r.value.elementos)
      if (padreDe(e.contenido.uid) === u && !actual(u)) quitar(e.contenido.uid)
  }
  quitar(uid)
}

async function aceptar(): Promise<void> {
  guardando.value = true
  try {
    await agente.decidir(
      r.value.elementos.map((e) => e.contenido.uid).filter((u) => elegidos.value.has(u))
    )
  } finally {
    guardando.value = false
  }
}

async function eliminar(): Promise<void> {
  if (window.confirm('¿Eliminar esta propuesta? Nada de ella se guarda en el diseño y no se puede recuperar.'))
    await agente.eliminar(props.trabajo.id)
}

function copiarNotas(): void {
  void navigator.clipboard.writeText(JSON.stringify(r.value.notas, null, 2))
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex flex-col bg-white">
    <header class="flex items-center justify-between border-b border-slate-200 px-6 py-3">
      <div>
        <h2 class="text-lg font-semibold">
          Propuesta del asistente · {{ NOMBRE_PLANTILLA[trabajo.plantilla] }}
        </h2>
        <p class="text-xs text-slate-500">
          {{ r.modelo }} · {{ r.intentos }} intento(s) · {{ (r.duracion_ms / 1000).toFixed(0) }} s ·
          {{
            r.modelo.startsWith('ollama:')
              ? 'local, sin costo'
              : `US$ ${r.uso.costo_usd.toFixed(3)}`
          }}
          · prompt {{ r.version_prompt }}
        </p>
      </div>
      <button class="btn-sec" @click="agente.abierto = null">Cerrar sin decidir</button>
    </header>

    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
      <div
        v-if="r.advertencias.length || generales.some((v) => !v.ok)"
        class="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm"
      >
        <p v-for="(a, i) in r.advertencias" :key="`a${i}`">⚠ {{ a }}</p>
        <p v-for="(v, i) in generales.filter((v) => !v.ok)" :key="`v${i}`">
          {{ v.bloqueante ? '⛔' : '⚠' }} {{ v.detalle }}
        </p>
      </div>

      <!-- RAG: de dónde se fundamentó la propuesta -->
      <div
        v-if="trabajo.material?.length"
        class="mb-4 rounded border border-slate-200 bg-slate-50 p-3 text-sm"
      >
        <p class="mb-1 text-xs uppercase text-slate-400">Material consultado</p>
        <p v-for="(m, i) in trabajo.material" :key="i">
          [M{{ i + 1 }}] {{ m.documento }}<span v-if="m.pagina">, p. {{ m.pagina }}</span>
        </p>
      </div>

      <!-- Elementos de diseño: actual a la izquierda, propuesta a la derecha -->
      <div
        v-for="e in r.elementos"
        :key="e.contenido.uid"
        class="mb-4 rounded-lg border border-slate-200"
      >
        <label
          class="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-3 py-2 text-sm"
        >
          <input
            type="checkbox"
            :checked="elegidos.has(e.contenido.uid)"
            @change="alternar(e.contenido.uid)"
          />
          Aceptar <span class="font-mono text-xs text-slate-500">{{ e.contenido.uid }}</span>
          <span v-if="actual(e.contenido.uid)" class="text-amber-700">· reemplaza al actual</span>
        </label>
        <div class="grid grid-cols-2 gap-4 p-3">
          <div class="border-r border-slate-100 pr-4">
            <p class="mb-1 text-xs uppercase text-slate-400">Actual</p>
            <TarjetaPropuesta
              v-if="actual(e.contenido.uid)"
              :elemento="{ tipo: e.tipo, contenido: actual(e.contenido.uid)!.contenido }"
            />
            <p v-else class="text-sm text-slate-400">(nuevo)</p>
          </div>
          <div>
            <p class="mb-1 text-xs uppercase text-slate-400">Propuesta</p>
            <TarjetaPropuesta :elemento="e" />
            <ul class="mt-2 text-xs">
              <li
                v-for="(v, i) in deElemento(e.contenido.uid)"
                :key="i"
                :class="v.ok ? 'text-green-700' : v.bloqueante ? 'text-red-700' : 'text-amber-700'"
              >
                {{ v.ok ? '✓' : v.bloqueante ? '⛔' : '⚠' }} {{ v.detalle || v.nombre }}
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Lo que no es elemento de diseño (resúmenes, planes, ítems, guiones) -->
      <div v-if="Object.keys(r.notas).length" class="rounded-lg border border-slate-200 p-3">
        <div class="mb-2 flex items-center justify-between">
          <p class="text-sm font-medium">Resultado</p>
          <button class="btn-sec" @click="copiarNotas">Copiar</button>
        </div>
        <pre class="max-h-[50vh] overflow-auto whitespace-pre-wrap text-xs">{{
          JSON.stringify(r.notas, null, 2)
        }}</pre>
      </div>
    </div>

    <footer class="flex items-end gap-3 border-t border-slate-200 px-6 py-3">
      <div class="flex flex-1 flex-col">
        <label class="etiqueta text-xs" for="regenerar-indicaciones"
          >Qué cambiar al regenerar (opcional)</label
        >
        <textarea
          id="regenerar-indicaciones"
          v-model="indicaciones"
          class="campo h-16"
          placeholder="P. ej., «tareas con datos de ventas en lugar de calificaciones». Vacío: otra versión con las mismas indicaciones."
        ></textarea>
      </div>
      <!-- Sin texto también regenera: a veces basta otra versión de la misma solicitud -->
      <button class="btn-sec" @click="emit('regenerar', indicaciones)">Regenerar</button>
      <button class="btn-sec text-red-700" @click="eliminar">Eliminar propuesta</button>
      <p v-if="errorBanco" class="max-w-xs text-xs text-red-600">{{ errorBanco }}</p>
      <button v-if="items.length" class="btn" :disabled="guardando" @click="guardarEnBanco">
        Guardar {{ items.length }} ítems en el Banco de ítems
      </button>
      <button class="btn" :disabled="guardando || (r.elementos.length > 0 && !elegidos.size)" @click="aceptar">
        {{
          r.elementos.length
            ? `Guardar ${elegidos.size} de ${r.elementos.length} en el diseño`
            : 'Marcar como útil'
        }}
      </button>
    </footer>
  </div>
</template>
