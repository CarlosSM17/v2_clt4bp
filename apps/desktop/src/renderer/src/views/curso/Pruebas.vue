<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import type { Item, Prueba } from '@shared/tipos'
import { api } from '../../lib/api'
import PestanasCurso from '../../components/PestanasCurso.vue'

const props = defineProps<{ id: string }>()

const pruebas = ref<Prueba[]>([])
const aprobados = ref<Item[]>([])
const error = ref('')
const f = reactive({
  nombre: '',
  momento: 'pre',
  tipo: 'teorica',
  forma: 'A',
  tiempo_limite_min: 40
})
const elegidos = reactive<Record<number, number>>({}) // item_id => puntos

// En una prueba teórica solo caben ítems de recall o comprensión; en una práctica, de programación
const disponibles = computed(() =>
  aprobados.value.filter((i) =>
    f.tipo === 'practica' ? i.nivel === 'practica' : i.nivel !== 'practica'
  )
)

async function cargar(): Promise<void> {
  pruebas.value = await api<Prueba[]>('GET', `/courses/${props.id}/assessments`)
  aprobados.value = (await api<Item[]>('GET', `/courses/${props.id}/items`)).filter(
    (i) => i.estado === 'aprobado'
  )
}

function alternar(item: Item): void {
  if (elegidos[item.id]) delete elegidos[item.id]
  else elegidos[item.id] = item.nivel === 'practica' ? 10 : 1
}

async function crear(): Promise<void> {
  error.value = ''
  const items = Object.entries(elegidos).map(([id, puntos]) => ({ id: Number(id), puntos }))
  try {
    await api('POST', `/courses/${props.id}/assessments`, { ...f, items })
    Object.keys(elegidos).forEach((k) => delete elegidos[Number(k)])
    f.nombre = ''
    await cargar()
  } catch (e) {
    error.value = (e as Error).message
  }
}

onMounted(cargar)
</script>

<template>
  <h1 class="mb-2 text-2xl font-semibold">Pruebas</h1>
  <PestanasCurso :id="id" />

  <table class="tarjeta mb-6 w-full text-sm">
    <thead class="text-left text-slate-500">
      <tr>
        <th class="py-1">Nombre</th>
        <th>Momento</th>
        <th>Tipo</th>
        <th>Forma</th>
        <th>Ítems</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="p in pruebas" :key="p.id" class="border-t border-slate-100">
        <td class="py-2">{{ p.nombre }}</td>
        <td>{{ p.momento }}</td>
        <td>{{ p.tipo }}</td>
        <td>{{ p.forma }}</td>
        <td>{{ p.items_count }}</td>
      </tr>
    </tbody>
  </table>

  <form class="tarjeta grid grid-cols-5 gap-4" @submit.prevent="crear">
    <div class="col-span-2">
      <label class="etiqueta">Nombre</label>
      <input v-model="f.nombre" class="campo" required />
    </div>
    <div>
      <label class="etiqueta">Momento</label>
      <select v-model="f.momento" class="campo">
        <option value="pre">Pre</option>
        <option value="post">Post</option>
      </select>
    </div>
    <div>
      <label class="etiqueta">Tipo</label>
      <select v-model="f.tipo" class="campo">
        <option value="teorica">Teórica</option>
        <option value="practica">Práctica</option>
      </select>
    </div>
    <div>
      <label class="etiqueta">Forma</label>
      <select v-model="f.forma" class="campo">
        <option>A</option>
        <option>B</option>
      </select>
    </div>
    <div>
      <label class="etiqueta">Minutos</label>
      <input v-model.number="f.tiempo_limite_min" type="number" class="campo" />
    </div>

    <div class="col-span-5">
      <p class="etiqueta">Ítems aprobados ({{ disponibles.length }})</p>
      <div
        v-for="i in disponibles"
        :key="i.id"
        class="flex items-center gap-3 border-t border-slate-100 py-1 text-sm"
      >
        <input type="checkbox" :checked="!!elegidos[i.id]" @change="alternar(i)" />
        <span class="flex-1 truncate">{{ i.enunciado.md }}</span>
        <span class="text-slate-500">{{ i.nivel }}</span>
        <input
          v-if="elegidos[i.id]"
          v-model.number="elegidos[i.id]"
          type="number"
          min="0.5"
          step="0.5"
          class="campo w-20"
        />
      </div>
    </div>
    <div class="col-span-5 flex items-center gap-4">
      <button class="btn" :disabled="!Object.keys(elegidos).length">Crear prueba</button>
      <span class="text-sm text-red-600">{{ error }}</span>
    </div>
  </form>
</template>
