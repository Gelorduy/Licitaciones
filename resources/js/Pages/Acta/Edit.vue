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
    rpc_fecha_inscripcion: props.acta.rpc_fecha_inscripcion ?? '',
    fecha_inscripcion: props.acta.fecha_inscripcion ?? '',
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

const extractionMetadata = props.acta.document_index?.metadata || {};
const fieldSources = extractionMetadata.field_sources || {};
const fieldConfidence = extractionMetadata.field_confidence || {};

const aiScalarFields = [
    'fecha_registro',
    'rpc_folio',
    'rpc_lugar',
    'rpc_fecha_inscripcion',
    'notaria_numero',
    'notaria_lugar',
    'notario_nombre',
    'escritura_numero',
    'libro_numero',
    'fecha_inscripcion',
    'acto',
];

const differingAiFields = aiScalarFields.filter((field) => {
    const aiValue = extractionMetadata[field];
    const savedValue = props.acta[field];

    if (aiValue === null || aiValue === undefined || aiValue === '') {
        return false;
    }

    return String(aiValue) !== String(savedValue ?? '');
});

const loadAiValuesIntoForm = () => {
    aiScalarFields.forEach((field) => {
        const value = extractionMetadata[field];
        if (value !== null && value !== undefined && value !== '') {
            form[field] = value;
        }
    });
};

const extractionRows = aiScalarFields
    .map((field) => {
        const aiValue = extractionMetadata[field];
        if (aiValue === null || aiValue === undefined || aiValue === '') {
            return null;
        }

        return {
            field,
            source: fieldSources[field] || 'n/a',
            confidence: fieldConfidence[field] ?? null,
            aiValue,
            savedValue: props.acta[field] ?? '',
        };
    })
    .filter(Boolean);

const formatConfidence = (value) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return 'n/a';
    }

    return `${Math.round(Number(value) * 100)}%`;
};

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

const missingRequiredFields = props.acta.document_index?.metadata?.required_missing_fields || [];
const hasExtractionAlerts = props.acta.document_index?.metadata?.has_required_missing_fields;
const extractionSource = props.acta.document_index?.metadata?.extraction_source || null;
const visionBudgetExhausted = !!extractionMetadata.vision_budget_exhausted;
const visionElapsedMs = extractionMetadata.vision_elapsed_ms ?? null;
const visionBudgetMs = extractionMetadata.vision_budget_ms ?? null;

const reextractForm = useForm({});

const requestReextract = () => {
    reextractForm.post(route('acta.reextract', [props.company.id, props.acta.id]));
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
                        <div v-if="hasExtractionAlerts" class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
                            <h3 class="text-sm font-semibold text-red-800">Alerta de extracción automática</h3>
                            <p class="mt-1 text-sm text-red-700">
                                El sistema no encontró todos los campos obligatorios del acta. Revísalos y complétalos manualmente.
                            </p>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-red-700">
                                <li v-for="field in missingRequiredFields" :key="field">{{ field }}</li>
                            </ul>
                        </div>

                        <div class="mb-5 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600" v-if="extractionSource">
                            <p>
                                Fuente de extracción detectada: <span class="font-medium">{{ extractionSource }}</span>
                            </p>
                            <p class="mt-1">
                                El formulario muestra los valores guardados del acta. La extracción AI se guarda en <span class="font-medium">document_index.metadata</span>.
                            </p>
                            <div v-if="differingAiFields.length" class="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-amber-800">
                                <p class="font-medium">Se detectaron diferencias entre valores guardados y AI ({{ differingAiFields.length }} campos).</p>
                                <Button
                                    class="mt-2"
                                    label="Cargar valores AI en el formulario"
                                    icon="pi pi-download"
                                    size="small"
                                    severity="warning"
                                    @click="loadAiValuesIntoForm"
                                />
                            </div>

                            <div v-if="visionBudgetExhausted" class="mt-2 rounded border border-red-200 bg-red-50 p-2 text-red-800">
                                La extracción por visión alcanzó su presupuesto de tiempo.
                                <span v-if="visionElapsedMs && visionBudgetMs" class="font-medium">
                                    ({{ visionElapsedMs }} ms / {{ visionBudgetMs }} ms)
                                </span>
                            </div>

                            <div v-if="extractionRows.length" class="mt-3 overflow-x-auto">
                                <table class="min-w-full border-collapse text-[11px]">
                                    <thead>
                                        <tr class="bg-slate-100 text-slate-700">
                                            <th class="border border-slate-200 px-2 py-1 text-left">Campo</th>
                                            <th class="border border-slate-200 px-2 py-1 text-left">Fuente</th>
                                            <th class="border border-slate-200 px-2 py-1 text-left">Confianza</th>
                                            <th class="border border-slate-200 px-2 py-1 text-left">Valor AI</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in extractionRows" :key="row.field" class="bg-white">
                                            <td class="border border-slate-200 px-2 py-1 font-medium">{{ row.field }}</td>
                                            <td class="border border-slate-200 px-2 py-1">{{ row.source }}</td>
                                            <td class="border border-slate-200 px-2 py-1">{{ formatConfidence(row.confidence) }}</td>
                                            <td class="border border-slate-200 px-2 py-1">{{ row.aiValue }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-xs text-amber-800">
                                    Si ajustas el prompt/modelo o subes un mejor OCR, vuelve a ejecutar la extracción automática.
                                </p>
                                <Button
                                    label="Re-ejecutar extracción AI"
                                    icon="pi pi-refresh"
                                    size="small"
                                    severity="warning"
                                    :loading="reextractForm.processing"
                                    @click="requestReextract"
                                />
                            </div>
                        </div>

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
                                    <InputLabel value="RPC Fecha de inscripción" />
                                    <TextInput v-model="form.rpc_fecha_inscripcion" type="date" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.rpc_fecha_inscripcion" />
                                </div>

                                <div>
                                    <InputLabel value="Fecha de inscripción" />
                                    <TextInput v-model="form.fecha_inscripcion" type="date" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.fecha_inscripcion" />
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

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Libro Número" />
                                    <TextInput v-model="form.libro_numero" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.libro_numero" />
                                </div>
                            </div>

                            <div v-if="Array.isArray(acta.apoderados) && acta.apoderados.length" class="rounded-lg border border-blue-100 bg-blue-50 p-4">
                                <h4 class="text-sm font-semibold text-blue-900">Apoderados extraídos</h4>
                                <div class="mt-2 space-y-2 text-xs text-blue-800">
                                    <div v-for="(apoderado, idx) in acta.apoderados" :key="idx" class="rounded border border-blue-200 bg-white p-2">
                                        <p><span class="font-medium">Nombre:</span> {{ apoderado.nombre_completo || 'N/A' }}</p>
                                        <p><span class="font-medium">INE:</span> {{ apoderado.ine || 'N/A' }}</p>
                                        <p><span class="font-medium">Poder:</span> {{ apoderado.poder_documento || 'N/A' }}</p>
                                        <p><span class="font-medium">Facultades:</span> {{ apoderado.facultades_otorgadas || 'N/A' }}</p>
                                    </div>
                                </div>
                            </div>

                            <div v-if="Array.isArray(acta.participacion_accionaria) && acta.participacion_accionaria.length" class="rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                                <h4 class="text-sm font-semibold text-emerald-900">Participación accionaria extraída</h4>
                                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-emerald-800">
                                    <li v-for="(item, idx) in acta.participacion_accionaria" :key="idx">
                                        {{ item.socio || 'Socio' }}: {{ item.porcentaje || 'N/A' }}
                                    </li>
                                </ul>
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
