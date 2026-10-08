<script setup lang="ts">
import { useRoute } from 'vue-router'
import Marco from './components/Marco.vue'

const route = useRoute()
</script>

<template>
  <RouterView v-if="route.meta.publica" />
  <Marco v-else>
    <RouterView />
  </Marco>
</template>
