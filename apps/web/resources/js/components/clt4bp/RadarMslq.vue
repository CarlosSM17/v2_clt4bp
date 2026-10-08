<script setup lang="ts">
// Radar de subescalas del MSLQ (escala 1–7): diagnóstico contra evaluación final, dibujado en SVG sin librerías
import { computed } from 'vue';

export type Eje = { clave: string; nombre: string; pre: number | null; post: number | null };

const props = withDefaults(defineProps<{ ejes: Eje[]; min?: number; max?: number }>(), { min: 1, max: 7 });

const C = 150; // centro
const R = 110; // radio del valor máximo

function punto(i: number, valor: number): [number, number] {
    const angulo = -Math.PI / 2 + (i * 2 * Math.PI) / props.ejes.length;
    const r = (R * (valor - props.min)) / (props.max - props.min);
    return [C + r * Math.cos(angulo), C + r * Math.sin(angulo)];
}

function poligono(valores: (number | null)[]): string | null {
    if (valores.some((v) => v === null)) return null; // sin el cuestionario completo no se dibuja
    return valores.map((v, i) => punto(i, v as number).join(',')).join(' ');
}

const anillos = computed(() => [2, 3, 4, 5, 6, 7].map((v) => props.ejes.map((_, i) => punto(i, v).join(',')).join(' ')));
const pre = computed(() => poligono(props.ejes.map((e) => e.pre)));
const post = computed(() => poligono(props.ejes.map((e) => e.post)));
</script>

<template>
    <figure class="flex flex-col items-center gap-2">
        <svg viewBox="0 0 300 300" class="w-full max-w-sm" role="img" aria-label="Radar de motivación y estrategias: inicio contra final">
            <polygon v-for="(a, i) in anillos" :key="i" :points="a" fill="none" class="stroke-muted-foreground/20" />
            <g v-for="(e, i) in ejes" :key="e.clave">
                <line :x1="C" :y1="C" :x2="punto(i, max)[0]" :y2="punto(i, max)[1]" class="stroke-muted-foreground/30" />
                <text :x="punto(i, max + 0.7)[0]" :y="punto(i, max + 0.7)[1]" text-anchor="middle" dominant-baseline="middle" class="fill-muted-foreground text-[10px]">
                    {{ i + 1 }}
                </text>
            </g>
            <polygon v-if="pre" :points="pre" class="fill-muted-foreground/10 stroke-muted-foreground" stroke-dasharray="4 3" />
            <polygon v-if="post" :points="post" class="fill-primary/20 stroke-primary" stroke-width="2" />
        </svg>
        <figcaption class="flex gap-4 text-xs text-muted-foreground">
            <span><span class="inline-block w-4 border-t-2 border-dashed border-muted-foreground align-middle" /> Al inicio</span>
            <span v-if="post"><span class="inline-block w-4 border-t-2 border-primary align-middle" /> Al final</span>
        </figcaption>
    </figure>
</template>
