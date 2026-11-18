<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GenerationStatus from '@/Components/GenerationStatus.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, watch } from 'vue';

const props = defineProps({
    generations: { type: Array, required: true },
});

// Poll only while something is unfinished, and not while the tab is hidden.
const POLL_MS = 3000;
const hasActive = computed(() => props.generations.some((g) => g.is_active));
let timer = null;

watch(
    hasActive,
    (active) => {
        clearInterval(timer);
        timer = active
            ? setInterval(() => {
                  if (!document.hidden) {
                      router.reload({ only: ['generations'], preserveScroll: true });
                  }
              }, POLL_MS)
            : null;
    },
    { immediate: true },
);

onBeforeUnmount(() => clearInterval(timer));

// Pixelforge image links expire; if one stops loading, say so instead of showing a broken icon.
const brokenImages = reactive(new Set());
</script>

<template>
    <Head title="History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">History</h2>
                <Link
                    :href="route('generations.create')"
                    class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                >
                    New image
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                <p
                    v-if="generations.length === 0"
                    class="rounded-lg bg-white p-8 text-center text-gray-500 shadow-sm"
                >
                    No images yet. Write a prompt to create your first one.
                </p>

                <article
                    v-for="g in generations"
                    :key="g.id"
                    class="flex flex-col gap-4 rounded-lg bg-white p-4 shadow-sm sm:flex-row"
                >
                    <div
                        class="flex h-40 w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-gray-100 text-sm text-gray-500 sm:w-40"
                    >
                        <img
                            v-if="g.image_url && !brokenImages.has(g.id)"
                            :src="g.image_url"
                            :alt="g.prompt"
                            class="h-full w-full object-cover"
                            @error="brokenImages.add(g.id)"
                        />
                        <span v-else-if="g.image_expired || brokenImages.has(g.id)" class="px-2 text-center">
                            Image link expired
                        </span>
                        <span v-else-if="g.status === 'failed'">No image</span>
                        <span v-else class="animate-pulse">Generating…</span>
                    </div>

                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex items-start justify-between gap-3">
                            <p class="break-words font-medium text-gray-900">{{ g.prompt }}</p>
                            <GenerationStatus :status="g.status" />
                        </div>

                        <p class="text-xs text-gray-500">
                            {{ g.size }} · {{ g.quality }} quality ·
                            {{ new Date(g.created_at).toLocaleString() }}
                        </p>

                        <p v-if="g.status === 'failed'" class="text-sm text-red-700">
                            {{ g.error_message ?? 'The image could not be generated.' }}
                            <span class="font-medium">You were not charged.</span>
                        </p>

                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-gray-500">Estimated</dt>
                                <dd class="text-gray-900">{{ g.estimate.min }} – {{ g.estimate.max }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Final cost</dt>
                                <dd class="font-semibold text-gray-900">
                                    <template v-if="g.charged !== null">{{ g.charged }}</template>
                                    <span v-else class="font-normal text-gray-500">Known when finished</span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </article>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
