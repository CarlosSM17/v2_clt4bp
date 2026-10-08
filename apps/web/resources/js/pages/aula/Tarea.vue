<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EditorCodigo from '@/components/clt4bp/EditorCodigo.vue';
import EscalaEsfuerzo from '@/components/clt4bp/EscalaEsfuerzo.vue';
import Markdown from '@/components/clt4bp/Markdown.vue';
import HiloTarea from '@/components/clt4bp/HiloTarea.vue';
import { Button } from '@/components/ui/button';
import {
    COLOR_APOYO,
    fecha,
    type Caso,
    type Medios,
    type NivelApoyo,
    type Resultado,
} from '@/lib/aula';
import { iniciarEventos, medirTiempo, registrar } from '@/lib/eventos';
import { postJson } from '@/lib/http';

type Tarea = {
    uid: string;
    titulo: string;
    nivel_apoyo: NivelApoyo;
    etiqueta_apoyo: string;
    enunciado_md: string;
    arcs: { relevancia: string; confianza: string };
    lenguaje: string;
    codigo_inicial: string;
    ejemplos: Caso[];
    casos_ocultos: number;
    pide_autoexplicacion: boolean;
    colaborativa: boolean;
    papel: string | null;
    rutas: string[];
    tiempo_estimado_min: number | null;
};
type Ayuda = { uid: string; tipo: string; titulo: string; cuerpo_md: string };
type Envio = {
    id: number;
    numero: number;
    estado: 'en_cola' | 'calificado' | 'error';
    fraccion: number | null;
    created_at: string;
};
type Sugerencia = {
    decision: 'menos_apoyo' | 'continuar' | 'mas_apoyo';
    mensaje: string;
    tarea_uid: string | null;
};

const props = defineProps<{
    curso: { id: number; titulo: string };
    clase: { uid: string; titulo: string };
    tarea: Tarea;
    ayudas: Ayuda[];
    rutas: Record<string, string>;
    progreso: {
        estado: string;
        borrador_codigo: string | null;
        intentos: number;
        mejor_fraccion: number | null;
        esfuerzo: number | null;
        autoexplicacion: string | null;
    };
    envios: Envio[];
    puedeEnviar: boolean;
    anterior: string | null;
    siguiente: string | null;
    comentarios:
        | {
              id: number;
              texto: string;
              autor: string;
              mio: boolean;
              fecha: string;
          }[]
        | null;
    medios: Medios;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] },
});

const base = `/aula/${props.curso.id}/tareas/${props.tarea.uid}`;
const esEjemplo = props.tarea.nivel_apoyo === 'ejemplo_resuelto';

// ---------- Editor con autoguardado ----------
const codigo = ref(
    props.progreso.borrador_codigo ?? props.tarea.codigo_inicial,
);
let guardado: number | undefined;
watch(codigo, (nuevo) => {
    window.clearTimeout(guardado);
    guardado = window.setTimeout(
        () =>
            void postJson(`${base}/borrador`, { codigo: nuevo }).catch(
                () => undefined,
            ),
        3000,
    );
});

// ---------- Ejecutar con entrada libre ----------
const entrada = ref(props.tarea.ejemplos[0]?.entrada ?? '');
const salida = ref('');
const ejecutando = ref(false);
async function ejecutar(): Promise<void> {
    ejecutando.value = true;
    try {
        const r = await postJson<{
            compilo: boolean;
            salida: string;
            errores: string;
            excedio_limite: boolean;
        }>(`${base}/ejecutar`, {
            codigo: codigo.value,
            entrada: entrada.value,
        });
        salida.value = !r.compilo
            ? `Error de compilación:\n${r.errores}`
            : r.excedio_limite
              ? 'El programa rebasó el tiempo o la memoria permitidos (¿un ciclo que no termina?).'
              : r.salida + (r.errores ? `\n[stderr]\n${r.errores}` : '');
    } catch (e) {
        salida.value = (e as Error).message;
    } finally {
        ejecutando.value = false;
    }
}

