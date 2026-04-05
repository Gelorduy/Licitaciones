<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';

const props = defineProps({ company: { type: Object, required: true } });

const form = useForm({
    periodicity: 'mensual',
    year: new Date().getFullYear(),
    month: new Date().getMonth() + 1,
    document: null,
});

const submit = () => {
    form.post(route('declaracion.store', props.company.id));
};
</script>

<template>
    <div>
        <Head title="Nueva Declaración" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Nueva Declaración de Impuestos · {{ company.nombre }}</h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Periodicidad" />
                                    <select v-model="form.periodicity" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                        <option value="mensual">mensual</option>
                                        <option value="anual">anual</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.periodicity" />
                                </div>
                                <div>
                                    <InputLabel value="Año" />
                                    <TextInput v-model="form.year" type="number" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.year" />
                                </div>
                            </div>

                            <div>
                                <InputLabel value="Mes (solo mensual)" />
                                <TextInput v-model="form.month" type="number" min="1" max="12" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.month" />
                            </div>

                            <div>
                                <InputLabel value="Archivo (PDF/XML/Excel)" />
                                <input type="file" accept=".pdf,.xml,.xlsx,.xls,.csv" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm" @change="form.document = $event.target.files[0]" />
                                <InputError class="mt-2" :message="form.errors.document" />
                            </div>

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
