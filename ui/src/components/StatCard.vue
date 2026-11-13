<script setup>
defineProps({ label: String, value: [String, Number], hint: String, tone: { type: String, default: 'green' } })
const tones = { green: 'text-green-600', red: 'text-red-600', amber: 'text-amber-600', gray: 'text-slate-500' }
</script>

<template>
  <div class="card card-pad min-w-0">
    <p class="text-[13px] font-medium text-slate-500">{{ label }}</p>
    <p class="mt-1.5 truncate text-[28px] font-bold leading-tight text-slate-900">{{ value ?? '—' }}</p>
    <p v-if="hint" class="mt-1 truncate text-xs font-medium" :class="tones[tone]">{{ hint }}</p>
  </div>
</template>
