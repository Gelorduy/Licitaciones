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
    tipos: {
        type: Array,
        default: () => [],
    },
});

const makeApoderado = (item = {}) => ({
    nombre_completo: item.nombre_completo ?? '',
    ine: item.ine ?? '',
    poder_documento: item.poder_documento ?? '',
    facultades_otorgadas: item.facultades_otorgadas ?? '',
});

const makeParticipacion = (item = {}) => ({
    socio: item.socio ?? '',
    porcentaje: item.porcentaje ?? '',
});

const buildApoderados = (items = []) => {
    if (!Array.isArray(items) || items.length === 0) {
        return [makeApoderado()];
    }

    return items.map((item) => makeApoderado(item));
};

const buildParticipaciones = (items = []) => {
    if (!Array.isArray(items) || items.length === 0) {
        return [makeParticipacion()];
    }

    return items.map((item) => makeParticipacion(item));
};

const buildStringList = (items = []) => {
    if (!Array.isArray(items) || items.length === 0) {
        return [''];
    }

    return items.map((item) => (typeof item === 'string' ? item : ''));
};

const form = useForm({
    tipo: props.tipos[0] ?? 'constitutiva',
    fecha_registro: '',
    rpc_fecha_inscripcion: '',
    fecha_inscripcion: '',
    documento: null,
    rpc_folio: '',
    rpc_lugar: '',
    notaria_numero: '',
    notaria_lugar: '',
    notario_nombre: '',
    escritura_numero: '',
    libro_numero: '',
    acto: '',
    apoderados: buildApoderados(),
    participacion_accionaria: buildParticipaciones(),
    consejo_administracion: buildStringList(),
    direccion_empresa: buildStringList(),
});

const addApoderado = () => {
    form.apoderados.push(makeApoderado());
};

const removeApoderado = (index) => {
    if (form.apoderados.length === 1) {
        form.apoderados[0] = makeApoderado();
        return;
    }

    form.apoderados.splice(index, 1);
};

const addParticipacion = () => {
    form.participacion_accionaria.push(makeParticipacion());
};

const removeParticipacion = (index) => {
    if (form.participacion_accionaria.length === 1) {
        form.participacion_accionaria[0] = makeParticipacion();
        return;
    }

    form.participacion_accionaria.splice(index, 1);
};

const addConsejero = () => {
    form.consejo_administracion.push('');
};

const removeConsejero = (index) => {
    if (form.consejo_administracion.length === 1) {
        form.consejo_administracion[0] = '';
        return;
    }

    form.consejo_administracion.splice(index, 1);
};

const addDireccion = () => {
    form.direccion_empresa.push('');
};

const removeDireccion = (index) => {
    if (form.direccion_empresa.length === 1) {
        form.direccion_empresa[0] = '';
        return;
    }

    form.direccion_empresa.splice(index, 1);
};

const submit = () => {
    form.post(route('acta.store', props.company.id));
};
</script>

