<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, reactive } from 'vue';
import EditorCodigo from '@/components/clt4bp/EditorCodigo.vue';
import Markdown from '@/components/clt4bp/Markdown.vue';
import { Button } from '@/components/ui/button';
import type { Caso, Resultado } from '@/lib/aula';
import { iniciarEventos, registrar } from '@/lib/eventos';
import { postJson } from '@/lib/http';

type Practica = { uid: string; habilidad: string; lenguaje: string; ejercicios: { enunciado_md: string; ejemplos: Caso[] }[] };
const props = defineProps<{ curso: { id: number; titulo: string }; practicas: Practica[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] } });

// Estado por ejercicio, con clave «uid#índice»
const codigo = reactive<Record<string, string>>({});
const resultado = reactive<Record<string, Resultado | string>>({});
const probando = reactive<Record<string, boolean>>({});

async function comprobar(p: Practica, i: number): Promise<void> {
    const k = `${p.uid}#${i}`;
    probando[k] = true;
    try {
        resultado[k] = await postJson<Resultado>(`/aula/${props.curso.id}/practica/${p.uid}/${i}`, { codigo: codigo[k] ?? '' });
    } catch (e) {
        resultado[k] = (e as Error).message;
    } finally {
        probando[k] = false;
    }
}

onMounted(() => {
    iniciarEventos(props.curso.id);
    registrar('abrio_practica', 'curso', String(props.curso.id));
});
</script>

<template>
    <Head title="Práctica rápida" />
    <div class="mx-auto flex max-w-3xl flex-col gap-6 p-4">
        <header>
            <Link :href="`/aula/${curso.id}`" class="text-sm text-muted-foreground">← {{ curso.titulo }}</Link>
            <h1 class="mt-1 text-2xl font-semibold">Práctica rápida</h1>
            <p class="text-muted-foreground">Ejercicios cortos para que la sintaxis salga sola. No cuentan para la calificación.</p>
        </header>

        <section v-for="p in practicas" :key="p.uid" class="flex flex-col gap-4">
            <h2 class="text-lg font-semibold">{{ p.habilidad }}</h2>
            <article v-for="(e, i) in p.ejercicios" :key="i" class="flex flex-col gap-2 rounded-xl border p-4">
                <Markdown :md="e.enunciado_md" />
                <div v-for="(c, j) in e.ejemplos" :key="j" class="grid grid-cols-2 gap-2 text-xs">
                    <pre class="rounded bg-muted p-2">{{ c.entrada }}</pre>
                    <pre class="rounded bg-muted p-2">{{ c.salida_esperada }}</pre>
                </div>
                <EditorCodigo v-model="codigo[`${p.uid}#${i}`]" :lenguaje="p.lenguaje" />
                <div>
                    <Button variant="outline" :disabled="probando[`${p.uid}#${i}`]" @click="comprobar(p, i)">Comprobar</Button>
                </div>
                <p v-if="typeof resultado[`${p.uid}#${i}`] === 'string'" class="text-sm text-red-600">{{ resultado[`${p.uid}#${i}`] }}</p>
                <p v-else-if="resultado[`${p.uid}#${i}`]" class="text-sm">
                    {{ (resultado[`${p.uid}#${i}`] as Resultado).error_compilacion ? 'No compila.' : `${(resultado[`${p.uid}#${i}`] as Resultado).aprobados} de ${(resultado[`${p.uid}#${i}`] as Resultado).total} casos correctos.` }}
                </p>
            </article>
        </section>
    </div>
</template>
