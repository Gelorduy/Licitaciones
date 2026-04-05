<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';

const props = defineProps({
    regulation: {
        type: Object,
        required: true,
    },
    fileHistory: {
        type: Array,
        default: () => [],
    },
    scopes: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    title: props.regulation.title ?? '',
    country_code: props.regulation.country_code ?? 'MX',
    scope: props.regulation.scope ?? (props.scopes[0] ?? 'federal'),
    regulatory_body: props.regulation.regulatory_body ?? '',
    general_description: props.regulation.general_description ?? '',
    source_pdf: null,
});

const submit = () => {
    if (form.source_pdf && props.regulation.source_pdf_path) {
        const confirmed = window.confirm(
            'Esta acción reemplazará el archivo actual de la regulación y no se puede deshacer. ¿Deseas continuar?',
        );

        if (!confirmed) {
            return;
        }
    }

    form.transform((data) => ({
        ...data,
        country_code: data.country_code.toUpperCase(),
        _method: 'put',
    })).post(route('regulacion.update', props.regulation.id), {
        forceFormData: true,
    });
};
</script>

<template>
    <div>
        <Head title="Editar Regulación" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Editar Regulación</h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                Archivo actual:
                                <span class="font-medium">
                                    {{ regulation.source_pdf_original_name || (regulation.source_pdf_path ? regulation.source_pdf_path.split('/').pop() : 'Sin archivo') }}
                                </span>
                                <div v-if="regulation.source_pdf_path" class="mt-3 flex flex-wrap gap-2">
                                    <a
                                        :href="route('regulacion.file.view', regulation.id)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Button label="Ver PDF" size="small" severity="secondary" text />
                                    </a>
                                    <a
                                        :href="route('regulacion.file.download', regulation.id)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Button label="Descargar" size="small" severity="secondary" text />
                                    </a>
                                    <Link :href="route('regulacion.text.view', regulation.id)">
                                        <Button label="Ver texto OCR" size="small" severity="info" text />
                                    </Link>
                                </div>
                            </div>

                            <div>
                                <InputLabel value="PDF de la regulación (opcional para reemplazar)" />
                                <input
                                    type="file"
                                    accept="application/pdf"
                                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                    @change="form.source_pdf = $event.target.files[0]"
                                />
                                <InputError class="mt-2" :message="form.errors.source_pdf" />
                                <p class="mt-2 text-xs text-gray-500">
                                    Si subes un nuevo PDF, se volverá a procesar OCR e indexación vectorial. Tamaño máximo: 20 MB.
                                </p>
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
