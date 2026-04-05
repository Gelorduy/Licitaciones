<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Button from 'primevue/button';

defineProps({
    companies: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div>
        <Head title="Empresas" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    M1 — Gestión Empresarial
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">

                    <!-- Page header -->
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Empresas registradas</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Administra actas, opiniones de cumplimiento, estados financieros y declaraciones por empresa.
                            </p>
                        </div>
                        <Link :href="route('empresa.create')">
                            <Button label="Nueva Empresa" icon="pi pi-plus" />
                        </Link>
                    </div>

                    <div v-if="companies.length" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Empresa</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">RFC</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Actas</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Opiniones</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="company in companies" :key="company.id">
                                    <td class="px-4 py-4 text-sm font-medium text-gray-900">{{ company.nombre }}</td>
                                    <td class="px-4 py-4 text-sm text-gray-600">{{ company.rfc }}</td>
                                    <td class="px-4 py-4 text-sm text-gray-600">{{ company.actas_count }}</td>
                                    <td class="px-4 py-4 text-sm text-gray-600">{{ company.opiniones_cumplimiento_count }}</td>
                                    <td class="px-4 py-4 text-right">
                                        <Link :href="route('empresa.show', company.id)">
                                            <Button label="Ver detalle" icon="pi pi-arrow-right" iconPos="right" size="small" />
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-20 text-center">
                        <span class="pi pi-building text-4xl text-gray-300"></span>
                        <h4 class="mt-4 text-base font-medium text-gray-600">Sin empresas registradas</h4>
                        <p class="mt-1 text-sm text-gray-400">Agrega tu primera empresa para comenzar.</p>
                        <Link :href="route('empresa.create')" class="mt-6">
                            <Button label="Agregar Empresa" icon="pi pi-plus" />
                        </Link>
                    </div>
                </div>

            </div>
        </AuthenticatedLayout>
    </div>
</template>
