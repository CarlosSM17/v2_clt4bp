<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import RadarMslq, { type Eje } from '@/components/clt4bp/RadarMslq.vue';

type Clase = { orden: number; titulo: string; estado: string; total: number; completadas: number };
type Par = { pre: number | null; post: number | null };
type Medida = 'recall' | 'comprension' | 'practico';

const props = defineProps<{
    curso: { id: number; titulo: string };
    clases: Clase[];
    prePost: Record<Medida, Par>;
    mslq: Eje[];
    esfuerzo: { etiqueta: string; titulo: string; valor: number }[];
    logros: { arcs: 'confianza' | 'satisfaccion'; texto: string }[];
    evaluacionFinal: boolean;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] } });

const PARTES: { clave: Medida; nombre: string }[] = [
    { clave: 'recall', nombre: 'Recordar conceptos' },
    { clave: 'comprension', nombre: 'Comprender código' },
    { clave: 'practico', nombre: 'Programar (práctica)' },
];

// Explicación en lenguaje sencillo de cada subescala del radar (el número es su eje)
const EXPLICA: Record<string, string> = {
    intrinseca: 'Aprender por el gusto de entender, no solo por la calificación.',
    valor_tarea: 'Qué tan útil e interesante te parece lo que aprendes.',
    autoeficacia: 'Qué tan capaz te sientes de aprender y resolver los problemas.',
    control: 'Creer que lo que aprendes depende de tu esfuerzo.',
    elaboracion: 'Relacionar lo nuevo con lo que ya sabes.',
    organizacion: 'Ordenar la información en esquemas o resúmenes.',
    pensamiento_critico: 'Cuestionar y evaluar lo que aprendes.',
    metacognicion: 'Planear, vigilar y ajustar cómo estudias.',
    tiempo_ambiente: 'Organizar tu tiempo y tu lugar de estudio.',
    regulacion_esfuerzo: 'Seguir adelante aunque la tarea sea difícil o aburrida.',
};

const hayMslq = computed(() => props.mslq.some((e) => e.pre !== null));
const hayPost = computed(() => PARTES.some((p) => props.prePost[p.clave].post !== null));

// Tendencia del esfuerzo mental (1–9) en el orden del curso
const W = 560;
const H = 160;
const y = (valor: number): number => H - 15 - ((valor - 1) / 8) * (H - 30);
const puntos = computed(() =>
    props.esfuerzo.map((e, i) => ({ ...e, x: 20 + (i * (W - 40)) / Math.max(1, props.esfuerzo.length - 1), y: y(e.valor) })),
);
const linea = computed(() => puntos.value.map((p) => `${p.x},${p.y}`).join(' '));
</script>

