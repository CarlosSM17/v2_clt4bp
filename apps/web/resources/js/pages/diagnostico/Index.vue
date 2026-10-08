<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

type Paso = {
    tipo: 'cuestionario' | 'prueba';
    id: number;
    titulo: string;
    estado: string;
};

const props = defineProps<{
    curso: { id: number; titulo: string };
    pasos: Paso[];
    estado: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] },
});

const etiquetas: Record<string, string> = {
    pendiente: 'Pendiente',
    en_progreso: 'En progreso',
    calificando: 'Calificando…',
    completo: 'Completo',
};

function enlace(p: Paso): string {
    return p.tipo === 'cuestionario'
        ? `/cursos/${props.curso.id}/cuestionarios/${p.id}`
        : `/cursos/${props.curso.id}/pruebas/${p.id}`;
}

// Mientras haya pruebas calificándose, recarga los pasos cada 5 segundos
let temporizador: number | undefined;
onMounted(() => {
    temporizador = window.setInterval(() => {
        if (props.pasos.some((p) => p.estado === 'calificando')) {
            router.reload({ only: ['pasos', 'estado'] });
        }
    }, 5000);
});
onBeforeUnmount(() => window.clearInterval(temporizador));
</script>

<template>
    <Head title="Diagnóstico inicial" />

    <div class="mx-auto flex max-w-2xl flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">
                {{ curso.titulo }}: diagnóstico inicial
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Antes de empezar el curso, responde estos cuestionarios y
                pruebas. Con ellos tu instructor prepara un material adecuado
                para ti. No cuentan para tu calificación.
            </p>
        </div>

        <p v-if="!pasos.length" class="text-sm text-muted-foreground">
            Tu instructor aún no publica el diagnóstico. Vuelve más tarde.
        </p>

        <ol class="flex flex-col gap-3">
            <li
                v-for="(p, i) in pasos"
                :key="`${p.tipo}-${p.id}`"
                class="flex items-center justify-between rounded-xl border p-4"
            >
                <div>
                    <p class="font-medium">{{ i + 1 }}. {{ p.titulo }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ etiquetas[p.estado] ?? p.estado }}
                    </p>
                </div>
                <Link
                    v-if="
                        p.estado === 'pendiente' || p.estado === 'en_progreso'
                    "
                    :href="enlace(p)"
                    class="rounded-md bg-primary px-3 py-1.5 text-sm text-primary-foreground"
                >
                    {{ p.estado === 'pendiente' ? 'Empezar' : 'Continuar' }}
                </Link>
            </li>
        </ol>

        <p
            v-if="estado === 'con_perfil'"
            class="rounded-md bg-green-50 p-3 text-sm text-green-800"
        >
            ¡Listo! Completaste el diagnóstico. Te avisaremos cuando tu material
            esté disponible.
        </p>
    </div>
</template>