// ---------- Enviar y esperar la calificación ----------
const autoexplicacion = ref(props.progreso.autoexplicacion ?? '');
const faltaExplicar = computed(
    () =>
        props.tarea.pide_autoexplicacion &&
        autoexplicacion.value.trim().length < 20,
);
const enviando = ref(false);
const resultado = ref<Resultado | null>(null);
const error = ref('');
const pedirEsfuerzo = ref(false);

async function enviar(): Promise<void> {
    enviando.value = true;
    error.value = '';
    resultado.value = null;
    try {
        const { id } = await postJson<{ id: number }>(`${base}/envios`, {
            codigo: codigo.value,
            autoexplicacion: autoexplicacion.value || null,
        });
        for (let i = 0; i < 40; i++) {
            await new Promise((ok) => setTimeout(ok, 1500));
            const e = await fetch(`/aula/envios/${id}`, {
                headers: { Accept: 'application/json' },
            }).then((r) => r.json());
            if (e.estado === 'calificado') {
                resultado.value = e.resultado;
                if (e.fraccion >= 1 && props.progreso.esfuerzo === null)
                    pedirEsfuerzo.value = true;
                break;
            }
            if (e.estado === 'error')
                throw new Error(
                    'No se pudo calificar tu envío. Intenta de nuevo en un momento.',
                );
        }
        router.reload({ only: ['progreso', 'envios'] });
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        enviando.value = false;
    }
}

async function completarEjemplo(): Promise<void> {
    enviando.value = true;
    error.value = '';
    try {
        await postJson(`${base}/completar`, {
            autoexplicacion: autoexplicacion.value || null,
        });
        if (props.progreso.esfuerzo === null) pedirEsfuerzo.value = true;
        router.reload({ only: ['progreso'] });
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        enviando.value = false;
    }
}

// Con la selección adaptativa activa (Etapa 6), la respuesta trae una sugerencia; si no, llega vacía
const sugerencia = ref<Sugerencia | null>(null);
async function valorarEsfuerzo(valor: number): Promise<void> {
    pedirEsfuerzo.value = false;
    const r = await postJson<{ sugerencia?: Sugerencia | null }>(
        `${base}/esfuerzo`,
        { valor },
    ).catch(() => ({ sugerencia: null }));
    sugerencia.value = r.sugerencia ?? null;
    router.reload({ only: ['progreso'] });
}

// ---------- Ayuda justo a tiempo ----------
const ayudaAbierta = ref<Ayuda | null>(null);
function abrirAyuda(a: Ayuda): void {
    ayudaAbierta.value = a;
    registrar('consulto_ayuda', 'ayuda', a.uid, {
        resultado: { tipo: a.tipo },
    });
}

let cerrarMedicion: (() => void) | null = null;
onMounted(() => {
    iniciarEventos(props.curso.id);
    registrar('abrio_tarea', 'tarea', props.tarea.uid);
    cerrarMedicion = medirTiempo('tarea', props.tarea.uid);
});
onBeforeUnmount(() => {
    cerrarMedicion?.();
    window.clearTimeout(guardado);
});

const segmento = (uid: string, t: number): void =>
    registrar('reprodujo_segmento', 'medio', uid, {
        resultado: { inicio_s: t },
    });
</script>

