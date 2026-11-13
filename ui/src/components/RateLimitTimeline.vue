<script setup>
import { computed } from 'vue'

/**
 * What the rate-limit tests send, drawn as a row of requests: N allowed, request N+1 blocked,
 * the window passes, then allowed again. Each block is coloured by the case that proves it.
 */
const props = defineProps({
  max: { type: Number, required: true },
  decay: { type: Number, required: true },
  results: { type: Object, default: () => ({}) }, // scenario id → passed|failed
  scenarios: { type: Array, default: () => [] },
  keyedBy: { type: String, default: 'ip' },
})

const byKind = computed(() => Object.fromEntries(props.scenarios.map((s) => [s.meta?.kind, s])))
const state = (kind) => {
  const s = byKind.value[kind]
  if (!s || s.meta?.todo || !s.selected) return 'todo'
  return props.results[s.id] ?? 'planned'
}

// Long allowances are compressed: 1 2 3 … N.
const allowed = computed(() => {
  if (props.max <= 8) return Array.from({ length: props.max }, (_, i) => String(i + 1))
  return ['1', '2', '3', '…', String(props.max - 1), String(props.max)]
})

const window = computed(() => (props.decay % 60 === 0 ? `${props.decay / 60} min` : `${props.decay} s`))
const tone = {
  passed: { allow: 'bg-green-500 text-white', block: 'bg-red-500 text-white' },
  failed: { allow: 'bg-amber-400 text-amber-950 ring-2 ring-amber-600', block: 'bg-amber-400 text-amber-950 ring-2 ring-amber-600' },
  planned: { allow: 'bg-green-100 text-green-800', block: 'bg-red-100 text-red-800' },
  todo: { allow: 'bg-slate-100 text-slate-400', block: 'bg-slate-100 text-slate-400' },
}
const cls = (kind, as) => tone[state(kind)][as]
const label = { passed: '✓ proven', failed: '✗ not enforced', planned: 'not run yet', todo: 'needs a human' }
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end gap-1.5">
      <div class="flex flex-col items-center gap-1">
        <div class="flex gap-1">
          <span v-for="n in allowed" :key="n" class="flex h-9 min-w-[2.25rem] items-center justify-center rounded-md px-1.5 font-mono text-xs font-bold" :class="n === '…' ? 'text-slate-400' : cls('allow', 'allow')">{{ n }}</span>
        </div>
        <span class="text-[11px] font-medium text-slate-500">{{ max }} allowed · {{ label[state('allow')] }}</span>
      </div>

      <div class="flex flex-col items-center gap-1">
        <span class="flex h-9 min-w-[3.5rem] items-center justify-center rounded-md px-2 font-mono text-xs font-bold" :class="cls('block', 'block')">{{ max + 1 }} · 429</span>
        <span class="text-[11px] font-medium text-slate-500">blocked · {{ label[state('block')] }}</span>
      </div>

      <div class="flex flex-col items-center gap-1 px-1">
        <span class="flex h-9 items-center gap-1 rounded-md border border-dashed border-slate-300 px-2.5 text-xs font-semibold text-slate-600">⏱ +{{ window }}</span>
        <span class="text-[11px] font-medium text-slate-500">travel(), no sleep</span>
      </div>

      <div class="flex flex-col items-center gap-1">
        <span class="flex h-9 min-w-[2.25rem] items-center justify-center rounded-md px-2 font-mono text-xs font-bold" :class="cls('reset', 'allow')">ok</span>
        <span class="text-[11px] font-medium text-slate-500">reset · {{ label[state('reset')] }}</span>
      </div>
    </div>

    <dl class="mt-4 grid gap-2 text-xs sm:grid-cols-3">
      <div class="rounded-lg bg-slate-50 p-2.5">
        <dt class="font-semibold text-slate-700">Retry-After header</dt>
        <dd class="mt-0.5 text-slate-500">1–{{ decay }} s on the 429 · <span :class="state('retry_after') === 'failed' ? 'font-semibold text-amber-700' : ''">{{ label[state('retry_after')] }}</span></dd>
      </div>
      <div class="rounded-lg bg-slate-50 p-2.5">
        <dt class="font-semibold text-slate-700">X-RateLimit headers</dt>
        <dd class="mt-0.5 text-slate-500">Limit {{ max }}, Remaining {{ max - 1 }} · <span :class="state('headers') === 'failed' ? 'font-semibold text-amber-700' : ''">{{ label[state('headers')] }}</span></dd>
      </div>
      <div class="rounded-lg bg-slate-50 p-2.5">
        <dt class="font-semibold text-slate-700">Per {{ keyedBy === 'user' ? 'user' : 'client IP' }}</dt>
        <dd class="mt-0.5 text-slate-500">A second {{ keyedBy === 'user' ? 'user' : 'IP' }} is not blocked · <span :class="state('isolation') === 'failed' ? 'font-semibold text-amber-700' : ''">{{ label[state('isolation')] }}</span></dd>
      </div>
    </dl>
  </div>
</template>
