<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Card from 'primevue/card';
import Button from 'primevue/button';

defineProps({
    overview: {
        type: Object,
        required: true,
    },
    modules: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div>
        <Head title="Dashboard" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Centro de Operaciones de Licitaciones
                </h2>
            </template>

            <div class="py-12">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div class="mb-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm uppercase tracking-wide text-slate-500">Resumen general</p>
                        <h3 class="mt-1 text-2xl font-semibold text-slate-900">Estado operativo de la plataforma</h3>
                        <p class="mt-2 text-slate-600">
                            Indicadores actuales de carga documental, procesamiento OCR y estado por modulo.
                        </p>
                        <p class="mt-2 text-xs text-slate-500">
                            Ultima actualizacion:
                            <span class="font-medium text-slate-700">
                                {{ overview.last_updated_at ? new Date(overview.last_updated_at).toLocaleString() : 'Sin actividad registrada' }}
                            </span>
                        </p>

                        <div class="mt-5 grid gap-3 md:grid-cols-6">
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Empresas</p>
                                <p class="mt-1 text-xl font-semibold text-slate-900">{{ overview.companies }}</p>
                                <p class="mt-1 text-xs text-emerald-700">hoy +{{ overview.trends.companies }}</p>
                            </div>
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Documentos</p>
                                <p class="mt-1 text-xl font-semibold text-slate-900">{{ overview.documents }}</p>
                                <p class="mt-1 text-xs text-emerald-700">hoy +{{ overview.trends.documents }}</p>
                            </div>
                            <div class="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                                <p class="text-xs uppercase tracking-wide text-emerald-700">Indexados</p>
                                <p class="mt-1 text-xl font-semibold text-emerald-800">{{ overview.indexed }}</p>
                                <p class="mt-1 text-xs text-emerald-700">hoy +{{ overview.trends.indexed }}</p>
                            </div>
                            <div class="rounded-md border border-cyan-200 bg-cyan-50 p-3">
                                <p class="text-xs uppercase tracking-wide text-cyan-700">Procesados</p>
                                <p class="mt-1 text-xl font-semibold text-cyan-800">{{ overview.processed }}</p>
                                <p class="mt-1 text-xs text-cyan-700">hoy +{{ overview.trends.processed }}</p>
                            </div>
                            <div class="rounded-md border border-amber-200 bg-amber-50 p-3">
                                <p class="text-xs uppercase tracking-wide text-amber-700">Pendientes</p>
                                <p class="mt-1 text-xl font-semibold text-amber-800">{{ overview.pending }}</p>
                                <p class="mt-1 text-xs text-amber-700">hoy +{{ overview.trends.pending }}</p>
                            </div>
                            <div class="rounded-md border border-red-200 bg-red-50 p-3">
                                <p class="text-xs uppercase tracking-wide text-red-700">Con error</p>
                                <p class="mt-1 text-xl font-semibold text-red-800">{{ overview.failed }}</p>
                                <p class="mt-1 text-xs text-red-700">hoy +{{ overview.trends.failed }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <Card v-for="item in modules" :key="item.title" class="shadow-sm hover:shadow-md transition-shadow">
                            <template #title>{{ item.subtitle }}</template>
                            <template #content>
                                <p class="m-0 text-sm text-slate-600">
                                    Estado:
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-medium"
                                        :class="item.status === 'activo' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'"
                                    >
                                        {{ item.status === 'activo' ? 'Activo' : 'Placeholder' }}
                                    </span>
                                </p>

                                <div class="mt-4 grid gap-2 sm:grid-cols-3">
                                    <div
                                        v-for="metric in item.metrics"
                                        :key="`${item.title}-${metric.label}`"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <p class="text-xs text-slate-500">{{ metric.label }}</p>
                                        <p class="mt-1 text-lg font-semibold text-slate-900">{{ metric.value }}</p>
                                        <p class="mt-1 text-xs text-emerald-700">hoy +{{ metric.today ?? 0 }}</p>
                                    </div>
                                </div>
                            </template>
                            <template #footer>
                                <div class="flex justify-end">
                                    <Link :href="route(item.route)">
                                        <Button :label="item.action" icon="pi pi-arrow-right" iconPos="right" size="small" />
                                    </Link>
                                </div>
                            </template>
                        </Card>
                    </div>

                    <div class="mt-6 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-700">
                        Los modulos en placeholder muestran desde ahora los indicadores que presentaran cuando se implementen.
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
