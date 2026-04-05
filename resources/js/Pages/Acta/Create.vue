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
});

const form = useForm({
    tipo: props.tipos[0] ?? 'constitutiva',
    fecha_registro: '',
    documento: null,
    rpc_folio: '',
    rpc_lugar: '',
    notaria_numero: '',
    notaria_lugar: '',
    notario_nombre: '',
    escritura_numero: '',
    libro_numero: '',
    acto: '',
});

const submit = () => {
    form.post(route('acta.store', props.company.id));
};
</script>

<template>
    <div>
        <Head title="Nueva Acta" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Nueva Acta · {{ company.nombre }}
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div>
                                <InputLabel value="PDF del acta" />
                                <input
                                    type="file"
                                    accept="application/pdf"
                                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                    @change="form.documento = $event.target.files[0]"
                                />
                                <InputError class="mt-2" :message="form.errors.documento" />
                                <p class="mt-2 text-xs text-gray-500">
                                    El documento se procesa con extracción de texto y OCR automático si viene escaneado como imagen. Tamaño máximo: 20 MB.
                                </p>
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Tipo de acta" />
                                    <select v-model="form.tipo" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                        <option v-for="tipo in tipos" :key="tipo" :value="tipo">{{ tipo }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.tipo" />
                                </div>

                                <div>
                                    <InputLabel value="Fecha de registro" />
                                    <TextInput v-model="form.fecha_registro" type="date" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.fecha_registro" />
                                </div>
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="RPC Folio" />
                                    <TextInput v-model="form.rpc_folio" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.rpc_folio" />
                                </div>

                                <div>
                                    <InputLabel value="RPC Lugar" />
                                    <TextInput v-model="form.rpc_lugar" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.rpc_lugar" />
                                </div>
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Notaría Número" />
                                    <TextInput v-model="form.notaria_numero" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.notaria_numero" />
                                </div>

                                <div>
                                    <InputLabel value="Notaría Lugar" />
                                    <TextInput v-model="form.notaria_lugar" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.notaria_lugar" />
                                </div>
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Notario Nombre" />
                                    <TextInput v-model="form.notario_nombre" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.notario_nombre" />
                                </div>

                                <div>
                                    <InputLabel value="Acto" />
                                    <TextInput v-model="form.acto" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.acto" />
                                </div>
                            </div>

                            <div class="flex justify-end gap-3">
                                <Link :href="route('empresa.show', company.id)">
                                    <Button label="Cancelar" severity="secondary" text />
                                </Link>
                                <Button type="submit" label="Guardar Acta" icon="pi pi-save" :loading="form.processing" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
