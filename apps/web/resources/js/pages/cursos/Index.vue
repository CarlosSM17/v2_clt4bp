<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Inscripcion = {
    id: number;
    estado: string;
    acceso: boolean;
    curso: {
        id: number;
        titulo: string;
        descripcion: string | null;
        lenguaje: string;
    };
};

defineProps<{ inscripciones: Inscripcion[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Mis cursos', href: '/cursos' }] },
});

const form = useForm({ codigo: '' });

const etiquetas: Record<string, string> = {
    solicitud: 'Solicitud pendiente',
    inscrito: 'Inscrito',
    diagnostico: 'Diagnóstico en curso',
    con_perfil: 'Esperando material',
    cursando: 'Cursando',
    evaluacion_final: 'Evaluación final',
    concluido: 'Concluido',
    baja: 'Baja',
};

function solicitar(): void {
    form.post('/cursos/solicitar', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Mis cursos" />

    <div class="flex flex-col gap-6 p-4">
        <form class="flex max-w-md items-end gap-3" @submit.prevent="solicitar">
            <div class="grid flex-1 gap-2">
                <Label for="codigo">Código del curso</Label>
                <Input
                    id="codigo"
                    v-model="form.codigo"
                    maxlength="8"
                    class="font-mono uppercase"
                    placeholder="ABCD2345"
                />
                <InputError :message="form.errors.codigo" />
            </div>
            <Button type="submit" :disabled="form.processing"
                >Solicitar inscripción</Button
            >
        </form>

        <p v-if="!inscripciones.length" class="text-sm text-muted-foreground">
            Aún no tienes cursos. Pide a tu instructor el código de inscripción.
        </p>

        <div class="grid gap-4 md:grid-cols-2">
            <div
                v-for="i in inscripciones"
                :key="i.id"
                class="rounded-xl border p-4"
            >
                <p class="font-medium">{{ i.curso.titulo }}</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ etiquetas[i.estado] ?? i.estado }}
                </p>
                <Link
                    v-if="i.acceso"
                    :href="`/cursos/${i.curso.id}`"
                    class="mt-3 inline-block text-sm underline"
                >
                    Entrar
                </Link>
            </div>
        </div>
    </div>
</template>
