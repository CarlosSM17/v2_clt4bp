<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { fecha } from '@/lib/aula';
import { iniciarEventos, registrar } from '@/lib/eventos';

type Clase = {
    uid: string;
    orden: number;
    titulo: string;
    descripcion: string;
    estado: 'sin_programar' | 'programada' | 'bloqueada' | 'abierta' | 'cerrada';
    abre_at: string | null;
    cierra_at: string | null;
    tareas: number;
    completadas: number;
};

type EscalaCarga = { aplicacion_id: number; clase_uid: string; orden: number; titulo: string };
type EvaluacionFinal = { cierra_at: string | null; estado: string };

const props = defineProps<{
    curso: { id: number; titulo: string; lenguaje: string };
    grupo: string | null;
    publicacion: number;
    clases: Clase[];
    hayPractica: boolean;
    escalaCarga: EscalaCarga | null;
    evaluacionFinal: EvaluacionFinal | null;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] } });

onMounted(() => {
    iniciarEventos(props.curso.id);
    registrar('abrio_mapa', 'curso', String(props.curso.id));
});

function aviso(c: Clase): string {
    switch (c.estado) {
        case 'abierta':
            return c.cierra_at ? `Abierta hasta el ${fecha(c.cierra_at)}` : 'Abierta';
        case 'cerrada':
            return 'Cerrada: puedes consultarla, ya no enviar';
        case 'programada':
            return `Abre el ${fecha(c.abre_at)}`;
        case 'bloqueada':
            return 'Se abre al terminar la clase anterior';
        default:
            return 'Tu instructor aún no le pone fecha';
    }
}
</script>

<template>
    <Head :title="curso.titulo" />
    <div class="mx-auto flex max-w-3xl flex-col gap-6 p-4">
        <header>
            <h1 class="text-2xl font-semibold">{{ curso.titulo }}</h1>
            <p class="text-sm text-muted-foreground">
                <span v-if="grupo">{{ grupo }} · </span>Material versión {{ publicacion }} ·
                <Link :href="`/aula/${curso.id}/avance`" class="underline">Mi avance</Link>
            </p>
        </header>

        <!-- Etapa 6: evaluación final abierta y escala de carga de una clase terminada -->
        <Link
            v-if="evaluacionFinal && evaluacionFinal.estado !== 'concluido'"
            :href="`/cursos/${curso.id}/evaluacion-final`"
            class="rounded-xl border border-primary/40 bg-primary/5 p-4 text-sm"
        >
            <span class="font-medium">La evaluación final está abierta</span
            >{{ evaluacionFinal.cierra_at ? ` hasta el ${fecha(evaluacionFinal.cierra_at)}` : '' }}. Presenta las pruebas y los cuestionarios finales.
        </Link>
        <Link
            v-if="escalaCarga"
            :href="`/cursos/${curso.id}/cuestionarios/${escalaCarga.aplicacion_id}`"
            class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
        >
            Terminaste la clase {{ escalaCarga.orden }} («{{ escalaCarga.titulo }}»). ¿Cuánto te costó? Responde 10 preguntas rápidas sobre su
            carga (unos 2 minutos).
        </Link>

        <!-- Las clases de tareas son niveles: de lo simple a lo complejo -->
        <ol class="flex flex-col gap-3">
            <li v-for="c in clases" :key="c.uid">
                <component
                    :is="c.estado === 'abierta' || c.estado === 'cerrada' ? Link : 'div'"
                    :href="`/aula/${curso.id}/clases/${c.uid}`"
                    class="flex gap-4 rounded-xl border p-4"
                    :class="c.estado === 'abierta' ? 'hover:bg-muted/50' : 'opacity-70'"
                >
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 font-semibold">{{ c.orden }}</span>
                    <div class="flex flex-1 flex-col gap-1">
                        <p class="font-medium">{{ c.titulo }}</p>
                        <p class="text-sm text-muted-foreground">{{ c.descripcion }}</p>
                        <p class="text-xs">{{ aviso(c) }}</p>
                        <div v-if="c.tareas" class="mt-1 h-1.5 w-full rounded-full bg-muted" :aria-label="`${c.completadas} de ${c.tareas} tareas completadas`">
                            <div class="h-1.5 rounded-full bg-primary" :style="{ width: `${(100 * c.completadas) / c.tareas}%` }" />
                        </div>
                    </div>
                </component>
            </li>
        </ol>

        <Link v-if="hayPractica" :href="`/aula/${curso.id}/practica`" class="rounded-xl border border-dashed p-4 text-sm hover:bg-muted/50">
            Práctica rápida: ejercicios cortos para automatizar la sintaxis (opcionales)
        </Link>
    </div>
</template>
