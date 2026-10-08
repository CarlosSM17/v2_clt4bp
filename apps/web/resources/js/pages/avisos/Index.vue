<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { fecha } from '@/lib/aula';

type Aviso = { id: string; datos: { tipo: string; texto: string; url?: string }; leido: boolean; fecha: string };
defineProps<{ avisos: Aviso[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Avisos', href: '/avisos' }] } });
</script>

<template>
    <Head title="Avisos" />
    <div class="mx-auto flex max-w-2xl flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Avisos</h1>
            <Button v-if="avisos.some((a) => !a.leido)" variant="outline" size="sm" @click="router.post('/avisos/leidos', {}, { preserveScroll: true })">
                Marcar todo como leído
            </Button>
        </div>
        <p v-if="!avisos.length" class="text-muted-foreground">No tienes avisos.</p>
        <component
            :is="a.datos.url ? Link : 'div'"
            v-for="a in avisos"
            :key="a.id"
            :href="a.datos.url"
            class="rounded-xl border p-4"
            :class="a.leido ? 'opacity-70' : 'border-primary/50 bg-primary/5'"
        >
            <p>{{ a.datos.texto }}</p>
            <p class="text-xs text-muted-foreground">{{ fecha(a.fecha) }}</p>
        </component>
    </div>
</template>
