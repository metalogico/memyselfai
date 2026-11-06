<script setup>
import { computed, onMounted, ref, shallowRef } from 'vue';
import { ElOption, ElSelect } from 'element-plus';
import 'element-plus/es/components/select/style/css';

defineOptions({ inheritAttrs: false });
const props = defineProps({
    modelValue: [Number, String],
    options: { type: Array, required: true },
    compact: Boolean,
    active: Boolean,
});
defineEmits(['update:modelValue', 'change']);
const select = ref(null);
const popupContainer = shallowRef();
const selectedValue = computed(() => props.options.find((option) =>
    String(option.value) === String(props.modelValue),
)?.value ?? props.modelValue);

onMounted(() => {
    popupContainer.value = select.value.$el.closest('dialog') || document.body;
});
</script>

<template>
    <ElSelect
        ref="select"
        v-bind="$attrs"
        :model-value="selectedValue"
        class="app-select"
        :class="{ 'app-select-compact': compact, 'app-select-active': active }"
        :append-to="popupContainer"
        :empty-values="[null, undefined]"
        :value-on-clear="null"
        :persistent="false"
        :show-arrow="false"
        :offset="6"
        popper-class="app-select-dropdown"
        @update:model-value="$emit('update:modelValue', $event)"
        @change="$emit('change', $event)"
    >
        <ElOption
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            :label="option.label"
            :disabled="option.disabled"
        >
            <slot name="option" :option="option">{{ option.label }}</slot>
        </ElOption>
    </ElSelect>
</template>
