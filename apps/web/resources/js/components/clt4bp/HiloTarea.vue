<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { fecha } from '@/lib/aula';

type Comentario = { id: number; texto: string; autor: string; mio: boolean; fecha: string };
const props = defineProps<{ cursoId: number; tareaUid: string; comentarios: Comentario[] }>();

const texto = ref('');
const enviando = ref(false);

function publicar(): void {
    if (!texto.value.trim()) return;
    enviando.value = true;
    router.post(`/aula/${props.cursoId}/tareas/${props.tareaUid}/comentarios`, { texto: texto.value }, {
        preserveScroll: true,
        only: ['comentarios'],
        onSuccess: () => (texto.value = ''),
        onFinish: () => (enviando.value = false),
    });
}

function borrar(id: number): void {
    router.delete(`/aula/comentarios/${id}`, { preserveScroll: true, only: ['comentarios'] });
}
</script>

<template>
    <section class="flex flex-col gap-3 rounded-xl border p-4">
        <h2 class="font-semibold">Discusión del grupo</h2>
        <p class="text-sm text-muted-foreground">Comparte cómo lo pensaste o qué te atoró. Solo tu grupo y tu instructor la ven.</p>
        <p v-if="!comentarios.length" class="text-sm text-muted-foreground">Nadie ha escrito todavía.</p>
        <article v-for="c in comentarios" :key="c.id" class="rounded-lg bg-muted/40 p-3 text-sm">
            <p class="mb-1 text-xs text-muted-foreground">
                {{ c.autor }} · {{ fecha(c.fecha) }}
                <button v-if="c.mio" type="button" class="ml-2 underline" @click="borrar(c.id)">borrar</button>
            </p>
            <p class="whitespace-pre-line">{{ c.texto }}</p>
        </article>
        <textarea v-model="texto" maxlength="2000" class="h-20 rounded-md border px-3 py-2 text-sm" placeholder="Escribe un comentario" />
        <div><Button :disabled="enviando || !texto.trim()" @click="publicar">Publicar</Button></div>
    </section>
</template>
