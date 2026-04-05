<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Button from 'primevue/button';

defineProps({
    company: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div>
        <Head :title="company.nombre" />

        <AuthenticatedLayout>
            <template #header>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ company.nombre }}</h2>
                        <p class="mt-1 text-sm text-gray-500">RFC {{ company.rfc }}</p>
                    </div>
                    <Link :href="route('empresa.index')">
                        <Button label="Volver" severity="secondary" text icon="pi pi-arrow-left" />
                    </Link>
                </div>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Actas</p>
                            <p class="mt-2 text-3xl font-semibold text-gray-900">{{ company.actas.length }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Opiniones</p>
                            <p class="mt-2 text-3xl font-semibold text-gray-900">{{ company.opiniones_cumplimiento.length }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Estados Financieros</p>
                            <p class="mt-2 text-3xl font-semibold text-gray-900">{{ company.financial_statements.length }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Declaraciones</p>
                            <p class="mt-2 text-3xl font-semibold text-gray-900">{{ company.tax_declarations.length }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Hojas Membretadas</p>
                            <p class="mt-2 text-3xl font-semibold text-gray-900">{{ company.letterheads.length }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-gray-900">Actas Corporativas</h3>
                            <Link :href="route('acta.create', company.id)">
                                <Button label="Nueva Acta" icon="pi pi-plus" size="small" />
                            </Link>
                        </div>

                        <div v-if="company.actas.length" class="overflow-hidden rounded-lg border border-gray-100">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Tipo</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Fecha registro</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Notario</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">RPC folio</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Archivo</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Indexación</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="acta in company.actas" :key="acta.id">
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ acta.tipo }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ acta.fecha_registro || 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ acta.notario_nombre || 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ acta.rpc_folio || 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            {{ acta.documento_original_name || (acta.documento_path ? acta.documento_path.split('/').pop() : 'Sin archivo') }}
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                                                {{ acta.document_index?.status || 'pendiente' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <div class="flex flex-wrap gap-2">
                                                <a
                                                    v-if="acta.documento_path"
                                                    :href="route('acta.file.view', [company.id, acta.id])"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <Button label="Ver PDF" size="small" severity="secondary" text />
                                                </a>
                                                <a
                                                    v-if="acta.documento_path"
                                                    :href="route('acta.file.download', [company.id, acta.id])"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <Button label="Descargar" size="small" severity="secondary" text />
                                                </a>
                                                <Link :href="route('acta.text.view', [company.id, acta.id])">
                                                    <Button label="Ver texto OCR" size="small" severity="help" text />
                                                </Link>
                                                <Link :href="route('acta.edit', [company.id, acta.id])">
                                                    <Button label="Editar" size="small" severity="info" text />
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500">
                            Sin actas registradas.
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-gray-900">Opiniones de Cumplimiento</h3>
                            <Link :href="route('opinion.create', company.id)">
                                <Button label="Nueva Opinión" icon="pi pi-plus" size="small" />
                            </Link>
                        </div>

                        <div v-if="company.opiniones_cumplimiento.length" class="overflow-hidden rounded-lg border border-gray-100">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Tipo</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Estado</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Emisión</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Vigencia</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Archivo</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Indexación</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="opinion in company.opiniones_cumplimiento" :key="opinion.id">
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ opinion.tipo.toUpperCase() }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ opinion.estado }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ opinion.fecha_emision }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            <div class="flex items-center gap-2">
                                                <span>{{ opinion.vigencia_calculada }}</span>
                                                <span v-if="opinion.expiry_status === 'expired'" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Vencida</span>
                                                <span v-else-if="opinion.expiry_status === 'expiring'" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Por vencer ({{ opinion.days_to_expiry }}d)</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            {{ opinion.documento_original_name || (opinion.documento_path ? opinion.documento_path.split('/').pop() : 'Sin archivo') }}
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                                                {{ opinion.document_index?.status || 'pendiente' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <div class="flex flex-wrap gap-2">
                                                <a
                                                    v-if="opinion.documento_path"
                                                    :href="route('opinion.file.view', [company.id, opinion.id])"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <Button label="Ver PDF" size="small" severity="secondary" text />
                                                </a>
                                                <a
                                                    v-if="opinion.documento_path"
                                                    :href="route('opinion.file.download', [company.id, opinion.id])"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <Button label="Descargar" size="small" severity="secondary" text />
                                                </a>
                                                <Link :href="route('opinion.text.view', [company.id, opinion.id])">
                                                    <Button label="Ver texto OCR" size="small" severity="help" text />
                                                </Link>
                                                <Link :href="route('opinion.edit', [company.id, opinion.id])">
                                                    <Button label="Editar" size="small" severity="info" text />
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500">
                            Sin opiniones registradas.
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-gray-900">Estados Financieros</h3>
                            <Link :href="route('estado-financiero.create', company.id)">
                                <Button label="Nuevo Estado" icon="pi pi-plus" size="small" />
                            </Link>
                        </div>

                        <div v-if="company.financial_statements.length" class="overflow-hidden rounded-lg border border-gray-100">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Periodicidad</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Periodo</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Auditado</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Archivo</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="statement in company.financial_statements" :key="statement.id">
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ statement.periodicity }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ statement.month ? `${statement.month}/${statement.year}` : statement.year }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ statement.is_audited ? 'Sí' : 'No' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ statement.document_original_name || 'Sin archivo' }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <div class="flex flex-wrap gap-2">
                                                <a
                                                    v-if="statement.document_path"
                                                    :href="route('estado-financiero.file.view', [company.id, statement.id])"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <Button label="Ver" size="small" severity="secondary" text />
                                                </a>
                                                <a
                                                    v-if="statement.document_path"
                                                    :href="route('estado-financiero.file.download', [company.id, statement.id])"
                                                >
                                                    <Button label="Descargar" size="small" severity="secondary" text />
                                                </a>
                                                <Link :href="route('estado-financiero.edit', [company.id, statement.id])">
                                                    <Button label="Editar" size="small" severity="info" text />
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500">
                            Sin estados financieros registrados.
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-gray-900">Declaraciones de Impuestos</h3>
                            <Link :href="route('declaracion.create', company.id)">
                                <Button label="Nueva Declaración" icon="pi pi-plus" size="small" />
                            </Link>
                        </div>

                        <div v-if="company.tax_declarations.length" class="overflow-hidden rounded-lg border border-gray-100">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Periodicidad</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Periodo</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Formato</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Archivo</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="declaration in company.tax_declarations" :key="declaration.id">
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ declaration.periodicity }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ declaration.month ? `${declaration.month}/${declaration.year}` : declaration.year }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700 uppercase">{{ declaration.format }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ declaration.document_original_name || 'Sin archivo' }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <div class="flex flex-wrap gap-2">
                                                <a
                                                    v-if="declaration.document_path"
                                                    :href="route('declaracion.file.view', [company.id, declaration.id])"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <Button label="Ver" size="small" severity="secondary" text />
                                                </a>
                                                <a
                                                    v-if="declaration.document_path"
                                                    :href="route('declaracion.file.download', [company.id, declaration.id])"
                                                >
                                                    <Button label="Descargar" size="small" severity="secondary" text />
                                                </a>
                                                <Link :href="route('declaracion.edit', [company.id, declaration.id])">
                                                    <Button label="Editar" size="small" severity="info" text />
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500">
                            Sin declaraciones registradas.
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-gray-900">Hojas Membretadas</h3>
                            <Link :href="route('letterhead.create', company.id)">
                                <Button label="Nueva Hoja" icon="pi pi-plus" size="small" />
                            </Link>
                        </div>

                        <div v-if="company.letterheads.length" class="overflow-hidden rounded-lg border border-gray-100">
                            <table class="min-w-full divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Título</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Contacto</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Ciudad</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Estado</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="letterhead in company.letterheads" :key="letterhead.id">
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ letterhead.title }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ letterhead.contact_name || 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ letterhead.city || 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            <span v-if="letterhead.is_default" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Predeterminada</span>
                                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">Activa</span>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <Link :href="route('letterhead.edit', [company.id, letterhead.id])">
                                                <Button label="Editar" size="small" severity="info" text />
                                            </Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-500">
                            Sin hojas membretadas registradas.
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>