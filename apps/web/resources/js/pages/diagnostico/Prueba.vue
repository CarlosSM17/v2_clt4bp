<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import EditorCodigo from '@/components/clt4bp/EditorCodigo.vue';
import Markdown from '@/components/clt4bp/Markdown.vue';
import { Button } from '@/components/ui/button';
import { postJson } from '@/lib/http';

type Item = {
    id: number;
    tipo:
        | 'opcion_multiple'
        | 'respuesta_corta'
        | 'prediccion_salida'
        | 'parsons'
        | 'programacion';
    enunciado: {
        md: string;
        opciones?: { id: string; texto: string }[];
        lineas?: { id: string; texto: string }[];
        codigo_inicial?: string;
    };
    lenguaje: string | null;
    ejemplos: { entrada: string; salida_esperada: string }[];
};
type Respuesta = {
    valor?: string | string[] | null;
    codigo?: string | null;
};

const props = defineProps<{
    curso: { id: number; titulo: string };
    prueba: {
        id: number;
        nombre: string;
        tipo: string;
        tiempo_limite_min: number | null;
    };
    intentoId: number;
    venceAt: string | null;
    items: Item[];
    respuestas: Record<number, Respuesta>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] },
});

// Estado local de cada respuesta, inicializado con lo ya guardado
const valor = reactive<Record<number, string>>({});
const codigo = reactive<Record<number, string>>({});
const orden = reactive<Record<number, string[]>>({});
for (const item of props.items) {
    const previa = props.respuestas[item.id];
    valor[item.id] = typeof previa?.valor === 'string' ? previa.valor : '';
    codigo[item.id] = previa?.codigo ?? item.enunciado.codigo_inicial ?? '';
    if (item.tipo === 'parsons') {
        orden[item.id] = Array.isArray(previa?.valor)
            ? previa.valor
            : (item.enunciado.lineas ?? []).map((l) => l.id);
    }
}

