<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import type { CasoPrueba, Item, TipoItem } from '@shared/tipos'
import { api } from '../../lib/api'
import PestanasCurso from '../../components/PestanasCurso.vue'

const props = defineProps<{ id: string }>()

const items = ref<Item[]>([])
const error = ref('')
const aviso = ref('')

// Formulario: los campos que no aplican al tipo elegido se ignoran al armar el cuerpo
const f = reactive({
  tipo: 'opcion_multiple' as TipoItem,
  nivel: 'recall' as Item['nivel'],
  objetivo: '',
  md: '',
  opciones: ['', '', '', ''],
  correcta: 0,
  aceptadas: '',
  salida: '',
  lineasTexto: '',
  lenguaje: 'c' as 'c' | 'cpp' | 'python',
  codigoInicial: '',
  solucion: '',
  casos: [{ entrada: '', salida_esperada: '', oculto: false }] as CasoPrueba[]
})
const esProgramacion = computed(() => f.tipo === 'programacion')

async function cargar(): Promise<void> {
  items.value = await api<Item[]>('GET', `/courses/${props.id}/items`)
}

function cuerpo(): Record<string, unknown> {
  const base = {
    tipo: f.tipo,
    nivel: esProgramacion.value ? 'practica' : f.nivel,
    objetivo: f.objetivo || null
  }
  switch (f.tipo) {
    case 'opcion_multiple': {
      const opciones = f.opciones
        .map((texto, i) => ({ id: String.fromCharCode(97 + i), texto }))
        .filter((o) => o.texto)
      return {
        ...base,
        enunciado: { md: f.md, opciones },
        clave: { correcta: String.fromCharCode(97 + f.correcta) }
      }
    }
    case 'respuesta_corta':
      return {
        ...base,
        enunciado: { md: f.md },
        clave: {
          aceptadas: f.aceptadas
            .split('|')
            .map((s) => s.trim())
            .filter(Boolean)
        }
      }
    case 'prediccion_salida':
      return { ...base, enunciado: { md: f.md }, clave: { salida: f.salida } }
    case 'parsons': {
      // Se escriben en el orden correcto; el aula las desordena
      const lineas = f.lineasTexto
        .split('\n')
        .filter((l) => l.trim())
        .map((texto, i) => ({ id: `l${i + 1}`, texto }))
      return {
        ...base,
        enunciado: { md: f.md, lineas },
        clave: { orden: lineas.map((l) => l.id) }
      }
    }
    case 'programacion':
      return {
        ...base,
        enunciado: { md: f.md, codigo_inicial: f.codigoInicial || undefined },
        lenguaje: f.lenguaje,
        solucion: f.solucion,
        casos_prueba: f.casos
      }
  }
}