<template>
    <div>
        <Head title="Nueva Acta" />

        <AuthenticatedLayout>
            <template #header>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Nueva Acta · {{ company.nombre }}
                </h2>
            </template>

            <div class="py-10">
                <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <form class="space-y-6" @submit.prevent="submit">
                            <div>
                                <InputLabel value="PDF del acta" />
                                <input
                                    type="file"
                                    accept="application/pdf"
                                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                    @change="form.documento = $event.target.files[0]"
                                />
                                <InputError class="mt-2" :message="form.errors.documento" />
                                <p class="mt-2 text-xs text-gray-500">
                                    El documento se procesa con extracción de texto y OCR automático si viene escaneado como imagen. Tamaño máximo: 20 MB.
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
                                    <textarea
                                        v-model="form.acto"
                                        rows="3"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                    />
                                    <InputError class="mt-2" :message="form.errors.acto" />
                                </div>
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel value="Escritura Número" />
                                    <TextInput v-model="form.escritura_numero" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.escritura_numero" />
                                </div>

                                <div>
                                    <InputLabel value="Libro Número" />
                                    <TextInput v-model="form.libro_numero" class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="form.errors.libro_numero" />
                                </div>
                            </div>

                            <div class="rounded-lg border border-blue-100 bg-blue-50 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-blue-900">Apoderados legales y facultades</h3>
                                    <Button type="button" label="Agregar apoderado" icon="pi pi-plus" size="small" severity="secondary" @click="addApoderado" />
                                </div>
                                <div class="mt-3 space-y-4">
                                    <div v-for="(apoderado, idx) in form.apoderados" :key="`apoderado-${idx}`" class="rounded-lg border border-blue-200 bg-white p-4">
                                        <div class="mb-3 flex items-center justify-between gap-3">
                                            <h4 class="text-sm font-medium text-blue-900">Apoderado {{ idx + 1 }}</h4>
                                            <Button type="button" label="Quitar" icon="pi pi-trash" size="small" severity="danger" text @click="removeApoderado(idx)" />
                                        </div>
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div>
                                                <InputLabel value="Nombre completo" />
                                                <TextInput v-model="form.apoderados[idx].nombre_completo" class="mt-1 block w-full" />
                                                <InputError class="mt-2" :message="form.errors[`apoderados.${idx}.nombre_completo`]" />
                                            </div>
                                            <div>
                                                <InputLabel value="INE" />
                                                <TextInput v-model="form.apoderados[idx].ine" class="mt-1 block w-full" />
                                                <InputError class="mt-2" :message="form.errors[`apoderados.${idx}.ine`]" />
                                            </div>
                                        </div>
                                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                                            <div>
                                                <InputLabel value="Poder / documento" />
                                                <TextInput v-model="form.apoderados[idx].poder_documento" class="mt-1 block w-full" />
                                                <InputError class="mt-2" :message="form.errors[`apoderados.${idx}.poder_documento`]" />
                                            </div>
                                            <div>
                                                <InputLabel value="Facultades otorgadas" />
                                                <textarea
                                                    v-model="form.apoderados[idx].facultades_otorgadas"
                                                    rows="3"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                                />
                                                <InputError class="mt-2" :message="form.errors[`apoderados.${idx}.facultades_otorgadas`]" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-emerald-900">Participación accionaria</h3>
                                    <Button type="button" label="Agregar participación" icon="pi pi-plus" size="small" severity="secondary" @click="addParticipacion" />
                                </div>
                                <div class="mt-3 space-y-4">
                                    <div v-for="(item, idx) in form.participacion_accionaria" :key="`participacion-${idx}`" class="rounded-lg border border-emerald-200 bg-white p-4">
                                        <div class="mb-3 flex items-center justify-between gap-3">
                                            <h4 class="text-sm font-medium text-emerald-900">Socio {{ idx + 1 }}</h4>
                                            <Button type="button" label="Quitar" icon="pi pi-trash" size="small" severity="danger" text @click="removeParticipacion(idx)" />
                                        </div>
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div>
                                                <InputLabel value="Socio" />
                                                <TextInput v-model="form.participacion_accionaria[idx].socio" class="mt-1 block w-full" />
                                                <InputError class="mt-2" :message="form.errors[`participacion_accionaria.${idx}.socio`]" />
                                            </div>
                                            <div>
                                                <InputLabel value="Porcentaje" />
                                                <TextInput v-model="form.participacion_accionaria[idx].porcentaje" class="mt-1 block w-full" />
                                                <InputError class="mt-2" :message="form.errors[`participacion_accionaria.${idx}.porcentaje`]" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-lg border border-violet-100 bg-violet-50 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-violet-900">Consejo de administración</h3>
                                    <Button type="button" label="Agregar integrante" icon="pi pi-plus" size="small" severity="secondary" @click="addConsejero" />
                                </div>
                                <div class="mt-3 space-y-3">
                                    <div v-for="(integrante, idx) in form.consejo_administracion" :key="`consejo-${idx}`" class="flex items-start gap-3">
                                        <div class="flex-1">
                                            <InputLabel :value="`Integrante ${idx + 1}`" />
                                            <TextInput v-model="form.consejo_administracion[idx]" class="mt-1 block w-full" />
                                            <InputError class="mt-2" :message="form.errors[`consejo_administracion.${idx}`]" />
                                        </div>
                                        <Button type="button" label="Quitar" icon="pi pi-trash" size="small" severity="danger" text class="mt-6" @click="removeConsejero(idx)" />
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-lg border border-amber-100 bg-amber-50 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-amber-900">Dirección de la empresa</h3>
                                    <Button type="button" label="Agregar línea" icon="pi pi-plus" size="small" severity="secondary" @click="addDireccion" />
                                </div>
                                <div class="mt-3 space-y-3">
                                    <div v-for="(linea, idx) in form.direccion_empresa" :key="`direccion-${idx}`" class="flex items-start gap-3">
                                        <div class="flex-1">
                                            <InputLabel :value="`Línea ${idx + 1}`" />
                                            <textarea
                                                v-model="form.direccion_empresa[idx]"
                                                rows="2"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                            />
                                            <InputError class="mt-2" :message="form.errors[`direccion_empresa.${idx}`]" />
                                        </div>
                                        <Button type="button" label="Quitar" icon="pi pi-trash" size="small" severity="danger" text class="mt-6" @click="removeDireccion(idx)" />
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end gap-3">
                                <Link :href="route('empresa.show', company.id)">
                                    <Button label="Cancelar" severity="secondary" text />
                                </Link>
                                <Button type="submit" label="Guardar Acta" icon="pi pi-save" :loading="form.processing" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>
</template>