function guardar(item: Item): void {
    const respuesta: Respuesta =
        item.tipo === 'programacion'
            ? { codigo: codigo[item.id] }
            : {
                  valor:
                      item.tipo === 'parsons' ? orden[item.id] : valor[item.id],
              };
    router.post(
        `/intentos/${props.intentoId}/respuestas`,
        { item_id: item.id, respuesta },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function mover(item: Item, i: number, delta: -1 | 1): void {
    const lista = orden[item.id];
    const j = i + delta;
    if (j < 0 || j >= lista.length) return;
    [lista[i], lista[j]] = [lista[j], lista[i]];
    guardar(item);
}

// Ejecutar con entrada libre (solo programación)
const entrada = reactive<Record<number, string>>({});
const salida = reactive<Record<number, string>>({});
const ejecutando = ref<number | null>(null);
async function ejecutar(item: Item): Promise<void> {
    ejecutando.value = item.id;
    try {
        const r = await postJson<{
            compilo: boolean;
            salida: string;
            errores: string;
            excedio_limite: boolean;
        }>(`/intentos/${props.intentoId}/ejecutar`, {
            item_id: item.id,
            codigo: codigo[item.id],
            entrada: entrada[item.id] ?? '',
        });
        salida[item.id] = !r.compilo
            ? `Error de compilación:\n${r.errores}`
            : r.excedio_limite
              ? 'El programa rebasó el tiempo o la memoria permitidos.'
              : r.salida + (r.errores ? `\n[stderr]\n${r.errores}` : '');
    } catch (e) {
        salida[item.id] = (e as Error).message;
    } finally {
        ejecutando.value = null;
    }
    guardar(item);
}

// Tiempo restante
const restante = ref('');
let reloj: number | undefined;
onMounted(() => {
    if (!props.venceAt) return;
    const fin = new Date(props.venceAt).getTime();
    reloj = window.setInterval(() => {
        const s = Math.max(0, Math.floor((fin - Date.now()) / 1000));
        restante.value = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
        if (s === 0) enviar();
    }, 1000);
});
onBeforeUnmount(() => window.clearInterval(reloj));

const enviando = ref(false);
function enviar(): void {
    if (enviando.value) return;
    enviando.value = true;
    window.clearInterval(reloj);
    router.post(`/intentos/${props.intentoId}/enviar`);
}

const lineas = computed(() =>
    Object.fromEntries(
        props.items.map((i) => [
            i.id,
            Object.fromEntries(
                (i.enunciado.lineas ?? []).map((l) => [l.id, l.texto]),
            ),
        ]),
    ),
);
</script>

<template>
    <Head :title="prueba.nombre" />

    <div class="mx-auto flex max-w-4xl flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">{{ prueba.nombre }}</h1>
            <span
                v-if="restante"
                class="rounded-md bg-muted px-3 py-1 font-mono text-sm"
                >⏱ {{ restante }}</span
            >
        </div>

        <section
            v-for="(item, n) in items"
            :key="item.id"
            class="flex flex-col gap-3 rounded-xl border p-4"
        >
            <p class="text-sm font-medium text-muted-foreground">
                Pregunta {{ n + 1 }}
            </p>
            <Markdown :md="item.enunciado.md" />

            <div
                v-if="item.tipo === 'opcion_multiple'"
                class="flex flex-col gap-2"
            >
                <label
                    v-for="o in item.enunciado.opciones"
                    :key="o.id"
                    class="flex items-center gap-2"
                >
                    <input
                        v-model="valor[item.id]"
                        type="radio"
                        :name="`i${item.id}`"
                        :value="o.id"
                        @change="guardar(item)"
                    />
                    {{ o.texto }}
                </label>
            </div>

            <input
                v-else-if="item.tipo === 'respuesta_corta'"
                v-model="valor[item.id]"
                class="rounded-md border px-3 py-1.5"
                @blur="guardar(item)"
            />

            <textarea
                v-else-if="item.tipo === 'prediccion_salida'"
                v-model="valor[item.id]"
                class="h-24 rounded-md border px-3 py-1.5 font-mono text-sm"
                placeholder="Escribe exactamente lo que imprimirá el programa"
                @blur="guardar(item)"
            />

            <ol v-else-if="item.tipo === 'parsons'" class="flex flex-col gap-1">
                <li
                    v-for="(id, i) in orden[item.id]"
                    :key="id"
                    class="flex items-center gap-2 rounded border bg-muted/40 px-2 py-1"
                >
                    <pre class="flex-1 font-mono text-sm">{{
                        lineas[item.id][id]
                    }}</pre>
                    <button
                        type="button"
                        class="px-2"
                        aria-label="Subir"
                        @click="mover(item, i, -1)"
                    >
                        ↑
                    </button>
                    <button
                        type="button"
                        class="px-2"
                        aria-label="Bajar"
                        @click="mover(item, i, 1)"
                    >
                        ↓
                    </button>
                </li>
            </ol>

            <div
                v-else-if="item.tipo === 'programacion'"
                class="flex flex-col gap-2"
            >
                <div v-if="item.ejemplos.length" class="text-sm">
                    <p class="font-medium">Ejemplos</p>
                    <div
                        v-for="(e, k) in item.ejemplos"
                        :key="k"
                        class="mt-1 grid grid-cols-2 gap-2"
                    >
                        <div>
                            <p class="text-xs text-muted-foreground">Entrada</p>
                            <pre class="rounded bg-muted p-2 text-xs">{{
                                e.entrada
                            }}</pre>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Salida esperada
                            </p>
                            <pre class="rounded bg-muted p-2 text-xs">{{
                                e.salida_esperada
                            }}</pre>
                        </div>
                    </div>
                </div>
                <EditorCodigo
                    v-model="codigo[item.id]"
                    :lenguaje="item.lenguaje"
                    @blur="guardar(item)"
                />
                <textarea
                    v-model="entrada[item.id]"
                    class="h-16 rounded-md border px-3 py-1.5 font-mono text-sm"
                    placeholder="Entrada para probar (opcional)"
                />
                <div>
                    <Button
                        variant="outline"
                        :disabled="ejecutando === item.id"
                        @click="ejecutar(item)"
                    >
                        {{
                            ejecutando === item.id ? 'Ejecutando…' : 'Ejecutar'
                        }}
                    </Button>
                </div>
                <pre
                    v-if="salida[item.id]"
                    class="max-h-48 overflow-auto rounded bg-black p-3 text-xs text-green-300"
                    >{{ salida[item.id] }}</pre>
            </div>
        </section>

        <div class="flex justify-end">
            <Button :disabled="enviando" @click="enviar">Enviar prueba</Button>
        </div>
    </div>
</template>
