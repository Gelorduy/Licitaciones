<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';

const props = defineProps({ company: { type: Object, required: true } });

const form = useForm({
    title: '',
    city: '',
    contact_name: '',
    contact_position: '',
    phone: '',
    email: '',
    body_template: '',
    is_default: false,
});

const submit = () => {
    form.post(route('letterhead.store', props.company.id));
};
</script>

<template>
    <div>
        <Head title="Nueva Hoja Membretada" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Nueva Hoja Membretada · {{ company.nombre }}</h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div>
                                <InputLabel value="Título" />
                                <TextInput v-model="form.title" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.title" />
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Ciudad" />
                                    <TextInput v-model="form.city" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <InputLabel value="Teléfono" />
                                    <TextInput v-model="form.phone" class="mt-1 block w-full" />
                                </div>
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Nombre de contacto" />
                                    <TextInput v-model="form.contact_name" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <InputLabel value="Puesto" />
                                    <TextInput v-model="form.contact_position" class="mt-1 block w-full" />
                                </div>
                            </div>

                            <div>
                                <InputLabel value="Correo" />
                                <TextInput v-model="form.email" type="email" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.email" />
                            </div>

                            <div>
                                <InputLabel value="Plantilla base" />
                                <textarea v-model="form.body_template" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" rows="8" placeholder="Texto base de la hoja membretada..." />
                                <InputError class="mt-2" :message="form.errors.body_template" />
                            </div>

                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input v-model="form.is_default" type="checkbox" class="rounded border-gray-300" />
                                Usar como hoja membretada predeterminada
                            </label>

                            <div class="flex justify-end gap-3">
                                <Link :href="route('empresa.show', company.id)">
                                    <Button label="Cancelar" severity="secondary" text />
                                </Link>
                                <Button type="submit" label="Guardar" icon="pi pi-save" :loading="form.processing" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
