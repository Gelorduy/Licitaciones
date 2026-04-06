<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputError from '@/Components/InputError.vue';

defineProps({
    licitacion: { type: Object, required: true },
});

const sendForm = useForm({});
const approvalForm = useForm({
    approval_note: '',
});

const sendForApproval = (id) => {
    sendForm.post(route('licitacion.send-for-approval', id));
};

const approveSubmission = (id) => {
    approvalForm.post(route('licitacion.approve-submission', id));
};

const canSendForApproval = (licitacion) => {
    return !!(licitacion.validation && (licitacion.validation.status === 'ready_for_export' || licitacion.validation.override_applied));
};
</script>

<template>
    <div>
        <Head :title="licitacion.title" />

        <AuthenticatedLayout>
            <template #header>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ licitacion.title }}</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ licitacion.company?.nombre }} ({{ licitacion.company?.rfc }})
                        </p>
                    </div>
                    <Link :href="route('licitacion.index')">
                        <Button label="Volver" severity="secondary" text icon="pi pi-arrow-left" />
                    </Link>
                </div>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                    <div class="grid gap-4 md:grid-cols-4">
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Tipología</p>
                            <p class="mt-2 text-base font-semibold text-gray-900">{{ licitacion.process_type }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Estado</p>
                            <p class="mt-2 text-base font-semibold text-gray-900">{{ licitacion.status }}</p>
                            <p class="mt-1 text-xs text-gray-500" v-if="licitacion.status === 'analyzing'">Analizando bases en segundo plano...</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Regulaciones</p>
                            <p class="mt-2 text-base font-semibold text-gray-900">{{ licitacion.regulations?.length || 0 }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <p class="text-sm uppercase tracking-wide text-gray-500">Bases</p>
                            <p class="mt-2 text-sm font-medium text-gray-700">{{ licitacion.bases_document_original_name || 'Sin archivo' }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div v-if="licitacion.status === 'committed'" class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                            Esta licitacion ya fue comprometida tras aprobacion humana y ahora es inmutable.
                        </div>
                        <div v-else-if="licitacion.status === 'sent_for_approval'" class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                            Esta licitacion fue enviada y esta pendiente de aprobacion humana para quedar inmutable.
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <Link v-if="licitacion.status !== 'committed'" :href="route('licitacion.edit', licitacion.id)">
                                <Button label="Editar expediente" icon="pi pi-pencil" />
                            </Link>
                            <form v-if="licitacion.status !== 'committed' && licitacion.status !== 'sent_for_approval'" @submit.prevent="sendForApproval(licitacion.id)">
                                <Button
                                    type="submit"
                                    label="Enviar a aprobacion humana"
                                    icon="pi pi-send"
                                    severity="warning"
                                    :loading="sendForm.processing"
                                    :disabled="!canSendForApproval(licitacion)"
                                />
                            </form>
                        </div>
                        <p v-if="licitacion.status !== 'committed' && licitacion.status !== 'sent_for_approval' && !canSendForApproval(licitacion)" class="mt-3 text-xs text-amber-700">
                            Para enviar esta licitacion, primero completa la validacion en estado ready_for_export (o aplica override legal).
                        </p>

                        <form
                            v-if="licitacion.status === 'sent_for_approval'"
                            class="mt-4 space-y-3 rounded-lg border border-gray-200 p-4"
                            @submit.prevent="approveSubmission(licitacion.id)"
                        >
                            <label class="block text-sm font-medium text-gray-700">Nota de aprobacion humana</label>
                            <textarea
                                v-model="approvalForm.approval_note"
                                rows="3"
                                class="block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                placeholder="Describe quien aprobó y por qué se autoriza el envio al gobierno"
                            />
                            <InputError :message="approvalForm.errors.approval_note" />
                            <div class="flex justify-end">
                                <Button type="submit" label="Aprobar y comprometer" icon="pi pi-check" :loading="approvalForm.processing" />
                            </div>
                        </form>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm" v-if="licitacion.letterhead">
                        <h3 class="text-base font-semibold text-gray-900">Hoja membretada seleccionada</h3>
                        <p class="mt-2 text-sm text-gray-700">{{ licitacion.letterhead.title }}</p>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ licitacion.letterhead.contact_name || 'Sin contacto' }}
                            <span v-if="licitacion.letterhead.contact_position">· {{ licitacion.letterhead.contact_position }}</span>
                        </p>
                        <p class="mt-1 text-sm text-gray-600" v-if="licitacion.letterhead.city">{{ licitacion.letterhead.city }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Checklist inicial</h3>
                        <ul class="mt-4 space-y-2">
                            <li v-for="(item, idx) in licitacion.checklist || []" :key="idx" class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="pi" :class="item.checked ? 'pi-check-circle text-green-600' : 'pi-circle text-gray-400'"></span>
                                <span>{{ item.label }}</span>
                            </li>
                        </ul>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Regulaciones vinculadas</h3>
                        <ul class="mt-4 space-y-2">
                            <li v-for="reg in licitacion.regulations || []" :key="reg.id" class="text-sm text-gray-700">
                                {{ reg.title }} ({{ reg.country_code }} · {{ reg.scope }})
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
