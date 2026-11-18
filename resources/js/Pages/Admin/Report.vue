<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    filters: { type: Object, required: true },
    totals: { type: Object, required: true },
    days: { type: Array, required: true },
    users: { type: Array, required: true },
    dayUsers: { type: Array, required: true },
});
</script>

<template>
    <Head title="Spend report" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Spend report</h2>
        </template>
    </AuthenticatedLayout>
</template>