<template>
    <Head :title="`Mi avance · ${curso.titulo}`" />
    <div class="mx-auto flex max-w-3xl flex-col gap-8 p-4">
        <header>
            <h1 class="text-2xl font-semibold">Mi avance</h1>
            <p class="text-sm text-muted-foreground">{{ curso.titulo }} · Solo tú ves esta página.</p>
        </header>

        <Link v-if="evaluacionFinal" :href="`/cursos/${curso.id}/evaluacion-final`" class="rounded-xl border border-primary/40 bg-primary/5 p-4 text-sm">
            La evaluación final está abierta. <span class="font-medium underline">Ir a la evaluación final</span>
        </Link>

        <section v-if="logros.length" aria-labelledby="t-logros">
            <h2 id="t-logros" class="mb-2 font-medium">Tus logros</h2>
            <ul class="flex flex-col gap-2">
                <li v-for="(l, i) in logros" :key="i" class="flex items-start gap-3 rounded-lg border p-3 text-sm">
                    <span class="rounded-full px-2 py-0.5 text-xs" :class="l.arcs === 'confianza' ? 'bg-sky-100 text-sky-800' : 'bg-emerald-100 text-emerald-800'">
                        {{ l.arcs === 'confianza' ? 'Confianza' : 'Satisfacción' }}
                    </span>
                    {{ l.texto }}
                </li>
            </ul>
        </section>

        <section aria-labelledby="t-clases">
            <h2 id="t-clases" class="mb-2 font-medium">Avance por clase de tareas</h2>
            <ul class="flex flex-col gap-3">
                <li v-for="c in clases" :key="c.orden" class="text-sm">
                    <div class="flex justify-between"><span>{{ c.orden }}. {{ c.titulo }}</span><span class="text-muted-foreground">{{ c.completadas }} de {{ c.total }}</span></div>
                    <div class="mt-1 h-2 rounded-full bg-muted" role="progressbar" :aria-valuenow="c.completadas" :aria-valuemax="c.total" :aria-label="c.titulo">
                        <div class="h-2 rounded-full bg-primary" :style="{ width: `${c.total ? (100 * c.completadas) / c.total : 0}%` }" />
                    </div>
                </li>
            </ul>
        </section>

        <section aria-labelledby="t-prepost">
            <h2 id="t-prepost" class="mb-1 font-medium">Diagnóstico contra evaluación final</h2>
            <p v-if="!hayPost" class="mb-2 text-sm text-muted-foreground">Cuando presentes la evaluación final verás aquí cuánto avanzaste.</p>
            <div class="flex flex-col gap-4">
                <div v-for="p in PARTES" :key="p.clave" class="text-sm">
                    <p class="mb-1">{{ p.nombre }}</p>
                    <div v-for="m in (['pre', 'post'] as const)" :key="m" class="flex items-center gap-2">
                        <span class="w-16 text-xs text-muted-foreground">{{ m === 'pre' ? 'Inicio' : 'Final' }}</span>
                        <div class="h-4 flex-1 rounded bg-muted">
                            <div
                                v-if="prePost[p.clave][m] !== null"
                                class="h-4 rounded"
                                :class="m === 'pre' ? 'bg-muted-foreground/50' : 'bg-primary'"
                                :style="{ width: `${prePost[p.clave][m]}%` }"
                            />
                        </div>
                        <span class="w-12 text-right text-xs">{{ prePost[p.clave][m] === null ? '—' : `${Math.round(prePost[p.clave][m] as number)} %` }}</span>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="hayMslq" aria-labelledby="t-mslq">
            <h2 id="t-mslq" class="mb-1 font-medium">Cómo aprendes: motivación y estrategias</h2>
            <p class="mb-3 text-sm text-muted-foreground">De 1 (nada cierto de mí) a 7 (totalmente cierto de mí). No hay respuestas buenas o malas: es para que te conozcas.</p>
            <div class="grid gap-4 md:grid-cols-2">
                <RadarMslq :ejes="mslq" />
                <ol class="flex flex-col gap-1 text-xs">
                    <li v-for="(e, i) in mslq" :key="e.clave">
                        <span class="font-medium">{{ i + 1 }}. {{ e.nombre }}</span>
                        <span class="text-muted-foreground"> ({{ e.pre ?? '—' }} → {{ e.post ?? '—' }}):</span> {{ EXPLICA[e.clave] }}
                    </li>
                </ol>
            </div>
        </section>

        <section v-if="esfuerzo.length > 1" aria-labelledby="t-esfuerzo">
            <h2 id="t-esfuerzo" class="mb-1 font-medium">Esfuerzo mental por tarea</h2>
            <p class="mb-2 text-sm text-muted-foreground">De 1 (muy bajo) a 9 (muy alto). Si baja mientras resuelves tareas del mismo nivel, estás automatizando.</p>
            <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" :aria-label="`Esfuerzo en ${esfuerzo.length} tareas`">
                <line v-for="v in [1, 5, 9]" :key="v" x1="20" :x2="W - 20" :y1="y(v)" :y2="y(v)" class="stroke-muted-foreground/20" />
                <polyline :points="linea" fill="none" class="stroke-primary" stroke-width="2" />
                <circle v-for="p in puntos" :key="p.etiqueta" :cx="p.x" :cy="p.y" r="4" class="fill-primary">
                    <title>{{ p.etiqueta }} · {{ p.titulo }}: {{ p.valor }}</title>
                </circle>
            </svg>
        </section>
    </div>
</template>
