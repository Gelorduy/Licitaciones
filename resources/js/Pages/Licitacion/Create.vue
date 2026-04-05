<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import { computed } from 'vue';

const props = defineProps({
    companies: { type: Array, default: () => [] },
    regulations: { type: Array, default: () => [] },
    letterheads: { type: Array, default: () => [] },
    processTypes: { type: Array, default: () => [] },
});

const form = useForm({
    title: '',
    company_id: props.companies[0]?.id ?? null,
    company_letterhead_id: null,
    process_type: props.processTypes[0] ?? 'publica',
    legal_signer_name: '',
    regulation_ids: [],
    bases_document: null,
});

const availableLetterheads = computed(() => {
    return props.letterheads.filter((letterhead) => letterhead.company_id === form.company_id);
});

const submit = () => {
    form.post(route('licitacion.store'));
};
</script>

<template>
    <div>
        <Head title="Nueva Licitación" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Nuevo Expediente de Licitación</h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div>
                                <InputLabel value="Título del expediente" />
                                <TextInput v-model="form.title" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.title" />
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Empresa" />
                                    <select v-model="form.company_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" @change="form.company_letterhead_id = null">
                                        <option v-for="company in companies" :key="company.id" :value="company.id">
                                            {{ company.nombre }} ({{ company.rfc }})
                                        </option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.company_id" />
                                </div>

                                <div>
                                    <InputLabel value="Tipología" />
                                    <select v-model="form.process_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                        <option v-for="type in processTypes" :key="type" :value="type">{{ type }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.process_type" />
                                </div>
                            </div>

                            <div>
                                <InputLabel value="Hoja membretada" />
                                <select v-model="form.company_letterhead_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                    <option :value="null">Sin hoja membretada</option>
                                    <option v-for="letterhead in availableLetterheads" :key="letterhead.id" :value="letterhead.id">
                                        {{ letterhead.title }}{{ letterhead.is_default ? ' (predeterminada)' : '' }}
                                    </option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.company_letterhead_id" />
                            </div>

                            <div>
                                <InputLabel value="Apoderado legal firmante" />
                                <TextInput v-model="form.legal_signer_name" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.legal_signer_name" />
                            </div>

                            <div>
                                <InputLabel value="Regulaciones aplicables" />
                                <div class="mt-2 grid gap-2 rounded-lg border border-gray-200 p-3 md:grid-cols-2">
                                    <label v-for="reg in regulations" :key="reg.id" class="flex items-start gap-2 text-sm text-gray-700">
                                        <input v-model="form.regulation_ids" type="checkbox" :value="reg.id" class="mt-1 rounded border-gray-300" />
                                        <span>{{ reg.title }} <span class="text-gray-500">({{ reg.country_code }} · {{ reg.scope }})</span></span>
                                    </label>
                                </div>
                                <InputError class="mt-2" :message="form.errors.regulation_ids" />
                            </div>

                            <div>
                                <InputLabel value="Bases de licitación (PDF)" />
                                <input
                                    type="file"
                                    accept="application/pdf"
                                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                    @change="form.bases_document = $event.target.files[0]"
                                />
                                <InputError class="mt-2" :message="form.errors.bases_document" />
                            </div>

                            <div class="flex justify-end gap-3">
                                <Link :href="route('licitacion.index')">
                                    <Button label="Cancelar" severity="secondary" text />
                                </Link>
                                <Button type="submit" label="Crear expediente" icon="pi pi-save" :loading="form.processing" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
