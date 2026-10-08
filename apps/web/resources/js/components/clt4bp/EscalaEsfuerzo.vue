<script setup lang="ts">
// Escala de esfuerzo mental de Paas (1992): un ítem, 9 puntos
defineEmits<{ elegir: [valor: number] }>();
const extremos: Record<number, string> = { 1: 'Muy, muy bajo', 5: 'Ni alto ni bajo', 9: 'Muy, muy alto' };
</script>

<template>
    <div class="rounded-xl border p-4">
        <p class="font-medium">¿Cuánto esfuerzo mental te costó esta tarea?</p>
        <div class="mt-3 grid grid-cols-9 gap-1" role="radiogroup" aria-label="Esfuerzo mental de 1 a 9">
            <button
                v-for="n in 9"
                :key="n"
                type="button"
                role="radio"
                :aria-checked="false"
                class="flex flex-col items-center rounded-md border py-2 text-sm hover:bg-muted focus-visible:ring-2"
                @click="$emit('elegir', n)"
            >
                <span class="font-semibold">{{ n }}</span>
                <span class="h-8 text-center text-[10px] leading-tight text-muted-foreground">{{ extremos[n] ?? '' }}</span>
            </button>
        </div>
    </div>
</template>
