<script setup>
import Icon from './Icon.vue'
defineProps({ icon: { type: String, default: 'sparkles' }, title: String, text: String })
</script>

<template>
  <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
    <span class="mb-3 rounded-full bg-brand-50 p-3 text-brand-600"><Icon :name="icon" :size="22" /></span>
    <p class="text-sm font-semibold text-slate-900">{{ title }}</p>
    <p v-if="text" class="mt-1 max-w-md text-sm text-slate-500">{{ text }}</p>
    <div v-if="$slots.default" class="mt-4"><slot /></div>
  </div>
</template>
