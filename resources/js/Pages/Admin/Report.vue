<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

const props = defineProps({
    filters: { type: Object, required: true },
    totals: { type: Object, required: true },
    days: { type: Array, required: true },
    users: { type: Array, required: true },
    dayUsers: { type: Array, required: true },
});

const range = reactive({ from: props.filters.from ?? '', to: props.filters.to ?? '' });

function apply() {
    router.get(route('admin.report'), { from: range.from || undefined, to: range.to || undefined }, { preserveState: true });
}

function clear() {
    range.from = '';
    range.to = '';
    apply();
}

// Users' rows for one day, shown when that day is expanded.
const open = reactive(new Set());
const usersByDay = computed(() => {
    const grouped = {};
    for (const row of props.dayUsers) (grouped[row.date] ??= []).push(row);

    return grouped;
});

function toggle(date) {
    open.has(date) ? open.delete(date) : open.add(date);
}

const summary = computed(() => [
    { label: 'Credits charged', value: props.totals.charged, hint: 'What users paid us' },
    { label: 'Provider cost', value: props.totals.provider_cost, hint: 'What Pixelforge charged us' },
    { label: 'Profit', value: props.totals.profit, hint: `${props.totals.margin} margin` },
    { label: 'Generations', value: String(props.totals.generations), hint: 'Succeeded and charged' },
]);
</script>

<template>
    <Head title="Spend report" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Spend report</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <form class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm" @submit.prevent="apply">
                    <label class="text-sm text-gray-700">
                        From (UTC)
                        <input v-model="range.from" type="date" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm" />
                    </label>
                    <label class="text-sm text-gray-700">
                        To (UTC)
                        <input v-model="range.to" type="date" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm" />
                    </label>
                    <button class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                        Apply
                    </button>
                    <button type="button" class="px-2 py-2 text-sm text-gray-600 hover:text-gray-900" @click="clear">Clear</button>
                    <p class="basis-full text-xs text-gray-500">
                        A generation counts on the UTC day it completed, the same rule Pixelforge uses for its usage report.
                    </p>
                </form>

                <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="item in summary" :key="item.label" class="rounded-lg bg-white p-4 shadow-sm">
                        <dt class="text-sm text-gray-500">{{ item.label }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ item.value }}</dd>
                        <p class="mt-1 text-xs text-gray-500">{{ item.hint }}</p>
                    </div>
                </dl>

                <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <h3 class="px-4 pt-4 text-sm font-semibold text-gray-800">By day</h3>
                    <table class="mt-2 w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="px-4 py-2 font-medium">Date (UTC)</th>
                                <th class="px-4 py-2 text-right font-medium">Generations</th>
                                <th class="px-4 py-2 text-right font-medium">Charged</th>
                                <th class="px-4 py-2 text-right font-medium">Provider cost</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                                <th class="px-4 py-2 text-right font-medium">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="days.length === 0">
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">No charged generations in this range.</td>
                            </tr>
                            <template v-for="day in days" :key="day.date">
                                <tr class="cursor-pointer border-b hover:bg-gray-50" @click="toggle(day.date)">
                                    <td class="px-4 py-2 font-medium text-gray-900">
                                        <span class="mr-1 text-gray-400">{{ open.has(day.date) ? '▾' : '▸' }}</span>{{ day.date }}
                                    </td>
                                    <td class="px-4 py-2 text-right">{{ day.generations }}</td>
                                    <td class="px-4 py-2 text-right">{{ day.charged }}</td>
                                    <td class="px-4 py-2 text-right">{{ day.provider_cost }}</td>
                                    <td class="px-4 py-2 text-right">{{ day.profit }}</td>
                                    <td class="px-4 py-2 text-right text-gray-500">{{ day.margin }}</td>
                                </tr>
                                <tr v-for="row in open.has(day.date) ? usersByDay[day.date] : []" :key="`${day.date}-${row.user_id}`" class="border-b bg-gray-50 text-gray-700">
                                    <td class="py-2 pl-10 pr-4">{{ row.name }} <span class="text-xs text-gray-500">{{ row.email }}</span></td>
                                    <td class="px-4 py-2 text-right">{{ row.generations }}</td>
                                    <td class="px-4 py-2 text-right">{{ row.charged }}</td>
                                    <td class="px-4 py-2 text-right">{{ row.provider_cost }}</td>
                                    <td class="px-4 py-2 text-right">{{ row.profit }}</td>
                                    <td class="px-4 py-2 text-right text-gray-500">{{ row.margin }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </section>

                <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <h3 class="px-4 pt-4 text-sm font-semibold text-gray-800">By user</h3>
                    <table class="mt-2 w-full text-left text-sm">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="px-4 py-2 font-medium">User</th>
                                <th class="px-4 py-2 text-right font-medium">Generations</th>
                                <th class="px-4 py-2 text-right font-medium">Charged</th>
                                <th class="px-4 py-2 text-right font-medium">Provider cost</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                                <th class="px-4 py-2 text-right font-medium">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="users.length === 0">
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">No charged generations in this range.</td>
                            </tr>
                            <tr v-for="user in users" :key="user.user_id" class="border-b">
                                <td class="px-4 py-2 text-gray-900">{{ user.name }} <span class="text-xs text-gray-500">{{ user.email }}</span></td>
                                <td class="px-4 py-2 text-right">{{ user.generations }}</td>
                                <td class="px-4 py-2 text-right">{{ user.charged }}</td>
                                <td class="px-4 py-2 text-right">{{ user.provider_cost }}</td>
                                <td class="px-4 py-2 text-right">{{ user.profit }}</td>
                                <td class="px-4 py-2 text-right text-gray-500">{{ user.margin }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
