<script setup lang="ts">
import type { Tarea } from '@shared/contracts'
import EditorCodigo from '../EditorCodigo.vue'
import EditorMarkdown from '../EditorMarkdown.vue'
import EditorCasos from './EditorCasos.vue'
import { useDiseno } from '../../stores/diseno'

const t = defineModel<Tarea>({ required: true })
const estudio = useDiseno()

// Rutas (mapa de ruta, ADR 0007): sin marcar ninguna, la tarea es para todos los grupos
function alternarRuta(clave: string): void {
  const rutas = new Set(t.value.rutas ?? [])
  if (rutas.has(clave)) rutas.delete(clave)
  else rutas.add(clave)
  t.value.rutas = [...rutas].sort()
}

const ayudaCodigo: Record<Tarea['nivel_apoyo'], string> = {
  ejemplo_resuelto: 'El ejemplo que se estudia, comentado; la solución y los casos son los de su gemelo (Paso 2).',
  por_completar: 'La solución con huecos: marca cada uno como /* HUECO 1: pista */.',
  convencional: 'Normalmente vacío (o solo el esqueleto mínimo).',
  solucion_libre: 'El programa que el estudiante explorará sin una meta específica.'
}
</script>

<template>
  <div class="grid grid-cols-4 gap-4">
    <div class="col-span-2"><label class="etiqueta">Título</label><input v-model="t.titulo" class="campo" /></div>
    <div>
      <label class="etiqueta">Nivel de apoyo</label>
      <select v-model="t.nivel_apoyo" class="campo">
        <option value="ejemplo_resuelto">Ejemplo resuelto</option>
        <option value="por_completar">Problema por completar</option>
        <option value="convencional">Problema convencional</option>
        <option value="solucion_libre">Solución libre</option>
      </select>
    </div>
    <div><label class="etiqueta">Orden</label><input v-model.number="t.orden" type="number" min="1" class="campo" /></div>

    <EditorMarkdown v-model="t.enunciado_md" etiqueta="Enunciado (escenario auténtico)" class="col-span-4" />

    <div>
      <label class="etiqueta">Lenguaje</label>
      <select v-model="t.lenguaje" class="campo">
        <option value="c">C</option>
        <option value="cpp">C++</option>
        <option value="python">Python</option>
      </select>
    </div>
    <label class="flex items-center gap-2 pt-6 text-sm"><input v-model="t.pide_autoexplicacion" type="checkbox" /> Pide auto-explicación</label>
    <label class="flex items-center gap-2 pt-6 text-sm"><input v-model="t.colaborativa" type="checkbox" /> Colaborativa (pareja)</label>
    <div>
      <p class="etiqueta">Rutas</p>
      <p v-if="!estudio.grupos.length" class="text-xs text-slate-500">Sin grupos: para todos.</p>
      <label v-for="g in estudio.grupos" :key="g.clave" class="mr-3 inline-flex items-center gap-1 text-sm">
        <input type="checkbox" :checked="(t.rutas ?? []).includes(g.clave)" @change="alternarRuta(g.clave)" /> {{ g.nombre }}
      </label>
      <p v-if="estudio.grupos.length && !(t.rutas ?? []).length" class="text-xs text-slate-500">Ninguna marcada: todos los grupos.</p>
    </div>

    <div class="col-span-2">
      <p class="etiqueta">Código inicial</p>
      <EditorCodigo v-model="t.codigo_inicial" :lenguaje="t.lenguaje" />
      <p class="mt-1 text-xs text-slate-500">{{ ayudaCodigo[t.nivel_apoyo] }}</p>
    </div>
    <div class="col-span-2">
      <p class="etiqueta">Solución de referencia</p>
      <EditorCodigo v-model="t.solucion" :lenguaje="t.lenguaje" />
      <p class="mt-1 text-xs text-slate-500">Debe pasar todos los casos; el verificador la ejecuta en el servidor.</p>
    </div>

    <EditorCasos v-model="t.casos_prueba" class="col-span-4" />
  </div>
</template>
