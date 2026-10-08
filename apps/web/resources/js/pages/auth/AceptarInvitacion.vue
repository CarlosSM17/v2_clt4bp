<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{ nombre: string; email: string; accion: string }>();

defineOptions({
    layout: {
        title: 'Activa tu cuenta de instructor',
        description: 'Define tu contraseña para empezar',
    },
});

const form = useForm({ password: '', password_confirmation: '' });

function enviar(): void {
    // "accion" es la URL firmada completa: el POST debe conservar la firma
    form.post(props.accion, { onFinish: () => form.reset() });
}
</script>

<template>
    <Head title="Activar cuenta" />

    <form class="flex flex-col gap-6" @submit.prevent="enviar">
        <p class="text-sm text-muted-foreground">{{ nombre }} · {{ email }}</p>

        <div class="grid gap-2">
            <Label for="password">Contraseña (mínimo 10 caracteres)</Label>
            <PasswordInput
                id="password"
                v-model="form.password"
                autocomplete="new-password"
                required
            />
            <InputError :message="form.errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">Confirma la contraseña</Label>
            <PasswordInput
                id="password_confirmation"
                v-model="form.password_confirmation"
                autocomplete="new-password"
                required
            />
        </div>

        <Button type="submit" class="w-full" :disabled="form.processing">
            <Spinner v-if="form.processing" />
            Activar cuenta
        </Button>
    </form>
</template>
