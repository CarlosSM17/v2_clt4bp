<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { fecha } from '@/lib/aula';

type Paso = { tipo: 'cuestionario' | 'prueba'; id: number; titulo: string; estado: string };

const props = defineProps<{
    curso: { id: number; titulo: string };
    ventana: { abre_at: string; cierra_at: string | null } | null;
    abierta: boolean;
    pasos: Paso[];
    estado: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] } });

const etiquetas: Record<string, string> = { pendiente: 'Pendiente', en_progreso: 'En progreso', calificando: 'Calificando…', completo: 'Completo' };

// Las mismas páginas del diagnóstico: el controlador sabe regresar aquí porque el paso es «post»
function enlace(p: Paso): string {
    return p.tipo === 'cuestionario' ? `/cursos/${props.curso.id}/cuestionarios/${p.id}` : `/cursos/${props.curso.id}/pruebas/${p.id}`;
}

let temporizador: number | undefined;
onMounted(() => {
    temporizador = window.setInterval(() => {
        if (props.pasos.some((p) => p.estado === 'calificando')) router.reload({ only: ['pasos', 'estado'] });
    }, 5000);
});
onBeforeUnmount(() => window.clearInterval(temporizador));
</script>

<template>
    <Head title="Evaluación final" />
    <div class="mx-auto flex max-w-2xl flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">{{ curso.titulo }}: evaluación final</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Las mismas partes del diagnóstico, en una versión equivalente. Con ellas ves cuánto avanzaste y tu instructor
                mejora el curso para el siguiente grupo.
            </p>
            <p v-if="ventana?.cierra_at && abierta" class="mt-1 text-sm">Disponible hasta el {{ fecha(ventana.cierra_at) }}.</p>
        </div>

        <p v-if="!abierta && estado !== 'concluido'" class="rounded-md bg-muted p-3 text-sm">
            {{ ventana && new Date(ventana.abre_at) > new Date() ? `Se abre el ${fecha(ventana.abre_at)}.` : 'La evaluación final no está abierta.' }}
        </p>

        <ol v-if="pasos.length" class="flex flex-col gap-3">
            <li v-for="(p, i) in pasos" :key="`${p.tipo}-${p.id}`" class="flex items-center justify-between rounded-xl border p-4">
                <div>
                    <p class="font-medium">{{ i + 1 }}. {{ p.titulo }}</p>
                    <p class="text-sm text-muted-foreground">{{ etiquetas[p.estado] ?? p.estado }}</p>
                </div>
                <Link
                    v-if="p.estado === 'pendiente' || p.estado === 'en_progreso'"
                    :href="enlace(p)"
                    class="rounded-md bg-primary px-3 py-1.5 text-sm text-primary-foreground"
                >
                    {{ p.estado === 'pendiente' ? 'Empezar' : 'Continuar' }}
                </Link>
            </li>
        </ol>

        <div v-if="estado === 'concluido'" class="rounded-md bg-green-50 p-3 text-sm text-green-800">
            ¡Terminaste el curso! Tus resultados están en
            <Link :href="`/aula/${curso.id}/avance`" class="font-medium underline">Mi avance</Link>.
        </div>
    </div>
</template>
