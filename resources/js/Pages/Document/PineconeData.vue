<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
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
    chunkCorrection: {
        type: Object,
        default: () => ({
            enabled: false,
            routeName: null,
            routeParamsBase: [],
        }),
    },
});

const page = usePage();
const correctionForm = useForm({});
const correctingChunkIndex = ref(null);

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

const flash = computed(() => page.props.flash || {});
const hasChunkCorrection = computed(() => !!props.chunkCorrection?.enabled && !!props.chunkCorrection?.routeName);

const correctionBadgeLabel = (record) => {
    if (record?.quality?.correctedWithTargetedOcr) {
        return 'Corregido con OCR dirigido';
    }

    if (record?.quality?.correctedWithVision) {
        return 'Corregido con visión';
    }

    return 'Corregido';
};

const requestChunkCorrection = (record) => {
    if (!hasChunkCorrection.value || record?.chunkIndex === null || record?.chunkIndex === undefined) {
        return;
    }

    const confirmed = window.confirm(
        `Se intentará corregir el chunk ${record.chunkIndex} con visión usando las páginas ${formatRecordPages(record)}. Si el motor visual no responde, se usará OCR dirigido sobre esas mismas páginas y luego se reindexará Pinecone. ¿Deseas continuar?`,
    );

    if (!confirmed) {
        return;
    }

    correctingChunkIndex.value = record.chunkIndex;
    correctionForm.post(route(props.chunkCorrection.routeName, [...props.chunkCorrection.routeParamsBase, record.chunkIndex]), {
        preserveScroll: true,
        onFinish: () => {
            correctingChunkIndex.value = null;
        },
    });
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
                    <div v-if="flash.success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 shadow-sm">
                        {{ flash.success }}
                    </div>

                    <div v-if="flash.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 shadow-sm">
                        {{ flash.error }}
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="grid gap-4 md:grid-cols-4 xl:grid-cols-8">
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
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Chunks sospechosos</p>
                                <p class="mt-1 text-sm font-medium text-gray-800">{{ pineconeData.suspiciousCount ?? 0 }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Chunks corregidos</p>
                                <p class="mt-1 text-sm font-medium text-gray-800">{{ pineconeData.correctedCount ?? 0 }}</p>
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
                                class="rounded-lg border p-4"
                                :class="{
                                    'border-red-200 bg-red-50': record.quality?.suspicious,
                                    'border-emerald-200 bg-emerald-50': !record.quality?.suspicious && record.quality?.corrected,
                                    'border-slate-200 bg-slate-50': !record.quality?.suspicious && !record.quality?.corrected,
                                }"
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

                                <div v-if="record.quality?.suspicious || record.quality?.corrected || hasChunkCorrection" class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h4 class="text-sm font-semibold text-slate-900">Revisión del chunk</h4>
                                            <p class="mt-1 text-xs text-slate-500">
                                                Score de sospecha: {{ record.quality?.score ?? 0 }}
                                            </p>
                                        </div>

                                        <div class="flex flex-wrap gap-2">
                                            <span v-if="record.quality?.suspicious" class="rounded-full border border-red-200 bg-red-100 px-2 py-1 text-[11px] font-semibold text-red-700">
                                                Sospechoso
                                            </span>
                                            <span v-if="record.quality?.corrected" class="rounded-full border border-emerald-200 bg-emerald-100 px-2 py-1 text-[11px] font-semibold text-emerald-700">
                                                {{ correctionBadgeLabel(record) }}
                                            </span>
                                        </div>
                                    </div>

                                    <ul v-if="record.quality?.reasons?.length" class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-700">
                                        <li v-for="reason in record.quality.reasons" :key="reason">{{ reason }}</li>
                                    </ul>

                                    <p v-if="record.quality?.correctedAt" class="mt-3 text-xs text-slate-500">
                                        Última corrección: {{ record.quality.correctedAt }}
                                    </p>

                                    <div v-if="hasChunkCorrection && record.quality?.correctionAvailable" class="mt-4">
                                        <Button
                                            label="Corregir chunk"
                                            icon="pi pi-eye"
                                            size="small"
                                            severity="warning"
                                            :loading="correctionForm.processing && correctingChunkIndex === record.chunkIndex"
                                            :disabled="correctionForm.processing && correctingChunkIndex !== record.chunkIndex"
                                            @click.prevent="requestChunkCorrection(record)"
                                        />
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