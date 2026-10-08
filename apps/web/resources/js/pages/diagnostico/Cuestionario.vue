<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

type Item = { id: string; texto: string };

const props = defineProps<{
    curso: { id: number; titulo: string };
    aplicacionId: number;
    nombre: string;
    escala: { min: number; max: number; etiquetas: Record<string, string> };
    partes: { titulo: string; items: string[] }[];
    items: Item[];
    respuestas: Record<string, number>;
    completado: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] },
});

const POR_PAGINA = 8;
const base = `/cursos/${props.curso.id}/cuestionarios/${props.aplicacionId}`;
const valores = reactive<Record<string, number>>({ ...props.respuestas });
const porId = computed(() =>
    Object.fromEntries(props.items.map((i) => [i.id, i])),
);
const opciones = computed(() =>
    Array.from(
        { length: props.escala.max - props.escala.min + 1 },
        (_, k) => props.escala.min + k,
    ),
);

// Páginas: cada parte del instrumento se divide en bloques de 8 ítems
const paginas = computed(() =>
    props.partes.flatMap((parte) => {
        const bloques: { titulo: string; ids: string[] }[] = [];
        for (let i = 0; i < parte.items.length; i += POR_PAGINA) {
            bloques.push({
                titulo: parte.titulo,
                ids: parte.items.slice(i, i + POR_PAGINA),
            });
        }
        return bloques;
    }),
);

const primeraIncompleta = paginas.value.findIndex((p) =>
    p.ids.some((id) => valores[id] === undefined),
);
const pagina = ref(primeraIncompleta === -1 ? 0 : primeraIncompleta);
const actual = computed(() => paginas.value[pagina.value]);
const paginaCompleta = computed(() =>
    actual.value.ids.every((id) => valores[id] !== undefined),
);
const esUltima = computed(() => pagina.value === paginas.value.length - 1);
const respondidas = computed(() => Object.keys(valores).length);

const final = useForm<{ respuestas: null }>({ respuestas: null });

function guardarYContinuar(): void {
    const respuestas = Object.fromEntries(
        actual.value.ids.map((id) => [id, valores[id]]),
    );
    router.post(
        `${base}/respuestas`,
        { respuestas },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                if (esUltima.value) {
                    final.post(`${base}/completar`);
                } else {
                    pagina.value++;
                    window.scrollTo({ top: 0 });
                }
            },
        },
    );
}
</script>

<template>
    <Head :title="nombre" />

    <div class="mx-auto flex max-w-3xl flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">{{ nombre }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ actual.titulo }} · página {{ pagina + 1 }} de
                {{ paginas.length }} · {{ respondidas }} de
                {{ items.length }} respuestas
            </p>
            <p class="mt-2 text-sm">
                Responde qué tan cierta es cada afirmación para ti:
                {{ escala.min }} = {{ escala.etiquetas[escala.min] }},
                {{ escala.max }} = {{ escala.etiquetas[escala.max] }}. No hay
                respuestas correctas o incorrectas.
            </p>
        </div>

        <fieldset
            v-for="id in actual.ids"
            :key="id"
            class="rounded-xl border p-4"
        >
            <legend class="sr-only">{{ porId[id].texto }}</legend>
            <p class="mb-3">{{ porId[id].texto }}</p>
            <div class="flex flex-wrap gap-2" role="radiogroup">
                <label
                    v-for="v in opciones"
                    :key="v"
                    class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-md border text-sm"
                    :class="
                        valores[id] === v
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-muted'
                    "
                >
                    <input
                        v-model="valores[id]"
                        type="radio"
                        class="sr-only"
                        :name="id"
                        :value="v"
                    />
                    {{ v }}
                </label>
            </div>
        </fieldset>

        <InputError :message="final.errors.respuestas" />

        <div class="flex justify-between">
            <Button variant="outline" :disabled="pagina === 0" @click="pagina--"
                >Anterior</Button
            >
            <Button
                :disabled="!paginaCompleta || final.processing"
                @click="guardarYContinuar"
            >
                {{ esUltima ? 'Terminar' : 'Guardar y seguir' }}
            </Button>
        </div>
    </div>
</template>
