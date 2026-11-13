<script setup>
defineProps({ tabs: Array, modelValue: String })
defineEmits(['update:modelValue'])
</script>

<template>
  <nav class="flex gap-6 border-b border-slate-200" role="tablist">
    <button
      v-for="tab in tabs" :key="tab.id" role="tab" :aria-selected="modelValue === tab.id"
      class="-mb-px border-b-2 pb-2.5 text-sm transition"
      :class="modelValue === tab.id ? 'border-brand-600 font-semibold text-brand-600' : 'border-transparent font-medium text-slate-500 hover:text-slate-800'"
      @click="$emit('update:modelValue', tab.id)"
    >
      {{ tab.label }}<span v-if="tab.count" class="ml-1.5 rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-600">{{ tab.count }}</span>
    </button>
  </nav>
</template>
