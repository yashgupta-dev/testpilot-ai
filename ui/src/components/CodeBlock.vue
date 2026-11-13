<script setup>
import { computed } from 'vue'

const props = defineProps({
  code: { type: String, default: '' },
  startLine: { type: Number, default: 1 },
  markers: { type: Object, default: () => ({}) }, // line => { tone, text }
  numbered: { type: Boolean, default: true },
  maxHeight: { type: String, default: '32rem' },
  wrap: { type: Boolean, default: false },
})

const lines = computed(() => props.code.replace(/\n$/, '').split('\n').map((text, i) => ({ n: props.startLine + i, text })))
const markerTone = { green: 'bg-green-900/60 text-green-300', red: 'bg-red-900/60 text-red-300', amber: 'bg-amber-900/60 text-amber-300', gray: 'bg-slate-700 text-slate-300' }
</script>

<template>
  <div class="overflow-auto rounded-lg bg-slate-900 py-3 font-mono text-[12.5px] leading-6" :style="{ maxHeight }">
    <div v-for="line in lines" :key="line.n" class="flex items-start gap-3 pr-3" :class="markers[line.n]?.tone === 'red' ? 'bg-red-950/50' : ''">
      <span v-if="numbered" class="w-10 shrink-0 select-none pr-1 text-right text-slate-500">{{ line.n }}</span>
      <span v-else class="w-3 shrink-0" />
      <pre class="min-w-0 flex-1 text-slate-200" :class="wrap ? 'whitespace-pre-wrap break-words' : 'overflow-hidden text-ellipsis whitespace-pre'">{{ line.text || ' ' }}</pre>
      <span v-if="markers[line.n]" class="shrink-0 rounded-full px-2 text-[11px] font-semibold" :class="markerTone[markers[line.n].tone]">{{ markers[line.n].text }}</span>
    </div>
  </div>
</template>
