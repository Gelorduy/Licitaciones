<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';

const props = defineProps({
    scopes: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    title: '',
    country_code: 'MX',
    scope: props.scopes[0] ?? 'federal',
    regulatory_body: '',
    general_description: '',
    source_pdf: null,
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        country_code: data.country_code.toUpperCase(),
    })).post(route('regulacion.store'));
};
</script>

<template>
    <div>
        <Head title="Nueva Regulación" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Nueva Regulación</h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div>
                                <InputLabel value="PDF de la regulación" />
                                <input
                                    type="file"
                                    accept="application/pdf"
                                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                    @change="form.source_pdf = $event.target.files[0]"
                                />
                                <InputError class="mt-2" :message="form.errors.source_pdf" />
                            </div>

                            <div>
                                <InputLabel value="Título" />
                                <TextInput v-model="form.title" class="mt-1 block w-full" autofocus />
                                <InputError class="mt-2" :message="form.errors.title" />
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="País" />
                                    <TextInput v-model="form.country_code" class="mt-1 block w-full uppercase" maxlength="2" />
                                    <InputError class="mt-2" :message="form.errors.country_code" />
                                </div>

                                <div>
                                    <InputLabel value="Ámbito" />
                                    <select v-model="form.scope" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option v-for="scope in scopes" :key="scope" :value="scope">{{ scope }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.scope" />
                                </div>
                            </div>

                            <div>
                                <InputLabel value="Entidad regulatoria" />
                                <TextInput v-model="form.regulatory_body" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.regulatory_body" />
                            </div>

                            <div>
                                <InputLabel value="Descripción general" />
                                <textarea v-model="form.general_description" rows="5" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                                <InputError class="mt-2" :message="form.errors.general_description" />
                            </div>

                            <div class="flex justify-end gap-3">
                                <Link :href="route('regulacion.index')">
                                    <Button label="Cancelar" severity="secondary" text />
                                </Link>
                                <Button type="submit" label="Guardar Regulación" icon="pi pi-save" :loading="form.processing" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>