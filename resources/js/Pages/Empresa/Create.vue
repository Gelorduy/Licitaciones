<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';

const form = useForm({
    nombre: '',
    rfc: '',
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        rfc: data.rfc.toUpperCase(),
    })).post(route('empresa.store'));
};
</script>

<template>
    <div>
        <Head title="Nueva Empresa" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Nueva Empresa
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900">Perfil corporativo</h3>
                            <p class="mt-1 text-sm text-gray-500">Captura la empresa base para comenzar el módulo 1.</p>
                        </div>

                        <form class="space-y-6" @submit.prevent="submit">
                            <div>
                                <InputLabel value="Nombre de la empresa" />
                                <TextInput v-model="form.nombre" class="mt-1 block w-full" autofocus />
                                <InputError class="mt-2" :message="form.errors.nombre" />
                            </div>

                            <div>
                                <InputLabel value="RFC" />
                                <TextInput v-model="form.rfc" class="mt-1 block w-full uppercase" maxlength="13" />
                                <InputError class="mt-2" :message="form.errors.rfc" />
                            </div>

                            <div class="flex justify-end gap-3">
                                <Link :href="route('empresa.index')">
                                    <Button label="Cancelar" severity="secondary" text />
                                </Link>
                                <Button type="submit" label="Guardar Empresa" icon="pi pi-save" :loading="form.processing" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>