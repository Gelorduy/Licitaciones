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
    acta: {
        type: Object,
        required: true,
    },
    fileHistory: {
        type: Array,
        default: () => [],
    },
    tipos: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    tipo: props.acta.tipo ?? (props.tipos[0] ?? 'constitutiva'),
    fecha_registro: props.acta.fecha_registro ?? '',
    documento: null,
    rpc_folio: props.acta.rpc_folio ?? '',
    rpc_lugar: props.acta.rpc_lugar ?? '',
    notaria_numero: props.acta.notaria_numero ?? '',
    notaria_lugar: props.acta.notaria_lugar ?? '',
    notario_nombre: props.acta.notario_nombre ?? '',
    escritura_numero: props.acta.escritura_numero ?? '',
    libro_numero: props.acta.libro_numero ?? '',
    acto: props.acta.acto ?? '',
});

const submit = () => {
    if (form.documento && props.acta.documento_path) {
        const confirmed = window.confirm(
            'Esta acción reemplazará el archivo actual del acta y no se puede deshacer. ¿Deseas continuar?',
        );

        if (!confirmed) {
            return;
        }
    }

    form.transform((data) => ({
        ...data,
        _method: 'put',
    })).post(route('acta.update', [props.company.id, props.acta.id]), {
        forceFormData: true,
    });
};
</script>

<template>
    <div>
        <Head title="Editar Acta" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Editar Acta · {{ company.nombre }}
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                Archivo actual:
                                <span class="font-medium">
                                    {{ acta.documento_original_name || (acta.documento_path ? acta.documento_path.split('/').pop() : 'Sin archivo') }}
                                </span>
                                <div v-if="acta.documento_path" class="mt-3 flex flex-wrap gap-2">
                                    <a
                                        :href="route('acta.file.view', [company.id, acta.id])"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Button label="Ver PDF" size="small" severity="secondary" text />
                                    </a>
                                    <a
                                        :href="route('acta.file.download', [company.id, acta.id])"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Button label="Descargar" size="small" severity="secondary" text />
                                    </a>
                                    <Link :href="route('acta.text.view', [company.id, acta.id])">
                                        <Button label="Ver texto OCR" size="small" severity="info" text />
                                    </Link>
                                </div>
                            </div>

                            <div>
                                <InputLabel value="PDF del acta (opcional para reemplazar)" />
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
                                <Button type="submit" label="Guardar cambios" icon="pi pi-save" :loading="form.processing" />
                            </div>
                        </form>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Historial del archivo</h3>
                        <div v-if="fileHistory.length" class="mt-4 space-y-3">
                            <div v-for="event in fileHistory" :key="event.id" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-medium">{{ event.event_type }}</span>
                                    <span>{{ event.created_at }}</span>
                                </div>
                                <div class="mt-1 text-xs text-slate-500">Usuario: {{ event.user_name || 'Sistema' }}</div>
                                <div v-if="event.metadata?.file_name" class="mt-1 text-xs text-slate-500">Archivo: {{ event.metadata.file_name }}</div>
                            </div>
                        </div>
                        <p v-else class="mt-4 text-sm text-slate-500">Sin eventos registrados todavía.</p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
