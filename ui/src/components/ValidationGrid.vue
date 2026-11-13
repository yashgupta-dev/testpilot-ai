<script setup>
import { computed, ref } from 'vue'
import Badge from './Badge.vue'
import CodeBlock from './CodeBlock.vue'
import { scenarioBlock } from '../lib/format.js'

/**
 * One row per generated case, grouped by field: Field × Rule × Input sent × Expected × Result.
 * Every row traces back to a line in the FormRequest's rules(), so a reviewer can see which
 * declared rule each Pest test proves — and which rules still need a human.
 */
const props = defineProps({
  scenarios: { type: Array, required: true },
  fields: { type: Array, default: () => [] },
  results: { type: Object, default: () => ({}) },
  selection: { type: Object, default: null }, // Set of ids while the plan is editable
  code: { type: String, default: '' },
  showResults: { type: Boolean, default: false },
  errorMode: { type: String, default: 'laravel' },
  errorStatus: { type: Number, default: 422 },
})
const emit = defineEmits(['toggle'])
const open = ref(null)

const fieldInfo = computed(() => Object.fromEntries(props.fields.map((f) => [f.field, f])))

const groups = computed(() => {
  const out = []
  const byField = new Map()
  for (const s of props.scenarios) {
    const key = s.meta?.field ?? '__payload'
    if (!byField.has(key)) {
      const info = fieldInfo.value[key] ?? fieldInfo.value[key.replace(/\.\d+(?=\.|$)/g, '.*')] ?? null
      const group = { field: info?.field ?? key, info, cases: [] }
      byField.set(key, group)
      out.push(group)
    }
    byField.get(key).cases.push(s)
  }
  return out
})

const isSelected = (s) => (props.selection ? props.selection.has(s.id) : s.selected)
const isTodo = (s) => !!s.meta?.todo
function expectText(s) {
  const field = s.meta?.target_field ?? s.meta?.field
  if (props.errorMode === 'status') return s.meta?.expect === 'error' ? `rejected with ${props.errorStatus}` : `not ${props.errorStatus}`
  if (props.errorMode === 'redirect_errors') return s.meta?.expect === 'error' ? `302 · session error on ${field}` : s.meta?.expect === 'no_error' ? `no session error on ${field}` : 'no session errors'
  return s.meta?.expect === 'error' ? `422 · error on ${field}` : s.meta?.expect === 'no_error' ? `accepted · no error on ${field}` : 'accepted · no validation errors'
}
const shortCase = (s) => s.name.split(' — ')[1] ?? s.name
const ruleChip = (r) => (r.kind === 'known' ? 'bg-slate-100 text-slate-700' : r.kind === 'custom' ? 'bg-violet-50 text-violet-700' : 'bg-amber-50 text-amber-800')

function result(s) {
  if (isTodo(s)) return { status: 'needs_human', label: 'Needs a human' }
  if (!isSelected(s)) return { status: 'skipped', label: 'Not selected' }
  const r = props.results[s.id]
  const aiGuess = s.meta?.ai && !s.meta?.verified
  return r ? { status: r, label: r === 'failed' ? (aiGuess ? 'AI guess failed' : 'Not enforced') : undefined } : { status: 'queued', label: 'Not run' }
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[720px] text-[13px]">
      <thead class="bg-slate-50">
        <tr>
          <th v-if="selection" class="th w-8 pl-3"><span class="sr-only">Include</span></th>
          <th class="th w-12 pl-3">Case</th>
          <th class="th">Rule → what is checked</th>
          <th class="th">Input sent</th>
          <th class="th">Expected</th>
          <th class="th pr-3 text-right">{{ showResults ? 'Result' : 'Status' }}</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="g in groups" :key="g.field">
          <tr class="border-t border-slate-200 bg-white">
            <td :colspan="selection ? 6 : 5" class="px-3 pb-1.5 pt-3">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-[13px] font-bold text-slate-900">{{ g.field === '__payload' ? 'Whole payload' : g.field }}</span>
                <span v-if="g.info" class="text-[11px] font-medium uppercase text-slate-400">{{ g.info.type }} · line {{ g.info.line }}</span>
                <span v-for="r in g.info?.rules ?? []" :key="r.source" class="rounded px-1.5 py-0.5 font-mono text-[11px]" :class="ruleChip(r)" :title="r.note ?? r.source">{{ r.note ?? r.source }}</span>
              </div>
            </td>
          </tr>
          <template v-for="s in g.cases" :key="s.id">
            <tr class="border-t border-slate-100 align-top" :class="[isTodo(s) || !isSelected(s) ? 'text-slate-400' : 'text-slate-700', result(s).status === 'failed' ? 'bg-red-50/60' : '']">
              <td v-if="selection" class="py-2 pl-3">
                <input type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" :aria-label="`Include ${s.id}`" :checked="selection.has(s.id)" :disabled="isTodo(s)" @change="emit('toggle', s.id)" />
              </td>
              <td class="py-2 pl-3 text-xs font-bold text-slate-400">{{ s.id }}</td>
              <td class="py-2 pr-3">
                <span class="font-mono text-[12px] text-brand-700">{{ s.meta?.rule }}</span>
                <span class="text-slate-500"> — {{ shortCase(s) }}</span>
                <span v-if="s.meta?.ai" class="ml-1.5 rounded px-1.5 py-0.5 text-[10.5px] font-semibold" :class="s.meta.verified ? 'bg-emerald-50 text-emerald-700' : 'bg-violet-50 text-violet-700'" :title="s.basis_source">{{ s.meta.verified ? 'AI · checked' : 'AI · assumption' }}</span>
                <p v-if="isTodo(s)" class="mt-1 text-[11.5px] text-amber-700">{{ s.meta.todo }}</p>
                <p v-else-if="s.meta?.ai && !s.meta?.verified && s.description" class="mt-1 text-[11.5px] text-violet-700">{{ s.description }}</p>
              </td>
              <td class="max-w-[220px] truncate py-2 pr-3 font-mono text-[12px]" :title="s.inputs">{{ s.inputs }}</td>
              <td class="py-2 pr-3 text-[12px]">{{ expectText(s) }}</td>
              <td class="py-2 pr-3 text-right">
                <div class="flex items-center justify-end gap-2">
                  <Badge :status="result(s).status" :label="result(s).label" />
                  <button v-if="code && scenarioBlock(code, s.id)" class="text-xs font-semibold text-brand-600" @click="open = open === s.id ? null : s.id">{{ open === s.id ? 'Hide' : 'Test' }}</button>
                </div>
              </td>
            </tr>
            <tr v-if="open === s.id">
              <td :colspan="selection ? 6 : 5" class="px-3 pb-3"><CodeBlock :code="scenarioBlock(code, s.id)" :numbered="false" wrap max-height="16rem" /></td>
            </tr>
          </template>
        </template>
      </tbody>
    </table>
  </div>
</template>
