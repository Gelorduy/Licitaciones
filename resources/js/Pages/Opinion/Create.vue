<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
    tipos: {
        type: Array,
        default: () => [],
    },
    estados: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    tipo: props.tipos[0] ?? 'sat',
    estado: props.estados[0] ?? 'positivo',
    fecha_emision: '',
    fecha_vigencia: '',
    documento: null,
});

const submit = () => {
    form.post(route('opinion.store', props.company.id));
};
</script>

<template>
    <div>
        <Head title="Nueva Opinión" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Nueva Opinión de Cumplimiento · {{ company.nombre }}
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div>
                                <InputLabel value="PDF de opinión" />
                                <input
                                    type="file"
                                    accept="application/pdf"
                                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                    @change="form.documento = $event.target.files[0]"
                                />
                                <InputError class="mt-2" :message="form.errors.documento" />
                                <p class="mt-2 text-xs text-gray-500">
                                    Tamaño máximo permitido: 20 MB.
                                </p>
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Tipo" />
                                    <select v-model="form.tipo" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                        <option v-for="tipo in tipos" :key="tipo" :value="tipo">{{ tipo.toUpperCase() }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.tipo" />
                                </div>

                                <div>
                                    <InputLabel value="Estado" />
                                    <select v-model="form.estado" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                        <option v-for="estado in estados" :key="estado" :value="estado">{{ estado }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.estado" />
                                </div>
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Fecha de emisión" />
                                    <TextInput v-model="form.fecha_emision" type="date" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.fecha_emision" />
                                </div>

                                <div>
                                    <InputLabel value="Fecha de vigencia (opcional)" />
                                    <TextInput v-model="form.fecha_vigencia" type="date" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.fecha_vigencia" />
                                </div>
                            </div>

                            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                                Si no se captura fecha de vigencia, el sistema calcula automáticamente +30 días desde la fecha de emisión.
                            </div>

                            <div class="flex justify-end gap-3">
                                <Link :href="route('empresa.show', company.id)">
                                    <Button label="Cancelar" severity="secondary" text />
                                </Link>
                                <Button type="submit" label="Guardar Opinión" icon="pi pi-save" :loading="form.processing" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
