<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { fecha } from '@/lib/aula';
import { dashboard } from '@/routes';

type Fecha = { iso: string };
type Paso = {
    tipo: 'espera' | 'diagnostico' | 'evaluacion' | 'tarea' | 'al_dia';
    texto: string;
    url: string | null;
};
type Clase = {
    uid: string;
    orden: number;
    titulo: string;
    estado: string;
    completadas: number;
    total: number;
    abre_at: Fecha | null;
    cierra_at: Fecha | null;
};
type Curso = {
    curso: { id: number; titulo: string; lenguaje: string };
    estado: string;
    ruta: string | null;
    siguiente: Paso;
    avance: { completadas: number; total: number } | null;
    clases: Clase[];
    fechas: { cuando: Fecha; texto: string }[];
};
type Imparte = {
    id: number;
    titulo: string;
    lenguaje: string;
    estado: string;
    inscritos: number;
    solicitudes: number;
};
type Aviso = {
    id: string;
    datos: { texto: string; url?: string };
    leido: boolean;
    fecha: string;
};

const props = defineProps<{
    nombre: string;
    cursos: Curso[];
    imparte: Imparte[];
    avisos: { sin_leer: number; recientes: Aviso[] };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Inicio', href: dashboard() }] },
});

const LENGUAJE: Record<string, string> = {
    c: 'C',
    cpp: 'C++',
    python: 'Python',
};
const ACCION: Record<Paso['tipo'], string> = {
    tarea: 'Continuar',
    diagnostico: 'Ir al diagnóstico',
    evaluacion: 'Ir a la evaluación',
    espera: 'Ver el curso',
    al_dia: 'Ver el curso',
};
const ESTADO_CLASE: Record<string, string> = {
    abierta: 'Abierta',
    programada: 'Próximamente',
    bloqueada: 'Termina la anterior',
    cerrada: 'Cerrada',
    sin_programar: 'Sin fecha',
};
const porcentaje = (c: number, t: number): number =>
    t ? Math.round((100 * c) / t) : 0;
const saludo = computed(() => props.nombre.split(' ')[0]);
const fechas = computed(() =>
    props.cursos
        .flatMap((c) => c.fechas.map((f) => ({ ...f, curso: c.curso.titulo })))
        .sort((a, b) => a.cuando.iso.localeCompare(b.cuando.iso))
        .slice(0, 6),
);
</script>

