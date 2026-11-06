<script setup>
import { useId } from 'vue';
import Icon from './Icon.vue';

defineProps({ dependency: { type: Object, required: true }, disabled: Boolean });
defineEmits(['open']);
const tooltipId = useId();
</script>

<template>
    <button
        type="button"
        class="group/dependency relative inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-stone-500 hover:bg-stone-100 hover:text-emerald-800"
        :aria-label="`Depends on ${dependency.name}. Open task.`"
        :aria-describedby="tooltipId"
        :disabled="disabled"
        draggable="false"
        @dragstart.stop.prevent
        @click.stop="$emit('open', dependency.id)"
    >
        <Icon name="signpost-big" :size="15" />
        <span
            :id="tooltipId"
            role="tooltip"
            class="pointer-events-none absolute top-full right-0 z-30 mt-1 hidden w-max max-w-64 rounded-md bg-stone-800 px-2.5 py-1.5 text-left text-xs leading-5 font-normal wrap-anywhere whitespace-normal text-white shadow-lg group-hover/dependency:block group-focus-visible/dependency:block"
        >Depends on: {{ dependency.name }}</span>
    </button>
</template>
