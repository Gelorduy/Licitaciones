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
    opinion: {
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
    estados: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    tipo: props.opinion.tipo ?? (props.tipos[0] ?? 'sat'),
    estado: props.opinion.estado ?? (props.estados[0] ?? 'positivo'),
    fecha_emision: props.opinion.fecha_emision ?? '',
    fecha_vigencia: props.opinion.fecha_vigencia ?? '',
    documento: null,
});

const submit = () => {
    if (form.documento && props.opinion.documento_path) {
        const confirmed = window.confirm(
            'Esta acción reemplazará el archivo actual de la opinión y no se puede deshacer. ¿Deseas continuar?',
        );

        if (!confirmed) {
            return;
        }
    }

    form.transform((data) => ({
        ...data,
        _method: 'put',
    })).post(route('opinion.update', [props.company.id, props.opinion.id]), {
        forceFormData: true,
    });
};
</script>

<template>
    <div>
        <Head title="Editar Opinión" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Editar Opinión de Cumplimiento · {{ company.nombre }}
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                Archivo actual:
                                <span class="font-medium">
                                    {{ opinion.documento_original_name || (opinion.documento_path ? opinion.documento_path.split('/').pop() : 'Sin archivo') }}
                                </span>
                                <div v-if="opinion.documento_path" class="mt-3 flex flex-wrap gap-2">
                                    <a
                                        :href="route('opinion.file.view', [company.id, opinion.id])"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Button label="Ver PDF" size="small" severity="secondary" text />
                                    </a>
                                    <a
                                        :href="route('opinion.file.download', [company.id, opinion.id])"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Button label="Descargar" size="small" severity="secondary" text />
                                    </a>
                                    <Link :href="route('opinion.text.view', [company.id, opinion.id])">
                                        <Button label="Ver texto OCR" size="small" severity="info" text />
                                    </Link>
                                </div>
                            </div>

                            <div>
                                <InputLabel value="PDF de opinión (opcional para reemplazar)" />
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
