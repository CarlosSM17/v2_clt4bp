<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { fecha } from '@/lib/aula';

type Consentimiento = { id: number; tipo: string; version: string; otorgado_at: string; revocado_at: string | null; revocable: boolean };

defineProps<{ consentimientos: Consentimiento[]; contacto: string }>();

const NOMBRE: Record<string, string> = {
    privacidad: 'Aviso de privacidad',
    investigacion: 'Participación en la investigación',
    tutor: 'Autorización de madre, padre o tutor',
};

function revocar(c: Consentimiento): void {
    const texto = 'Si revocas tu consentimiento, tus datos dejarán de incluirse en los análisis de la investigación. '
        + 'Puedes seguir usando la plataforma igual que antes. ¿Continuar?';
    if (window.confirm(texto)) router.post(`/mis-datos/consentimientos/${c.id}/revocar`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Mis datos" />
    <div class="mx-auto flex max-w-2xl flex-col gap-6 p-4">
        <h1 class="text-2xl font-semibold">Mis datos</h1>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Consentimientos</h2>
            <div v-for="c in consentimientos" :key="c.id" class="flex items-center justify-between gap-4 rounded-xl border p-4">
                <div>
                    <p class="font-medium">{{ NOMBRE[c.tipo] ?? c.tipo }}
                        <span class="text-xs text-muted-foreground">(versión {{ c.version }})</span>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Otorgado el {{ fecha(c.otorgado_at) }}<template v-if="c.revocado_at"> · <strong>revocado el {{ fecha(c.revocado_at) }}</strong></template>
                    </p>
                </div>
                <Button v-if="c.revocable" variant="outline" size="sm" @click="revocar(c)">Revocar</Button>
            </div>
            <p class="text-sm text-muted-foreground">
                Para retirar el aviso de privacidad (dar de baja tu cuenta), corregir o cancelar tus datos, escribe a
                <a :href="`mailto:${contacto}`" class="underline">{{ contacto }}</a>.
            </p>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">Descargar una copia</h2>
            <p class="text-sm">Un archivo ZIP con todo lo que la plataforma guarda de ti: respuestas, envíos de código, avance y eventos.</p>
            <a href="/mis-datos/descarga" class="self-start rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-muted">Descargar mis datos</a>
        </section>
    </div>
</template>
