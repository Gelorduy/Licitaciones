<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import { ref } from 'vue';

const props = defineProps({
    regulations: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({ country: '', scope: '' }),
    },
    availableScopes: {
        type: Array,
        default: () => [],
    },
    availableCountries: {
        type: Array,
        default: () => [],
    },
});

const selectedCountry = ref(props.filters.country || '');
const selectedScope = ref(props.filters.scope || '');

const applyFilters = () => {
    router.get(route('regulacion.index'), {
        country: selectedCountry.value || undefined,
        scope: selectedScope.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    selectedCountry.value = '';
    selectedScope.value = '';
    applyFilters();
};
</script>

<template>
    <div>
        <Head title="Regulaciones" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    M2 — Regulaciones Aplicables
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">

                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Biblioteca normativa</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Leyes y normas por país, ámbito y entidad regulatoria.
                            </p>
                        </div>
                        <Link :href="route('regulacion.create')">
                            <Button label="Nueva Regulación" icon="pi pi-plus" />
                        </Link>
                    </div>

                    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="grid gap-4 md:grid-cols-3">
                            <div>
                                <label class="text-sm font-medium text-gray-700">País</label>
                                <select v-model="selectedCountry" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    <option value="">Todos</option>
                                    <option v-for="country in availableCountries" :key="country" :value="country">{{ country }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-gray-700">Ámbito</label>
                                <select v-model="selectedScope" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    <option value="">Todos</option>
                                    <option v-for="scope in availableScopes" :key="scope" :value="scope">{{ scope }}</option>
                                </select>
                            </div>
                            <div class="flex items-end gap-2">
                                <Button label="Aplicar" icon="pi pi-filter" size="small" @click="applyFilters" />
                                <Button label="Limpiar" icon="pi pi-times" severity="secondary" text size="small" @click="clearFilters" />
                            </div>
                        </div>
                    </div>

                    <div v-if="regulations.length" class="grid gap-4">
                        <div v-for="regulation in regulations" :key="regulation.id" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h4 class="text-base font-semibold text-gray-900">{{ regulation.title }}</h4>
                                    <p class="mt-1 text-sm text-gray-600">{{ regulation.general_description }}</p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium uppercase tracking-wide text-slate-700">
                                    {{ regulation.country_code }} · {{ regulation.scope }}
                                </span>
                            </div>
                            <p class="mt-3 text-sm text-gray-500">
                                Entidad: {{ regulation.regulatory_body || 'No especificada' }}
                            </p>
                            <p class="mt-2 text-xs text-gray-500">
                                Estado de indexación:
                                <span class="rounded-full bg-slate-100 px-2 py-1 font-medium text-slate-700">
                                    {{ regulation.document_index?.status || 'pendiente' }}
                                </span>
                            </p>
                            <p class="mt-2 text-xs text-gray-500">
                                Archivo: <span class="font-medium text-gray-700">{{ regulation.source_pdf_name || 'Sin archivo' }}</span>
                            </p>
                            <div class="mt-4 flex justify-end">
                                <a
                                    v-if="regulation.source_pdf_path"
                                    :href="route('regulacion.file.view', regulation.id)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="me-2"
                                >
                                    <Button label="Ver PDF" icon="pi pi-eye" severity="secondary" size="small" />
                                </a>
                                <a
                                    v-if="regulation.source_pdf_path"
                                    :href="route('regulacion.file.download', regulation.id)"
                                    class="me-2"
                                >
                                    <Button label="Descargar" icon="pi pi-download" severity="secondary" size="small" />
                                </a>
                                <Link :href="route('regulacion.text.view', regulation.id)" class="me-2">
                                    <Button label="Ver texto OCR" icon="pi pi-file" severity="help" size="small" />
                                </Link>
                                <Link :href="route('regulacion.edit', regulation.id)">
                                    <Button label="Editar" icon="pi pi-pencil" size="small" />
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-20 text-center">
                        <span class="pi pi-book text-4xl text-gray-300"></span>
                        <h4 class="mt-4 text-base font-medium text-gray-600">Sin regulaciones registradas</h4>
                        <p class="mt-1 text-sm text-gray-400">Agrega la primera norma o ley aplicable.</p>
                        <Link :href="route('regulacion.create')" class="mt-6">
                            <Button label="Agregar Regulación" icon="pi pi-plus" />
                        </Link>
                    </div>

                </div>

            </div>
        </AuthenticatedLayout>
    </div>
</template>
