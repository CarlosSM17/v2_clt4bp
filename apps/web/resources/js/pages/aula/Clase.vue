<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Markdown from '@/components/clt4bp/Markdown.vue';
import { COLOR_APOYO, type Medios, type NivelApoyo } from '@/lib/aula';
import { iniciarEventos, medirTiempo, registrar } from '@/lib/eventos';

type Pieza = { uid: string; tipo: string; titulo: string; cuerpo_md: string };
type TareaResumen = {
    uid: string;
    orden: number;
    titulo: string;
    nivel_apoyo: NivelApoyo;
    etiqueta_apoyo: string;
    tiempo_estimado_min: number | null;
    papel: string | null;
    rutas: string[];
    estado: 'sin_empezar' | 'en_progreso' | 'completada';
    mejor_fraccion: number | null;
};

const props = defineProps<{
    curso: { id: number; titulo: string };
    clase: { uid: string; orden: number; titulo: string; descripcion: string };
    estado: 'abierta' | 'cerrada';
    soporte: Pieza[];
    procedimental: Pieza[];
    protocolo: Pieza[];
    tareas: TareaResumen[];
    rutas: Record<string, string>;
    medios: Medios;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] },
});

// Como en el mapa de ruta: la primera pieza del soporte abierta; lo demás, a un clic (siempre disponible para consulta)
const abiertas = ref(new Set(props.soporte.slice(0, 1).map((s) => s.uid)));
function alternar(
    p: Pieza,
    verbo: 'vio_soporte' | 'consulto_ayuda',
    objeto: string,
): void {
    if (abiertas.value.has(p.uid)) {
        abiertas.value.delete(p.uid);
    } else {
        abiertas.value.add(p.uid);
        registrar(verbo, objeto, p.uid);
    }
}

let cerrarMedicion: (() => void) | null = null;
onMounted(() => {
    iniciarEventos(props.curso.id);
    registrar('abrio_clase', 'clase', props.clase.uid);
    cerrarMedicion = medirTiempo('clase', props.clase.uid);
});
onBeforeUnmount(() => cerrarMedicion?.());

const ICONO = { sin_empezar: '○', en_progreso: '◐', completada: '●' } as const;
const SECCIONES = [
    {
        clave: 'soporte',
        numero: 1,
        titulo: 'Información de soporte',
        nota: 'Léela antes de las tareas; queda disponible todo el tema.',
        color: 'border-blue-500',
        verbo: 'vio_soporte',
        objeto: 'soporte',
    },
    {
        clave: 'procedimental',
        numero: 2,
        titulo: 'Información procedimental',
        nota: 'Tenla a la vista y consúltala justo a tiempo mientras resuelves las tareas.',
        color: 'border-violet-500',
        verbo: 'consulto_ayuda',
        objeto: 'procedimental',
    },
] as const;
const nombreRuta = (claves: string[]): string =>
    claves.map((c) => props.rutas[c] ?? c).join(' · ');
</script>

<template>
    <Head :title="clase.titulo" />
    <div class="mx-auto flex max-w-3xl flex-col gap-8 p-4">
        <header>
            <Link
                :href="`/aula/${curso.id}`"
                class="text-sm text-muted-foreground"
                >← {{ curso.titulo }}</Link
            >
            <h1 class="mt-1 text-2xl font-semibold">
                {{ clase.orden }}. {{ clase.titulo }}
            </h1>
            <p class="text-muted-foreground">{{ clase.descripcion }}</p>
            <p
                v-if="estado === 'cerrada'"
                class="mt-2 rounded-md bg-muted px-3 py-2 text-sm"
            >
                Esta clase ya cerró: puedes consultarla, pero no enviar.
            </p>
        </header>

        <template v-for="s in SECCIONES" :key="s.clave">
            <section v-if="props[s.clave].length" class="flex flex-col gap-2">
                <h2
                    class="border-l-4 pl-3 text-xl font-semibold"
                    :class="s.color"
                >
                    {{ s.numero }}. {{ s.titulo }}
                </h2>
                <p class="text-sm text-muted-foreground">{{ s.nota }}</p>
                <article
                    v-for="p in props[s.clave]"
                    :key="p.uid"
                    class="rounded-xl border"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between p-4 text-left font-medium"
                        :aria-expanded="abiertas.has(p.uid)"
                        @click="alternar(p, s.verbo, s.objeto)"
                    >
                        {{ p.titulo }}
                        <span aria-hidden="true">{{
                            abiertas.has(p.uid) ? '−' : '+'
                        }}</span>
                    </button>
                    <div v-if="abiertas.has(p.uid)" class="border-t p-4">
                        <Markdown
                            :md="p.cuerpo_md"
                            :medios="medios"
                            @segmento="
                                (uid, t) =>
                                    registrar(
                                        'reprodujo_segmento',
                                        'medio',
                                        uid,
                                        { resultado: { inicio_s: t } },
                                    )
                            "
                        />
                    </div>
                </article>
            </section>
        </template>

        <section class="flex flex-col gap-2">
            <h2
                class="border-l-4 border-emerald-500 pl-3 text-xl font-semibold"
            >
                3. Tareas de aprendizaje
            </h2>
            <p class="text-sm text-muted-foreground">
                Resuélvelas en orden: la ayuda disminuye de una tarea a la
                siguiente y la etiqueta te dice cuánta tienes.
            </p>
            <Link
                v-for="t in tareas"
                :key="t.uid"
                :href="`/aula/${curso.id}/tareas/${t.uid}`"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border p-4 hover:bg-muted/50"
            >
                <span class="text-lg" :aria-label="t.estado">{{
                    ICONO[t.estado]
                }}</span>
                <span class="flex-1 font-medium"
                    >T{{ t.orden }}. {{ t.titulo }}</span
                >
                <span class="flex flex-wrap items-center gap-1.5 text-xs">
                    <span
                        v-if="t.rutas.length"
                        class="rounded-full bg-sky-100 px-2 py-0.5 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                        >{{ nombreRuta(t.rutas) }}</span
                    >
                    <span
                        class="rounded-full px-2 py-0.5"
                        :class="COLOR_APOYO[t.nivel_apoyo]"
                        >{{ t.etiqueta_apoyo }}</span
                    >
                    <span
                        v-if="t.papel"
                        class="rounded-full bg-violet-100 px-2 py-0.5 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200"
                        >{{ t.papel }}</span
                    >
                    <span
                        v-if="t.tiempo_estimado_min"
                        class="text-muted-foreground"
                        >⏱ {{ t.tiempo_estimado_min }} min</span
                    >
                </span>
            </Link>
        </section>

        <section v-if="protocolo.length" class="flex flex-col gap-2">
            <h2 class="border-l-4 border-orange-500 pl-3 text-xl font-semibold">
                4. Protocolo verbal
            </h2>
            <p class="text-sm text-muted-foreground">
                El cómo y el porqué de un experto, en voz alta: avanza a tu
                ritmo.
            </p>
            <article
                v-for="p in protocolo"
                :key="p.uid"
                class="rounded-xl border"
            >
                <button
                    type="button"
                    class="flex w-full items-center justify-between p-4 text-left font-medium"
                    :aria-expanded="abiertas.has(p.uid)"
                    @click="alternar(p, 'consulto_ayuda', 'procedimental')"
                >
                    {{ p.titulo }}
                    <span aria-hidden="true">{{
                        abiertas.has(p.uid) ? '−' : '+'
                    }}</span>
                </button>
                <div v-if="abiertas.has(p.uid)" class="border-t p-4">
                    <Markdown :md="p.cuerpo_md" :medios="medios" />
                </div>
            </article>
        </section>
    </div>
</template>
