<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';

const props = defineProps({
    validations: { type: Array, default: () => [] },
    availableLicitaciones: { type: Array, default: () => [] },
});

const form = useForm({
    licitacion_id: props.availableLicitaciones[0]?.id ?? null,
});

const submit = () => {
    form.post(route('validacion.store'));
};

const badgeClass = (trafficLight) => ({
    green: 'bg-emerald-100 text-emerald-700',
    yellow: 'bg-amber-100 text-amber-700',
    red: 'bg-red-100 text-red-700',
}[trafficLight] || 'bg-slate-100 text-slate-700');
</script>

<template>
    <div>
        <Head title="Validación" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    M4 — Revisión y Validación
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="mb-4 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Validación de propuestas</h3>
                                <p class="mt-1 text-sm text-gray-500">Semáforo de cumplimiento, override legal y exportación final.</p>
                            </div>
                        </div>

                        <form class="grid gap-4 md:grid-cols-[1fr_auto]" @submit.prevent="submit">
                            <div>
                                <label class="text-sm font-medium text-gray-700">Expediente disponible</label>
                                <select v-model="form.licitacion_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                    <option v-for="licitacion in availableLicitaciones" :key="licitacion.id" :value="licitacion.id">
                                        {{ licitacion.title }} · {{ licitacion.company?.nombre }}
                                    </option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <Button type="submit" label="Iniciar Validación" icon="pi pi-check" :disabled="!form.licitacion_id" :loading="form.processing" />
                            </div>
                        </form>
                    </div>

                    <div v-if="validations.length" class="grid gap-4">
                        <div v-for="validation in validations" :key="validation.id" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h4 class="text-base font-semibold text-gray-900">{{ validation.title }}</h4>
                                    <p class="mt-1 text-sm text-gray-600">{{ validation.company?.nombre }} ({{ validation.company?.rfc }})</p>
                                </div>
                                <span class="rounded-full px-3 py-1 text-xs font-medium uppercase tracking-wide" :class="badgeClass(validation.traffic_light)">
                                    {{ validation.traffic_light }}
                                </span>
                            </div>
                            <p class="mt-3 text-sm text-gray-500">Estado: {{ validation.status }}</p>
                            <p class="mt-1 text-sm text-gray-500">Override legal: {{ validation.override_applied ? 'Sí' : 'No' }}</p>
                            <div class="mt-4 flex justify-end">
                                <Link :href="route('validacion.show', validation.id)">
                                    <Button label="Abrir validación" icon="pi pi-folder-open" size="small" />
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-20 text-center">
                        <span class="pi pi-check-circle text-4xl text-gray-300"></span>
                        <h4 class="mt-4 text-base font-medium text-gray-600">Sin propuestas en revisión</h4>
                        <p class="mt-1 text-sm text-gray-400">Selecciona un expediente para iniciar la validación.</p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
