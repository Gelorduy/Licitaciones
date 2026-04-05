<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Button from 'primevue/button';
</script>

<template>
    <Head title="Validación" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                M4 — Revisión y Validación
            </h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">

                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Validación de propuestas</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Semáforo de cumplimiento, override legal y exportación final conforme a LAASSP.
                        </p>
                    </div>
                    <Button label="Nueva Validación" icon="pi pi-plus" />
                </div>

                <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-20 text-center">
                    <span class="pi pi-check-circle text-4xl text-gray-300"></span>
                    <h4 class="mt-4 text-base font-medium text-gray-600">Sin propuestas en revisión</h4>
                    <p class="mt-1 text-sm text-gray-400">Selecciona un expediente para iniciar la validación.</p>
                    <Button class="mt-6" label="Iniciar Validación" icon="pi pi-check" />
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
