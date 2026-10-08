<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Crea tu cuenta',
        description: 'Escribe tus datos para registrarte como estudiante',
    },
});
</script>

<template>
    <Head title="Registro" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Nombre completo</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Contraseña (mínimo 10 caracteres)</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation"
                    >Confirma la contraseña</Label
                >
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <!-- Consentimientos: el de privacidad es obligatorio; el de investigación, opcional -->
            <div class="grid gap-3 text-sm">
                <label class="flex items-start gap-2">
                    <input
                        type="checkbox"
                        name="acepta_privacidad"
                        value="1"
                        required
                        class="mt-1"
                        :tabindex="5"
                    />
                    <span>
                        Leí y acepto el
                        <a
                            href="/legal/privacidad"
                            target="_blank"
                            class="underline"
                            >aviso de privacidad</a
                        >.
                    </span>
                </label>
                <InputError :message="errors.acepta_privacidad" />

                <label class="flex items-start gap-2">
                    <input
                        type="checkbox"
                        name="acepta_investigacion"
                        value="1"
                        class="mt-1"
                        :tabindex="6"
                    />
                    <span>
                        Acepto que mis datos seudonimizados se usen con fines de
                        <a
                            href="/legal/investigacion"
                            target="_blank"
                            class="underline"
                            >investigación educativa</a
                        >
                        (opcional; no afecta tu calificación).
                    </span>
                </label>
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="7"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Crear cuenta
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            ¿Ya tienes cuenta?
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="8"
                >Inicia sesión</TextLink
            >
        </div>
    </Form>
</template>
