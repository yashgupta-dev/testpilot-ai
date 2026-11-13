<script setup>
import { riskColor } from '../lib/status.js'
defineProps({ score: Number, level: String })
</script>

<template>
  <div class="flex items-center gap-2" :title="`Risk ${score} (${level})`">
    <span class="w-6 text-right text-sm font-bold" :style="{ color: riskColor(level) }">{{ score }}</span>
    <span class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100">
      <span class="block h-full rounded-full" :style="{ width: `${score}%`, background: riskColor(level) }" />
    </span>
    <span class="text-xs font-medium capitalize text-slate-500">{{ level }}</span>
  </div>
</template>
