<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    sizes: { type: Array, required: true },
    qualities: { type: Array, required: true },
    maxReferences: { type: Number, required: true },
    markupPercent: { type: Number, required: true },
});

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

const filledReferences = computed(() => form.reference_images.filter((url) => url.trim() !== ''));

function addReference() {
    if (form.reference_images.length < props.maxReferences) {
        form.reference_images.push('');
    }
}

function removeReference(index) {
    form.reference_images.splice(index, 1);
}

function submit() {
    form.transform((data) => ({ ...data, reference_images: filledReferences.value })).post(route('generations.store'));
}

// The server prices the estimate (single source of truth), so ask it whenever an input changes.
const estimate = ref(null);
const estimateFailed = ref(false);
let debounce = null;
let latestRequest = 0;

async function loadEstimate() {
    const requestId = ++latestRequest;

    try {
        const { data } = await window.axios.get(route('generations.estimate'), {
            params: {
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_count: filledReferences.value.length,
            },
        });

        // Ignore a slow response that was overtaken by a newer request.
        if (requestId === latestRequest) {
            estimate.value = data;
            estimateFailed.value = false;
        }
    } catch {
        if (requestId === latestRequest) {
            estimateFailed.value = true;
        }
    }
}

watch(
    () => [form.prompt, form.size, form.quality, filledReferences.value.length],
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(loadEstimate, 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(debounce));
</script>

<template>
    <Head title="New image" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">New image</h2>
        </template>

        <div class="py-8">
            <form
                class="mx-auto max-w-3xl space-y-6 rounded-lg bg-white p-6 shadow-sm sm:px-8"
                @submit.prevent="submit"
            >
                <div>
                    <InputLabel for="prompt" value="Prompt" />
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="4"
                        maxlength="4000"
                        required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="Describe the image you want"
                    />
                    <p class="mt-1 text-right text-xs text-gray-500">{{ form.prompt.length }} / 4000</p>
                    <InputError class="mt-1" :message="form.errors.prompt" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <InputLabel for="size" value="Size" />
                        <select
                            id="size"
                            v-model="form.size"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option v-for="size in sizes" :key="size" :value="size">{{ size }}</option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.size" />
                    </div>
                    <div>
                        <InputLabel for="quality" value="Quality" />
                        <select
                            id="quality"
                            v-model="form.quality"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option v-for="quality in qualities" :key="quality" :value="quality">
                                {{ quality }}
                            </option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.quality" />
                    </div>
                </div>

                <div>
                    <InputLabel value="Reference images (optional)" />
                    <div v-for="(url, index) in form.reference_images" :key="index" class="mt-2 flex gap-2">
                        <input
                            v-model="form.reference_images[index]"
                            type="url"
                            placeholder="https://example.com/photo.png"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <button
                            type="button"
                            class="rounded-md px-3 text-sm text-gray-600 hover:bg-gray-100"
                            @click="removeReference(index)"
                        >
                            Remove
                        </button>
                    </div>
                    <InputError
                        v-for="(message, key) in form.errors"
                        :key="key"
                        v-show="String(key).startsWith('reference_images')"
                        class="mt-1"
                        :message="message"
                    />
                    <button
                        v-if="form.reference_images.length < maxReferences"
                        type="button"
                        class="mt-2 text-sm font-medium text-indigo-600 hover:text-indigo-800"
                        @click="addReference"
                    >
                        + Add reference image URL
                    </button>
                    <p class="mt-1 text-xs text-gray-500">Up to {{ maxReferences }}. Each one adds to the cost.</p>
                </div>

                <div class="rounded-md bg-gray-50 p-4">
                    <p class="text-sm text-gray-600">Estimated cost</p>
                    <p v-if="estimate" class="text-2xl font-semibold text-gray-900">
                        {{ estimate.min }} – {{ estimate.max }}
                    </p>
                    <p v-else-if="estimateFailed" class="text-sm text-red-700">Estimate unavailable right now.</p>
                    <p v-else class="text-sm text-gray-500">Calculating…</p>
                    <p class="mt-2 text-xs text-gray-500">
                        This is an estimate. Part of the cost is only known once the image finishes, and the
                        final price is shown in your history. It includes our {{ markupPercent }}% markup. If a
                        generation fails, you pay nothing.
                    </p>
                </div>

                <div class="flex justify-end">
                    <PrimaryButton :disabled="form.processing || form.prompt.trim() === ''">
                        Generate
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
