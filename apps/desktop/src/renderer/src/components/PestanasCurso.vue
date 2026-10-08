<script setup lang="ts">
defineProps<{ id: string }>()

const pestanas = [
  { nombre: 'curso', texto: 'Inscripciones' },
  { nombre: 'curso-items', texto: 'Banco de ítems' },
  { nombre: 'curso-pruebas', texto: 'Pruebas' },
  { nombre: 'curso-diagnostico', texto: 'Diagnóstico y grupos' },
  { nombre: 'curso-materiales', texto: 'Materiales' },
  { nombre: 'curso-estudio', texto: 'Estudio de diseño' },
  { nombre: 'curso-publicacion', texto: 'Publicación' },
  { nombre: 'curso-tablero', texto: 'Seguimiento' },
  { nombre: 'curso-resultados', texto: 'Resultados' }
]
</script>

<template>
  <nav class="mb-6 flex gap-1 border-b border-slate-200 text-sm">
    <RouterLink
      v-for="p in pestanas"
      :key="p.nombre"
      :to="{ name: p.nombre, params: { id } }"
      class="-mb-px border-b-2 border-transparent px-3 py-2 hover:text-indigo-600"
      exact-active-class="border-indigo-600 font-medium text-indigo-700"
    >
      {{ p.texto }}
    </RouterLink>
  </nav>
</template>
