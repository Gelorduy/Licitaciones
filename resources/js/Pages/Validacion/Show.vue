<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';

const props = defineProps({
    validation: { type: Object, required: true },
});

const overrideForm = useForm({
    override_reason: props.validation.override_reason || '',
});

const applyOverride = () => {
    overrideForm.post(route('validacion.override', props.validation.id));
};

const persistedFindings = props.validation.findings || [];

const criticalFindings = persistedFindings.length
    ? persistedFindings.filter((finding) => finding.severity === 'critical')
    : (props.validation.report?.issues || []).map((message, idx) => ({
        id: `legacy-critical-${idx}`,
        rule_code: 'LEGACY',
        category: 'cumplimiento',
        message,
    }));

const warningFindings = persistedFindings.length
    ? persistedFindings.filter((finding) => finding.severity === 'warning')
    : (props.validation.report?.warnings || []).map((message, idx) => ({
        id: `legacy-warning-${idx}`,
        rule_code: 'LEGACY',
        category: 'seguimiento',
        message,
    }));

const badgeClass = ({
    green: 'bg-emerald-100 text-emerald-700',
    yellow: 'bg-amber-100 text-amber-700',
    red: 'bg-red-100 text-red-700',
}[props.validation.traffic_light] || 'bg-slate-100 text-slate-700');
</script>

<template>
    <div>
        <Head :title="`Validación · ${validation.licitacion?.title || 'Expediente'}`" />

        <AuthenticatedLayout>
            <template #header>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ validation.licitacion?.title }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ validation.licitacion?.company?.nombre }} ({{ validation.licitacion?.company?.rfc }})</p>
                    </div>
                    <Link :href="route('validacion.index')">
                        <Button label="Volver" severity="secondary" text icon="pi pi-arrow-left" />
                    </Link>
                </div>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div class="grid gap-4 md:grid-cols-4">
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Semáforo</p>
                            <span class="mt-3 inline-flex rounded-full px-3 py-1 text-xs font-medium uppercase tracking-wide" :class="badgeClass">{{ validation.traffic_light }}</span>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Estado</p>
                            <p class="mt-2 text-base font-semibold text-gray-900">{{ validation.status }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Override</p>
                            <p class="mt-2 text-base font-semibold text-gray-900">{{ validation.override_applied ? 'Aplicado' : 'No aplicado' }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Checklist</p>
                            <p class="mt-2 text-base font-semibold text-gray-900">{{ validation.report?.summary?.checks_passed || 0 }}/{{ validation.report?.summary?.checks_total || 0 }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap gap-3">
                            <form @submit.prevent="$inertia.post(route('validacion.audit', validation.id))">
                                <Button type="submit" label="Reejecutar auditoría" icon="pi pi-refresh" />
                            </form>
                            <a :href="route('validacion.export-usb', validation.id)">
                                <Button label="Exportar ZIP" icon="pi pi-download" severity="secondary" :disabled="!(validation.status === 'ready_for_export' || validation.override_applied)" />
                            </a>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                            <h3 class="text-base font-semibold text-gray-900">Hallazgos críticos</h3>
                            <ul class="mt-4 space-y-2 text-sm text-gray-700">
                                <li v-for="finding in criticalFindings" :key="finding.id" class="rounded-lg bg-red-50 px-3 py-2 text-red-700">
                                    <p class="font-medium">{{ finding.rule_code }} · {{ finding.category }}</p>
                                    <p class="mt-1">{{ finding.message }}</p>
                                </li>
                                <li v-if="!criticalFindings.length" class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-700">
                                    Sin hallazgos críticos.
                                </li>
                            </ul>
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                            <h3 class="text-base font-semibold text-gray-900">Observaciones</h3>
                            <ul class="mt-4 space-y-2 text-sm text-gray-700">
                                <li v-for="finding in warningFindings" :key="finding.id" class="rounded-lg bg-amber-50 px-3 py-2 text-amber-700">
                                    <p class="font-medium">{{ finding.rule_code }} · {{ finding.category }}</p>
                                    <p class="mt-1">{{ finding.message }}</p>
                                </li>
                                <li v-if="!warningFindings.length" class="rounded-lg bg-slate-50 px-3 py-2 text-slate-700">
                                    Sin observaciones pendientes.
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Checks de cumplimiento</h3>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div v-for="(check, idx) in validation.report?.checks || []" :key="idx" class="rounded-lg border border-gray-200 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm font-medium text-gray-900">{{ check.label }}</p>
                                    <span class="pi text-sm" :class="check.passed ? 'pi-check-circle text-emerald-600' : 'pi-times-circle text-red-600'"></span>
                                </div>
                                <p class="mt-2 text-sm text-gray-600">{{ check.detail }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Override legal</h3>
                        <p class="mt-1 text-sm text-gray-500">Permite liberar la exportación con justificación registrada.</p>
                        <form class="mt-4 space-y-4" @submit.prevent="applyOverride">
                            <textarea v-model="overrideForm.override_reason" class="block w-full rounded-md border-gray-300 text-sm shadow-sm" rows="5" placeholder="Describa la justificación legal del override..." />
                            <InputError :message="overrideForm.errors.override_reason" />
                            <div class="flex justify-end">
                                <Button type="submit" label="Aplicar override" icon="pi pi-shield" severity="warning" :loading="overrideForm.processing" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
