<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Button from 'primevue/button';

defineProps({
    licitaciones: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div>
        <Head title="Licitaciones" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    M3 — Generación Documental
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Expedientes de licitación</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Análisis de bases, checklist y redacción asistida por IA.
                            </p>
                        </div>
                        <Link :href="route('licitacion.create')">
                            <Button label="Nueva Licitación" icon="pi pi-plus" />
                        </Link>
                    </div>

                    <div v-if="licitaciones.length" class="grid gap-4">
                        <div v-for="licitacion in licitaciones" :key="licitacion.id" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h4 class="text-base font-semibold text-gray-900">{{ licitacion.title }}</h4>
                                    <p class="mt-1 text-sm text-gray-600">
                                        Empresa: {{ licitacion.company?.nombre }} ({{ licitacion.company?.rfc }})
                                    </p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium uppercase tracking-wide text-slate-700">
                                    {{ licitacion.process_type }}
                                </span>
                            </div>
                            <p class="mt-3 text-sm text-gray-500">
                                Regulaciones asignadas: {{ licitacion.regulations?.length || 0 }}
                            </p>
                            <p class="mt-1 text-sm text-gray-500">
                                Estado: {{ licitacion.status }}
                            </p>
                            <p class="mt-1 text-sm text-gray-500">
                                Bases: {{ licitacion.bases_document_original_name || 'Pendiente de carga' }}
                            </p>
                            <div class="mt-4 flex justify-end gap-2">
                                <Link v-if="licitacion.status !== 'committed'" :href="route('licitacion.edit', licitacion.id)">
                                    <Button label="Editar" icon="pi pi-pencil" size="small" severity="secondary" />
                                </Link>
                                <Link :href="route('licitacion.show', licitacion.id)">
                                    <Button label="Abrir expediente" icon="pi pi-folder-open" size="small" />
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-20 text-center">
                        <span class="pi pi-file text-4xl text-gray-300"></span>
                        <h4 class="mt-4 text-base font-medium text-gray-600">Sin licitaciones activas</h4>
                        <p class="mt-1 text-sm text-gray-400">Inicia un nuevo expediente para comenzar el proceso.</p>
                        <Link :href="route('licitacion.create')" class="mt-6">
                            <Button label="Crear Expediente" icon="pi pi-plus" />
                        </Link>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