async function crear(): Promise<void> {
  error.value = ''
  try {
    await api('POST', `/courses/${props.id}/items`, cuerpo())
    aviso.value = 'Ítem guardado como borrador.'
    f.md = ''
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function verificar(item: Item): Promise<void> {
  error.value = ''
  try {
    const r = await api<{
      verificado: boolean
      resultado: { aprobados: number; total: number; error_compilacion: string | null }
    }>('POST', `/items/${item.id}/verificar`)
    aviso.value = r.verificado
      ? `Verificado: ${r.resultado.aprobados}/${r.resultado.total} casos.`
      : r.resultado.error_compilacion
        ? `No compila: ${r.resultado.error_compilacion}`
        : `Falla: ${r.resultado.aprobados}/${r.resultado.total} casos.`
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function aprobar(item: Item): Promise<void> {
  error.value = ''
  try {
    await api('POST', `/items/${item.id}/aprobar`)
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

onMounted(cargar)
</script>

<template>
  <h1 class="mb-2 text-2xl font-semibold">Banco de ítems</h1>
  <PestanasCurso :id="id" />

  <form class="tarjeta mb-6 grid grid-cols-3 gap-4" @submit.prevent="crear">
    <div>
      <label class="etiqueta">Tipo</label>
      <select v-model="f.tipo" class="campo">
        <option value="opcion_multiple">Opción múltiple</option>
        <option value="respuesta_corta">Respuesta corta</option>
        <option value="prediccion_salida">Predicción de salida</option>
        <option value="parsons">Problema de Parsons</option>
        <option value="programacion">Programación</option>
      </select>
    </div>
    <div>
      <label class="etiqueta">Nivel</label>
      <select v-model="f.nivel" class="campo" :disabled="esProgramacion">
        <option value="recall">Recall</option>
        <option value="comprension">Comprensión</option>
      </select>
    </div>
    <div>
      <label class="etiqueta">Objetivo</label>
      <input v-model="f.objetivo" class="campo" placeholder="OB-1" />
    </div>
    <div class="col-span-3">
      <label class="etiqueta">Enunciado (Markdown)</label>
      <textarea v-model="f.md" class="campo h-24 font-mono" required />
    </div>

    <div v-if="f.tipo === 'opcion_multiple'" class="col-span-3 grid gap-2">
      <label v-for="(_, i) in f.opciones" :key="i" class="flex items-center gap-2">
        <input v-model="f.correcta" type="radio" :value="i" title="Correcta" />
        <input
          v-model="f.opciones[i]"
          class="campo"
          :placeholder="`Opción ${String.fromCharCode(97 + i)}`"
        />
      </label>
    </div>
    <div v-else-if="f.tipo === 'respuesta_corta'" class="col-span-3">
      <label class="etiqueta">Respuestas aceptadas (separadas por |)</label>
      <input v-model="f.aceptadas" class="campo" />
    </div>
    <div v-else-if="f.tipo === 'prediccion_salida'" class="col-span-3">
      <label class="etiqueta">Salida exacta esperada</label>
      <textarea v-model="f.salida" class="campo h-20 font-mono" />
    </div>
    <div v-else-if="f.tipo === 'parsons'" class="col-span-3">
      <label class="etiqueta">Líneas en el orden correcto (una por renglón)</label>
      <textarea v-model="f.lineasTexto" class="campo h-32 font-mono" />
    </div>
    <template v-else>
      <div>
        <label class="etiqueta">Lenguaje</label>
        <select v-model="f.lenguaje" class="campo">
          <option value="c">C</option>
          <option value="cpp">C++</option>
          <option value="python">Python</option>
        </select>
      </div>
      <div class="col-span-2">
        <label class="etiqueta">Código inicial (opcional)</label>
        <textarea v-model="f.codigoInicial" class="campo h-20 font-mono" />
      </div>
      <div class="col-span-3">
        <label class="etiqueta"
          >Solución de referencia (debe terminar con código de salida 0)</label
        >
        <textarea v-model="f.solucion" class="campo h-40 font-mono" required />
      </div>
      <div class="col-span-3 space-y-2">
        <p class="etiqueta">Casos de prueba</p>
        <div v-for="(c, i) in f.casos" :key="i" class="grid grid-cols-5 gap-2">
          <textarea
            v-model="c.entrada"
            class="campo col-span-2 h-16 font-mono"
            placeholder="Entrada"
          />
          <textarea
            v-model="c.salida_esperada"
            class="campo col-span-2 h-16 font-mono"
            placeholder="Salida esperada"
          />
          <label class="flex items-center gap-1 text-sm">
            <input v-model="c.oculto" type="checkbox" /> Oculto
          </label>
        </div>
        <button
          type="button"
          class="btn-sec"
          @click="f.casos.push({ entrada: '', salida_esperada: '', oculto: true })"
        >
          + Caso
        </button>
      </div>
    </template>

    <div class="col-span-3 flex items-center gap-4">
      <button class="btn">Guardar ítem</button>
      <span class="text-sm text-slate-600">{{ aviso }}</span>
      <span class="text-sm text-red-600">{{ error }}</span>
    </div>
  </form>

  <table class="tarjeta w-full text-sm">
    <thead class="text-left text-slate-500">
      <tr>
        <th class="py-1">Enunciado</th>
        <th>Tipo</th>
        <th>Nivel</th>
        <th>Estado</th>
        <th />
      </tr>
    </thead>
    <tbody>
      <tr v-for="i in items" :key="i.id" class="border-t border-slate-100 align-top">
        <td class="max-w-md truncate py-2">{{ i.enunciado.md }}</td>
        <td>{{ i.tipo }}</td>
        <td>{{ i.nivel }}</td>
        <td>
          {{ i.estado }}
          <span
            v-if="i.tipo === 'programacion'"
            :class="i.verificado_at ? 'text-green-700' : 'text-amber-700'"
          >
            · {{ i.verificado_at ? 'verificado' : 'sin verificar' }}
          </span>
        </td>
        <td class="space-x-2 text-right">
          <button v-if="i.tipo === 'programacion'" class="underline" @click="verificar(i)">
            Verificar
          </button>
          <button v-if="i.estado === 'borrador'" class="underline" @click="aprobar(i)">
            Aprobar
          </button>
        </td>
      </tr>
    </tbody>
  </table>
</template>
