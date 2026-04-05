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
    errorMessage: {
        type: String,
        default: null,
    },
    vectorIndexError: {
        type: String,
        default: null,
    },
    extractedText: {
        type: String,
        default: null,
    },
    metadata: {
        type: Object,
        default: null,
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
</script>

<template>
    <div>
        <Head :title="`Texto OCR · ${documentLabel}`" />

        <AuthenticatedLayout>
            <template #header>
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Texto OCR · {{ documentLabel }}
                    </h2>
                    <Link :href="backUrl">
                        <Button :label="backLabel" icon="pi pi-arrow-left" severity="secondary" text />
                    </Link>
                </div>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="grid gap-4 md:grid-cols-4">
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
                                <p class="text-xs uppercase tracking-wide text-gray-500">Chunks</p>
                                <p class="mt-1 text-sm font-medium text-gray-800">{{ chunkCount ?? 0 }}</p>
                            </div>
                        </div>

                        <p v-if="errorMessage" class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            <strong>Error de extracción:</strong> {{ errorMessage }}
                        </p>
                        <p v-if="vectorIndexError" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            <strong>Error de indexación vectorial:</strong> {{ vectorIndexError }}<br />
                            <span class="text-xs text-amber-700">El texto ya fue extraído. Puede reintentar la indexación desde la consola: <code>php artisan documents:requeue-indexing</code></span>
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Texto extraído</h3>
                        <pre class="mt-4 max-h-130 overflow-auto whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm leading-relaxed text-slate-800">{{ extractedText || 'Todavía no hay texto extraído para este documento.' }}</pre>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Metadatos detectados</h3>
                        <pre class="mt-4 overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm leading-relaxed text-slate-800">{{ metadata ? JSON.stringify(metadata, null, 2) : '{}' }}</pre>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