<template>
    <Head title="Inicio" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
        <div>
            <h1 class="text-2xl font-semibold">Hola, {{ saludo }}</h1>
            <p v-if="cursos.length" class="text-muted-foreground">
                Esto es lo que te toca en cada curso.
            </p>
        </div>

        <!-- Estudiante sin cursos -->
        <div
            v-if="!cursos.length && !imparte.length"
            class="rounded-xl border p-6"
        >
            <p class="font-medium">Aún no estás inscrito en ningún curso.</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Pide a tu instructor el código de inscripción y escríbelo en
                «Mis cursos».
            </p>
            <Button as-child class="mt-4"
                ><Link href="/cursos">Ir a Mis cursos</Link></Button
            >
        </div>

        <div v-if="cursos.length" class="grid gap-6 lg:grid-cols-3">
            <!-- Un bloque por curso: el siguiente paso arriba, luego el avance y las clases -->
            <div class="flex flex-col gap-4 lg:col-span-2">
                <section
                    v-for="c in cursos"
                    :key="c.curso.id"
                    class="rounded-xl border p-5"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h2 class="text-lg font-semibold">
                            {{ c.curso.titulo }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ LENGUAJE[c.curso.lenguaje] ?? c.curso.lenguaje
                            }}<template v-if="c.ruta"> · {{ c.ruta }}</template>
                        </p>
                    </div>

                    <div
                        class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg p-3"
                        :class="
                            c.siguiente.tipo === 'espera' ||
                            c.siguiente.tipo === 'al_dia'
                                ? 'bg-muted'
                                : 'bg-primary/10'
                        "
                    >
                        <p
                            class="text-sm"
                            :class="{
                                'font-medium': c.siguiente.tipo !== 'espera',
                            }"
                        >
                            {{ c.siguiente.texto }}
                        </p>
                        <Button
                            v-if="c.siguiente.url"
                            as-child
                            size="sm"
                            :variant="
                                c.siguiente.tipo === 'espera' ||
                                c.siguiente.tipo === 'al_dia'
                                    ? 'outline'
                                    : 'default'
                            "
                        >
                            <Link :href="c.siguiente.url">{{
                                ACCION[c.siguiente.tipo]
                            }}</Link>
                        </Button>
                    </div>

                    <template v-if="c.avance">
                        <div
                            class="mt-4 flex items-center justify-between text-sm"
                        >
                            <span>Tareas completadas</span>
                            <span class="font-medium"
                                >{{ c.avance.completadas }} de
                                {{ c.avance.total }}</span
                            >
                        </div>
                        <div class="mt-1 h-2 rounded-full bg-muted">
                            <div
                                class="h-2 rounded-full bg-primary"
                                :style="{
                                    width: `${porcentaje(c.avance.completadas, c.avance.total)}%`,
                                }"
                            />
                        </div>

                        <ul class="mt-4 divide-y text-sm">
                            <li
                                v-for="cl in c.clases"
                                :key="cl.uid"
                                class="flex items-center gap-3 py-2"
                            >
                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium"
                                    >{{ cl.orden }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate">{{ cl.titulo }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            ESTADO_CLASE[cl.estado] ?? cl.estado
                                        }}
                                        <template
                                            v-if="
                                                cl.estado === 'abierta' &&
                                                cl.cierra_at
                                            "
                                        >
                                            · cierra el
                                            {{
                                                fecha(cl.cierra_at.iso)
                                            }}</template
                                        >
                                        <template
                                            v-else-if="
                                                cl.estado === 'programada' &&
                                                cl.abre_at
                                            "
                                        >
                                            · abre el
                                            {{
                                                fecha(cl.abre_at.iso)
                                            }}</template
                                        >
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >{{ cl.completadas }}/{{ cl.total }}</span
                                >
                            </li>
                        </ul>

                        <div class="mt-3 flex gap-4 text-sm">
                            <Link
                                :href="`/aula/${c.curso.id}`"
                                class="underline"
                                >Mapa del curso</Link
                            >
                            <Link
                                :href="`/aula/${c.curso.id}/avance`"
                                class="underline"
                                >Mi avance</Link
                            >
                        </div>
                    </template>
                </section>
            </div>

            <!-- Columna lateral: fechas y avisos -->
            <div class="flex flex-col gap-4">
                <section class="rounded-xl border p-5">
                    <h2 class="font-semibold">Próximas fechas</h2>
                    <p
                        v-if="!fechas.length"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        Nada en las próximas dos semanas.
                    </p>
                    <ul v-else class="mt-2 space-y-2 text-sm">
                        <li v-for="(f, i) in fechas" :key="i">
                            <p class="font-medium">{{ fecha(f.cuando.iso) }}</p>
                            <p class="text-muted-foreground">
                                {{ f.texto }} · {{ f.curso }}
                            </p>
                        </li>
                    </ul>
                </section>

                <section class="rounded-xl border p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Avisos</h2>
                        <span
                            v-if="avisos.sin_leer"
                            class="rounded-full bg-primary px-2 text-xs text-primary-foreground"
                            >{{ avisos.sin_leer }} sin leer</span
                        >
                    </div>
                    <p
                        v-if="!avisos.recientes.length"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        No tienes avisos.
                    </p>
                    <ul v-else class="mt-2 space-y-2 text-sm">
                        <li
                            v-for="a in avisos.recientes"
                            :key="a.id"
                            :class="{ 'opacity-70': a.leido }"
                        >
                            <component
                                :is="a.datos.url ? Link : 'p'"
                                :href="a.datos.url"
                                :class="{ underline: a.datos.url }"
                                >{{ a.datos.texto }}</component
                            >
                            <p class="text-xs text-muted-foreground">
                                {{ fecha(a.fecha) }}
                            </p>
                        </li>
                    </ul>
                    <Link
                        href="/avisos"
                        class="mt-3 inline-block text-sm underline"
                        >Ver todos</Link
                    >
                </section>
            </div>
        </div>

        <!-- Equipo docente: el trabajo se hace en la consola -->
        <section v-if="imparte.length" class="rounded-xl border p-5">
            <h2 class="font-semibold">Cursos que impartes</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                El diseño, la publicación y el seguimiento se hacen en la
                consola del instructor.
            </p>
            <ul class="mt-3 divide-y text-sm">
                <li
                    v-for="c in imparte"
                    :key="c.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <span class="font-medium"
                        >{{ c.titulo }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ LENGUAJE[c.lenguaje] ?? c.lenguaje }}</span
                        ></span
                    >
                    <span class="text-muted-foreground">
                        {{ c.inscritos }} inscritos<template
                            v-if="c.solicitudes"
                        >
                            · {{ c.solicitudes }} solicitudes por
                            aprobar</template
                        >
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