<template>
    <Head :title="tarea.titulo" />
    <div class="grid gap-6 p-4 lg:grid-cols-2">
        <!-- Izquierda: el problema -->
        <section class="flex flex-col gap-4">
            <div>
                <Link
                    :href="`/aula/${curso.id}/clases/${clase.uid}`"
                    class="text-sm text-muted-foreground"
                    >← {{ clase.titulo }}</Link
                >
                <h1 class="mt-1 text-2xl font-semibold">{{ tarea.titulo }}</h1>
                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                    <span
                        v-if="tarea.rutas.length"
                        class="rounded-full bg-sky-100 px-2 py-0.5 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                        >{{
                            tarea.rutas.map((c) => rutas[c] ?? c).join(' · ')
                        }}</span
                    >
                    <span
                        class="rounded-full px-2 py-0.5"
                        :class="COLOR_APOYO[tarea.nivel_apoyo]"
                        >{{ tarea.etiqueta_apoyo }}</span
                    >
                    <span
                        v-if="tarea.papel"
                        class="rounded-full bg-violet-100 px-2 py-0.5 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200"
                        >{{ tarea.papel }}</span
                    >
                    <span
                        v-if="tarea.tiempo_estimado_min"
                        class="text-muted-foreground"
                        >⏱ {{ tarea.tiempo_estimado_min }} min</span
                    >
                </div>
            </div>
            <div
                v-if="tarea.arcs.relevancia"
                class="rounded-lg bg-primary/5 p-3 text-sm"
            >
                <p class="font-medium">¿Para qué te sirve?</p>
                <p>{{ tarea.arcs.relevancia }}</p>
            </div>
            <Markdown
                :md="tarea.enunciado_md"
                :medios="medios"
                @segmento="segmento"
            />
            <p
                v-if="tarea.arcs.confianza"
                class="text-sm text-muted-foreground"
            >
                💡 {{ tarea.arcs.confianza }}
            </p>

            <div v-if="tarea.ejemplos.length" class="text-sm">
                <p class="font-medium">Ejemplos</p>
                <div
                    v-for="(e, k) in tarea.ejemplos"
                    :key="k"
                    class="mt-1 grid grid-cols-2 gap-2"
                >
                    <pre class="rounded bg-muted p-2 text-xs">{{
                        e.entrada
                    }}</pre>
                    <pre class="rounded bg-muted p-2 text-xs">{{
                        e.salida_esperada
                    }}</pre>
                </div>
                <p
                    v-if="tarea.casos_ocultos"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Al enviar se prueban además {{ tarea.casos_ocultos }} casos
                    que no se muestran.
                </p>
            </div>

            <div v-if="ayudas.length" class="flex flex-wrap gap-2">
                <Button
                    v-for="a in ayudas"
                    :key="a.uid"
                    variant="outline"
                    size="sm"
                    @click="abrirAyuda(a)"
                    >🛟 {{ a.titulo }}</Button
                >
            </div>

            <HiloTarea
                v-if="comentarios"
                :curso-id="curso.id"
                :tarea-uid="tarea.uid"
                :comentarios="comentarios"
            />
        </section>

        <!-- Derecha: el código -->
        <section class="flex flex-col gap-3">
            <p
                v-if="esEjemplo"
                class="rounded-md bg-emerald-50 p-2 text-sm text-emerald-900"
            >
                Estudia la solución: ejecútala, cambia datos y observa qué pasa.
            </p>
            <EditorCodigo v-model="codigo" :lenguaje="tarea.lenguaje" />
            <textarea
                v-model="entrada"
                class="h-16 rounded-md border px-3 py-1.5 font-mono text-sm"
                placeholder="Entrada para probar (stdin)"
            />
            <div class="flex gap-2">
                <Button
                    variant="outline"
                    :disabled="ejecutando"
                    @click="ejecutar"
                    >{{ ejecutando ? 'Ejecutando…' : 'Ejecutar' }}</Button
                >
            </div>
            <pre
                v-if="salida"
                class="max-h-48 overflow-auto rounded bg-black p-3 text-xs text-green-300"
                >{{ salida }}</pre>

            <label
                v-if="tarea.pide_autoexplicacion"
                class="flex flex-col gap-1 text-sm"
            >
                <span class="font-medium">Explica tu razonamiento</span>
                <span class="text-muted-foreground"
                    >¿Qué hace cada parte y por qué funciona? (mínimo 20
                    caracteres)</span
                >
                <textarea
                    v-model="autoexplicacion"
                    maxlength="3000"
                    class="h-24 rounded-md border px-3 py-2"
                />
            </label>

            <div v-if="puedeEnviar" class="flex items-center gap-3">
                <Button
                    v-if="esEjemplo"
                    :disabled="
                        enviando ||
                        faltaExplicar ||
                        progreso.estado === 'completada'
                    "
                    @click="completarEjemplo"
                >
                    {{
                        progreso.estado === 'completada'
                            ? 'Estudiado ✓'
                            : 'Terminé de estudiarlo'
                    }}
                </Button>
                <Button
                    v-else
                    :disabled="enviando || faltaExplicar"
                    @click="enviar"
                    >{{ enviando ? 'Calificando…' : 'Enviar' }}</Button
                >
                <span class="text-sm text-muted-foreground"
                    >Intentos: {{ progreso.intentos }}</span
                >
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Los envíos están cerrados para esta clase.
            </p>
            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

            <div v-if="resultado" class="rounded-xl border p-3 text-sm">
                <p class="font-medium">
                    {{
                        resultado.error_compilacion
                            ? 'No compila'
                            : `${resultado.aprobados} de ${resultado.total} casos correctos`
                    }}
                    <span v-if="resultado.fraccion >= 1"> 🎉</span>
                </p>
                <pre
                    v-if="resultado.error_compilacion"
                    class="mt-2 max-h-40 overflow-auto rounded bg-muted p-2 text-xs"
                    >{{ resultado.error_compilacion }}</pre>
                <ul class="mt-2 flex flex-col gap-1">
                    <li v-for="c in resultado.casos" :key="c.caso">
                        {{ c.aprobado ? '✓' : '✗' }} Caso {{ c.caso
                        }}{{ c.oculto ? ' (oculto)' : ''
                        }}{{ c.tiempo_excedido ? ' — tiempo excedido' : '' }}
                        <details
                            v-if="!c.aprobado && !c.oculto"
                            class="ml-5 text-xs"
                        >
                            <summary>Ver diferencia</summary>
                            <p>Entrada:</p>
                            <pre class="rounded bg-muted p-1">{{
                                c.entrada
                            }}</pre>
                            <p>Esperada:</p>
                            <pre class="rounded bg-muted p-1">{{
                                c.esperada
                            }}</pre>
                            <p>Tu programa:</p>
                            <pre class="rounded bg-muted p-1">{{
                                c.obtenida
                            }}</pre>
                        </details>
                    </li>
                </ul>
            </div>

            <EscalaEsfuerzo v-if="pedirEsfuerzo" @elegir="valorarEsfuerzo" />

            <div
                v-if="sugerencia && sugerencia.decision !== 'continuar'"
                class="rounded-xl border border-primary/40 bg-primary/5 p-3 text-sm"
                role="status"
            >
                <p>{{ sugerencia.mensaje }}</p>
                <Link
                    v-if="sugerencia.tarea_uid"
                    :href="`/aula/${curso.id}/tareas/${sugerencia.tarea_uid}`"
                    class="mt-1 inline-block font-medium underline"
                >
                    Ir a la tarea
                </Link>
            </div>

            <div v-if="envios.length" class="text-xs text-muted-foreground">
                <p class="font-medium">Tus últimos envíos</p>
                <p v-for="e in envios" :key="e.id">
                    #{{ e.numero }} · {{ fecha(e.created_at) }} ·
                    {{
                        e.estado === 'calificado'
                            ? `${Math.round((e.fraccion ?? 0) * 100)} %`
                            : e.estado
                    }}
                </p>
            </div>

            <nav class="mt-auto flex justify-between pt-4 text-sm">
                <Link
                    v-if="anterior"
                    :href="`/aula/${curso.id}/tareas/${anterior}`"
                    >← Tarea anterior</Link
                ><span v-else />
                <Link
                    v-if="siguiente"
                    :href="`/aula/${curso.id}/tareas/${siguiente}`"
                    >Siguiente tarea →</Link
                >
            </nav>
        </section>

        <!-- Panel lateral de ayuda (información procedimental) -->
        <aside
            v-if="ayudaAbierta"
            class="fixed inset-y-0 right-0 z-50 w-full max-w-md overflow-y-auto border-l bg-background p-4 shadow-xl"
            role="dialog"
            :aria-label="ayudaAbierta.titulo"
        >
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold">{{ ayudaAbierta.titulo }}</h2>
                <Button variant="ghost" size="sm" @click="ayudaAbierta = null"
                    >Cerrar</Button
                >
            </div>
            <Markdown
                :md="ayudaAbierta.cuerpo_md"
                :medios="medios"
                @segmento="segmento"
            />
        </aside>
    </div>
</template>
