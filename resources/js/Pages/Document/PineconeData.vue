<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Button from 'primevue/button';

const props = defineProps({
    documentLabel: {
        type: String,
        required: true,
    },
    documentName: {
        type: String,
        default: null,
    },
    status: {
        type: String,
        default: null,
    },
    extractionMethod: {
        type: String,
        default: null,
    },
    chunkCount: {
        type: Number,
        default: null,
    },
    vectorIndexError: {
        type: String,
        default: null,
    },
    pineconeData: {
        type: Object,
        default: () => ({
            available: false,
            namespace: null,
            baseId: null,
            indexHost: null,
            vectorIds: [],
            vectorIdCount: 0,
            records: [],
            recordCount: 0,
            errors: [],
        }),
    },
    backUrl: {
        type: String,
        required: true,
    },
    backLabel: {
        type: String,
        default: 'Volver',
    },
});

const prettyJson = (value) => JSON.stringify(value, null, 2);
const formatRecordPages = (record) => {
    if (record?.pageNumbers) {
        return record.pageNumbers;
    }

    if (Array.isArray(record?.pageIds) && record.pageIds.length) {
        return record.pageIds.join(', ');
    }

    return 'n/a';
};
</script>

<template>
    <div>
        <Head :title="`Datos Pinecone · ${documentLabel}`" />

        <AuthenticatedLayout>
            <template #header>
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Datos Pinecone · {{ documentLabel }}
                    </h2>
                    <Link :href="backUrl">
                        <Button :label="backLabel" icon="pi pi-arrow-left" severity="secondary" text />
                    </Link>
                </div>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="grid gap-4 md:grid-cols-4 xl:grid-cols-6">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Documento</p>
                                <p class="mt-1 text-sm font-medium text-gray-800">{{ documentName || 'Sin nombre' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Estado</p>
                                <p class="mt-1 text-sm font-medium text-gray-800">{{ status || 'sin proceso' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Método</p>
                                <p class="mt-1 text-sm font-medium text-gray-800">{{ extractionMethod || 'N/A' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Chunks esperados</p>
                                <p class="mt-1 text-sm font-medium text-gray-800">{{ chunkCount ?? 0 }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Vectores listados</p>
                                <p class="mt-1 text-sm font-medium text-gray-800">{{ pineconeData.vectorIdCount ?? 0 }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Registros recuperados</p>
                                <p class="mt-1 text-sm font-medium text-gray-800">{{ pineconeData.recordCount ?? 0 }}</p>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Namespace</p>
                                <p class="mt-1 break-all text-sm font-medium text-gray-800">{{ pineconeData.namespace || 'N/A' }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Base ID</p>
                                <p class="mt-1 break-all text-sm font-medium text-gray-800">{{ pineconeData.baseId || 'N/A' }}</p>
                            </div>
                        </div>

                        <p v-if="pineconeData.indexHost" class="mt-4 text-xs text-slate-500">
                            Host Pinecone consultado: <span class="font-medium">{{ pineconeData.indexHost }}</span>
                        </p>

                        <p v-if="vectorIndexError" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            <strong>Error de indexación vectorial:</strong> {{ vectorIndexError }}
                        </p>
                        <p v-for="error in pineconeData.errors || []" :key="error" class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            <strong>Error de inspección:</strong> {{ error }}
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">IDs de vectores en Pinecone</h3>
                        <pre class="mt-4 max-h-72 overflow-auto whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm leading-relaxed text-slate-800">{{ (pineconeData.vectorIds && pineconeData.vectorIds.length) ? pineconeData.vectorIds.join('\n') : 'Todavía no hay IDs de vectores listados para este documento.' }}</pre>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Registros almacenados en Pinecone</h3>
                        <div v-if="pineconeData.records && pineconeData.records.length" class="mt-4 space-y-4">
                            <details
                                v-for="record in pineconeData.records"
                                :key="record.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <summary class="cursor-pointer list-none">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ record.id }}</p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                Chunk {{ record.chunkIndex ?? 'n/a' }} · Páginas {{ formatRecordPages(record) }} · Dimensión {{ record.dimension ?? 0 }}
                                            </p>
                                        </div>
                                        <span class="rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-600 shadow-sm">
                                            Abrir detalle
                                        </span>
                                    </div>
                                </summary>

                                <div class="mt-4 grid gap-4 xl:grid-cols-2">
                                    <div>
                                        <h4 class="text-sm font-semibold text-slate-900">Texto guardado</h4>
                                        <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg border border-slate-200 bg-white p-3 text-sm leading-relaxed text-slate-800">{{ record.text || 'Sin texto en metadata.' }}</pre>
                                    </div>

                                    <div>
                                        <h4 class="text-sm font-semibold text-slate-900">Metadata</h4>
                                        <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg border border-slate-200 bg-white p-3 text-sm leading-relaxed text-slate-800">{{ prettyJson(record.metadata || {}) }}</pre>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <h4 class="text-sm font-semibold text-slate-900">Registro Pinecone completo</h4>
                                    <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded-lg border border-slate-200 bg-white p-3 text-sm leading-relaxed text-slate-800">{{ prettyJson(record.raw || {}) }}</pre>
                                </div>
                            </details>
                        </div>

                        <p v-else class="mt-4 text-sm text-slate-500">
                            Todavía no hay registros recuperados desde Pinecone para este documento.
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>