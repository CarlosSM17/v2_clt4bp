<script setup lang="ts">
import { cpp } from '@codemirror/lang-cpp'
import { python } from '@codemirror/lang-python'
import { computed } from 'vue'
import { Codemirror } from 'vue-codemirror'

const props = defineProps<{ lenguaje: string | null; soloLectura?: boolean; alto?: string }>()
const codigo = defineModel<string>({ required: true })
const extensiones = computed(() => [props.lenguaje === 'python' ? python() : cpp()])
</script>

<template>
  <Codemirror
    v-model="codigo"
    :extensions="extensiones"
    :disabled="soloLectura"
    :indent-with-tab="true"
    :tab-size="4"
    :style="{ minHeight: alto ?? '200px', fontSize: '13px' }"
    class="overflow-hidden rounded-md border border-slate-300"
  />
</template>
