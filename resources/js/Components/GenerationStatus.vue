<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, required: true },
});

const styles = {
    queued: { label: 'Queued', classes: 'bg-gray-100 text-gray-700' },
    processing: { label: 'Processing', classes: 'bg-blue-100 text-blue-800 animate-pulse' },
    succeeded: { label: 'Succeeded', classes: 'bg-green-100 text-green-800' },
    failed: { label: 'Failed', classes: 'bg-red-100 text-red-800' },
};

const style = computed(() => styles[props.status] ?? styles.queued);
</script>

<template>
    <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="style.classes">
        {{ style.label }}
    </span>
</template>
