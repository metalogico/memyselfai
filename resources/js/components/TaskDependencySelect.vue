<script setup>
import { computed, onUnmounted, reactive, ref, useId, watch } from 'vue';
import AppSelect from './AppSelect.vue';
import TaskDependencyLink from './TaskDependencyLink.vue';
import { createDependencyLoader, dependencyOptions } from '../taskDependencies';

const props = defineProps({
    modelValue: [Number, String],
    projectId: [Number, String],
    taskId: Number,
    selectedTask: Object,
    fetchTasks: { type: Function, required: true },
    disabled: Boolean,
});
const emit = defineEmits(['update:modelValue', 'select', 'open']);
const id = useId();
const search = ref('');
const state = reactive({ tasks: [], loading: false, error: '' });
const loader = createDependencyLoader((projectId) => props.fetchTasks(projectId), state);
const options = computed(() => dependencyOptions(state.tasks, props.projectId, props.taskId, search.value));
const selected = computed(() => state.tasks.find((task) => task.id === Number(props.modelValue))
    || (props.selectedTask?.id === Number(props.modelValue) ? props.selectedTask : null));
const selectOptions = computed(() => {
    const choices = options.value.map((task) => ({ value: task.id, label: task.name }));
    if (!search.value && selected.value && !choices.some((option) => option.value === selected.value.id)) {
        choices.push({ value: selected.value.id, label: selected.value.name });
    }
    return choices;
});

watch([() => props.projectId, () => props.taskId], () => {
    search.value = '';
    loader.load(props.projectId);
}, { immediate: true });
onUnmounted(() => loader.cancel());

function choose(value) {
    const task = state.tasks.find((task) => task.id === Number(value))
        || (selected.value?.id === Number(value) ? selected.value : null);
    emit('update:modelValue', task?.id ?? null);
    emit('select', task);
}
</script>

<template>
    <div class="min-w-0 max-w-full">
        <div class="relative mb-1.5 flex h-4 items-center justify-between gap-2">
            <span :id="`${id}-label`" class="text-xs font-medium text-stone-600">
                Depends on <span class="font-normal text-stone-400">· optional</span>
            </span>
            <TaskDependencyLink v-if="selected && taskId" class="absolute -top-1 right-0 h-6! w-6!" :dependency="selected" :disabled="disabled" @open="$emit('open', $event)" />
        </div>
        <AppSelect
            :model-value="modelValue || null"
            :options="selectOptions"
            :disabled="disabled || !projectId"
            :loading="state.loading"
            :filter-method="(query) => search = query"
            aria-label="Depends on"
            placeholder="No dependency"
            no-data-text="No matching tasks in this project."
            filterable
            clearable
            default-first-option
            @visible-change="search = ''"
            @update:model-value="choose"
        />
        <p v-if="state.error" role="alert" class="mt-1.5 text-xs leading-5 text-red-700">
            {{ state.error }}
            <button type="button" class="ml-1 underline" :disabled="disabled" @click="loader.load(projectId)">Try again</button>
        </p>
    </div>
</template>
