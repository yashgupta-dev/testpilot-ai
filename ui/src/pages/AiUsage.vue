<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import Badge from '../components/Badge.vue'
import Card from '../components/Card.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import { api, app } from '../lib/api.js'
import { ago } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const u = ref(null)
const days = ref(30)
const busy = ref(false)
const batchModule = ref('unit')

async function load() { u.value = await api(`/ai/usage?days=${days.value}`) }
watch(days, load)
onMounted(load)

const money = (v, digits = 2) => (v === null || v === undefined ? '—' : `$${Number(v).toFixed(v > 0 && v < 0.01 ? 4 : digits)}`)
const tokens = (n) => (n >= 1e6 ? `${(n / 1e6).toFixed(1)}M` : n >= 1e3 ? `${Math.round(n / 1e3)}K` : String(n))

const budget = computed(() => {
  const m = u.value?.month
  if (!m?.budget) return null
  const pct = Math.min(100, Math.round((m.spent / m.budget) * 100))
  return { pct, warn: Math.round(m.warn_at * 100), tone: pct >= 100 ? 'bg-red-500' : pct >= m.warn_at * 100 ? 'bg-amber-500' : 'bg-brand-600' }
})
const saved = computed(() => u.value?.totals.saved ?? { total: 0 })
const savedRows = computed(() => {
  const s = saved.value
  const max = Math.max(s.prompt_cache ?? 0, s.response_cache ?? 0, s.batch ?? 0, 0.000001)
  return [
    { label: 'Prompt caching', value: s.prompt_cache, how: 'System prompt + target context read from cache at a fraction of the input price', w: (s.prompt_cache / max) * 100 },
    { label: 'Response cache', value: s.response_cache, how: 'Unchanged code + prompt replayed with no API call', w: (s.response_cache / max) * 100 },
    { label: 'Batch planning', value: s.batch, how: 'Message Batches API at 50% of standard prices', w: (s.batch / max) * 100 },
  ]
})
const effective = computed(() => {
  const t = u.value?.totals
  if (!t) return null
  const would = t.cost + (t.saved.total ?? 0)
  return would > 0 ? Math.round(((t.saved.total ?? 0) / would) * 100) : 0
})
const chart = computed(() => {
  const d = u.value?.daily ?? []
  const max = Math.max(...d.map((x) => x.cost + x.saved), 0.0001)
  return d.map((x) => ({ ...x, cost: (x.cost / max) * 100, saved: (x.saved / max) * 100, rawCost: x.cost, rawSaved: x.saved }))
})
const routingRows = computed(() => [
  ['Plan', 'plan', 'Reasoning about intent and edge cases'],
  ['Generate', 'generate', 'Writing the Pest file'],
  ['Repair (1st try)', 'repair', 'Imports, mocks, typos'],
  ['Repair (later tries)', 'repair_escalation', 'When the cheap fix did not work'],
  ['Validation review', 'validation', 'Regex / custom-rule values and rule gaps (empty = Plan)'],
  ['Critical targets', 'critical', 'criticality: high — plan, generate and validation'],
].map(([label, key, why]) => ({ label, why, model: u.value?.routing?.[key] ?? null })))

async function submitBatch() {
  busy.value = true
  try {
    const r = await api('/ai/batches', { method: 'POST', body: { module: batchModule.value } })
    const skipped = Object.keys(r.skipped ?? {}).length
    toast(`Batch submitted: ${r.batch.requests} target(s) at 50% of the price${skipped ? `, ${skipped} skipped` : ''}. Plans appear when it ends.`)
    await load()
  } catch (e) { toast(e.message, 'error') } finally { busy.value = false }
}
async function poll(batch) {
  try {
    const r = await api(`/ai/batches/${batch.id}/poll`, { method: 'POST' })
    toast(r.ended ? 'Batch ended. Plans are ready on each target.' : 'Still processing. Most batches finish within an hour.')
    await load()
  } catch (e) { toast(e.message, 'error') }
}
const batchTone = { in_progress: 'running', ended: 'completed', failed: 'failed' }
</script>

<template>
  <PageHeader
    :crumbs="[{ label: app.project }, { label: 'AI usage' }]"
    title="AI usage & cost"
    subtitle="What TestPilot spends on Claude, what caching and batching saved, and which model does each step."
    :more="[{ label: 'Refresh', action: load }]"
  >
    <template #actions>
      <select v-model.number="days" class="input w-auto py-1.5" aria-label="Period">
        <option :value="7">Last 7 days</option><option :value="30">Last 30 days</option><option :value="90">Last 90 days</option>
      </select>
    </template>
  </PageHeader>

  <template v-if="u">
    <div class="mb-5 grid grid-cols-2 gap-4 xl:grid-cols-4">
      <div class="card card-pad min-w-0">
        <p class="text-[13px] font-medium text-slate-500">Spent this month</p>
        <p class="mt-1.5 text-[28px] font-bold leading-tight">{{ money(u.month.spent) }} <span v-if="u.month.budget" class="text-base font-medium text-slate-400">of {{ money(u.month.budget, 0) }}</span></p>
        <div v-if="budget" class="relative mt-3 h-2 rounded-full bg-slate-100">
          <div class="h-2 rounded-full" :class="budget.tone" :style="{ width: `${budget.pct}%` }" />
          <div class="absolute -top-1 h-4 w-0.5 bg-slate-400" :style="{ left: `${budget.warn}%` }" :title="`Warning at ${budget.warn}%`" />
        </div>
        <p class="mt-2 text-xs font-medium text-slate-500">{{ budget ? `${budget.pct}% used · warning at ${budget.warn}%` : 'No monthly budget set' }}</p>
      </div>
      <div class="card card-pad min-w-0">
        <p class="text-[13px] font-medium text-slate-500">Saved · {{ days }} days</p>
        <p class="mt-1.5 text-[28px] font-bold leading-tight text-green-700">{{ money(saved.total) }}</p>
        <p class="mt-1 text-xs font-medium text-green-700">{{ effective }}% less than without caching & batching</p>
      </div>
      <div class="card card-pad min-w-0">
        <p class="text-[13px] font-medium text-slate-500">Input read from prompt cache</p>
        <p class="mt-1.5 text-[28px] font-bold leading-tight">{{ u.totals.cache_read_share }}%</p>
        <p class="mt-1 truncate text-xs font-medium text-slate-500">{{ tokens(u.totals.tokens.cache_read) }} cached · {{ tokens(u.totals.tokens.input) }} fresh · TTL {{ u.settings.prompt_cache }}</p>
      </div>
      <div class="card card-pad min-w-0">
        <p class="text-[13px] font-medium text-slate-500">AI calls · {{ days }} days</p>
        <p class="mt-1.5 text-[28px] font-bold leading-tight">{{ u.totals.calls }}</p>
        <p class="mt-1 text-xs font-medium" :class="u.totals.errors ? 'text-red-600' : 'text-slate-500'">{{ u.totals.response_cache_hits }} replayed free · {{ u.totals.errors }} error(s)</p>
      </div>
    </div>

    <div class="mb-5 grid gap-5 xl:grid-cols-[1fr_400px]">
      <Card title="Daily cost and savings" hint="Dark: what you paid. Light: what caching and batching saved.">
        <EmptyState v-if="!u.totals.calls" title="No AI calls in this period" text="Plans and generated tests will show up here." />
        <div v-else class="flex h-44 items-end gap-1" role="img" :aria-label="`Daily AI cost over the last ${days} days`">
          <div v-for="d in chart" :key="d.day" class="group relative flex h-full flex-1 flex-col justify-end" :title="`${d.day}: paid ${money(d.rawCost)}, saved ${money(d.rawSaved)}`">
            <div class="rounded-t-sm bg-green-200" :style="{ height: `${d.saved}%` }" />
            <div class="bg-brand-600" :class="d.saved ? '' : 'rounded-t-sm'" :style="{ height: `${d.cost}%` }" />
          </div>
        </div>
        <div v-if="u.totals.calls" class="mt-2 flex justify-between text-[11px] text-slate-400"><span>{{ u.daily[0]?.day }}</span><span>today</span></div>
      </Card>
      <Card title="Where the savings come from">
        <ul class="space-y-4">
          <li v-for="r in savedRows" :key="r.label">
            <div class="flex items-baseline justify-between text-[13px]"><span class="font-semibold">{{ r.label }}</span><span class="font-bold text-green-700">{{ money(r.value) }}</span></div>
            <div class="mt-1.5 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-green-500" :style="{ width: `${r.w}%` }" /></div>
            <p class="mt-1 text-[11.5px] text-slate-500">{{ r.how }}</p>
          </li>
        </ul>
      </Card>
    </div>

    <div class="mb-5 grid gap-5 xl:grid-cols-2">
      <Card title="Model routing" hint="Each step uses the cheapest model that does it well." :padded="false">
        <template #actions><RouterLink to="/settings/ai" class="btn-secondary">Change</RouterLink></template>
        <table class="w-full">
          <thead class="bg-slate-50"><tr><th class="th pl-5">Step</th><th class="th">Model</th><th class="th pr-5">Why</th></tr></thead>
          <tbody>
            <tr v-for="r in routingRows" :key="r.label" class="border-t border-slate-100">
              <td class="td pl-5 font-semibold">{{ r.label }}</td>
              <td class="td whitespace-nowrap font-mono text-xs" :class="r.model ? 'text-brand-700' : 'text-slate-400'">{{ r.model ?? `default (${u.settings.default_model})` }}</td>
              <td class="td pr-5 text-xs text-slate-500">{{ r.why }}</td>
            </tr>
          </tbody>
        </table>
      </Card>
      <Card title="Spend by model and step" :padded="false">
        <table class="w-full">
          <thead class="bg-slate-50"><tr><th class="th pl-5">Model / step</th><th class="th text-right">Calls</th><th class="th text-right">Paid</th><th class="th text-right">Saved</th><th class="th pr-5 text-right">From cache</th></tr></thead>
          <tbody>
            <tr v-for="r in u.by_model" :key="`m-${r.name}`" class="border-t border-slate-100">
              <td class="td pl-5 font-mono text-xs">{{ r.name }}</td><td class="td text-right">{{ r.calls }}</td><td class="td text-right font-semibold">{{ money(r.cost) }}</td><td class="td text-right text-green-700">{{ money(r.saved) }}</td><td class="td pr-5 text-right">{{ r.cache_read_share }}%</td>
            </tr>
            <tr v-for="r in u.by_purpose" :key="`p-${r.name}`" class="border-t border-slate-100 bg-slate-50/50">
              <td class="td pl-5 text-xs font-semibold capitalize text-slate-600">step: {{ r.name }}</td><td class="td text-right">{{ r.calls }}</td><td class="td text-right font-semibold">{{ money(r.cost) }}</td><td class="td text-right text-green-700">{{ money(r.saved) }}</td><td class="td pr-5 text-right">{{ r.cache_read_share }}%</td>
            </tr>
            <tr v-if="!u.by_model.length"><td colspan="5" class="td pl-5 text-slate-400">No calls yet.</td></tr>
          </tbody>
        </table>
      </Card>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1fr_400px]">
      <Card title="Batch planning" hint="Plan every unplanned target in one Message Batch at 50% of the price. Results usually arrive within an hour." :padded="false">
        <template #actions>
          <select v-model="batchModule" class="input w-auto py-1.5 text-xs" aria-label="Module to plan"><option value="unit">Unit & fuzz</option><option value="feature">HTTP feature</option></select>
          <button class="btn-primary" :disabled="busy || !u.settings.batch_available" :title="u.settings.batch_available ? '' : 'Needs the anthropic driver'" @click="submitBatch">Plan unplanned as batch</button>
        </template>
        <table class="w-full">
          <thead class="bg-slate-50"><tr><th class="th pl-5">Batch</th><th class="th">Module</th><th class="th">Status</th><th class="th text-right">Targets</th><th class="th">Started</th><th class="th pr-5" /></tr></thead>
          <tbody>
            <tr v-for="b in u.batches" :key="b.id" class="border-t border-slate-100">
              <td class="td pl-5 font-mono text-xs">{{ b.id.slice(-8) }}</td>
              <td class="td text-xs">{{ b.module === 'feature' ? 'HTTP feature' : 'Unit & fuzz' }}</td>
              <td class="td"><Badge :status="batchTone[b.status] ?? b.status" :label="{ in_progress: 'Processing', ended: 'Ended', failed: 'Failed' }[b.status]" /></td>
              <td class="td text-right">{{ b.requests }}<span v-if="b.counts?.errored" class="text-red-600"> · {{ b.counts.errored }} failed</span></td>
              <td class="td text-xs text-slate-500">{{ ago(b.created_at) }} · {{ b.created_by }}</td>
              <td class="td pr-5 text-right"><button v-if="b.status === 'in_progress'" class="text-xs font-semibold text-brand-600" @click="poll(b)">Check now</button></td>
            </tr>
            <tr v-if="!u.batches.length"><td colspan="6" class="td pl-5 text-slate-400">No batches yet. From the CLI: <code class="font-mono text-xs">php artisan testpilot:plan --all --batch</code></td></tr>
          </tbody>
        </table>
      </Card>
      <Card title="Cost controls">
        <template #actions><RouterLink to="/settings/ai" class="text-sm font-semibold text-brand-600">Edit →</RouterLink></template>
        <dl class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-2.5 text-[13px]">
          <dt class="text-slate-500">Monthly budget</dt><dd class="font-semibold">{{ u.month.budget ? money(u.month.budget, 0) : 'off' }}</dd>
          <dt class="text-slate-500">Per-target monthly budget</dt><dd class="font-semibold">{{ u.settings.target_budget ? money(u.settings.target_budget) : 'off' }}</dd>
          <dt class="text-slate-500">Prompt cache</dt><dd class="font-semibold">{{ u.settings.prompt_cache === 'off' ? 'off' : `on · ${u.settings.prompt_cache}` }}</dd>
          <dt class="text-slate-500">Response cache</dt><dd class="font-semibold">{{ u.settings.response_cache ? `on · ${u.settings.response_cache_days} days` : 'off' }}</dd>
          <dt class="text-slate-500">Secrets redacted before sending</dt><dd class="font-semibold text-green-700">always</dd>
          <dt class="text-slate-500">Prompts stored</dt><dd class="font-semibold">never (metadata only)</dd>
        </dl>
        <p class="mt-4 text-[11.5px] text-slate-500">Top spenders this period:</p>
        <ul class="mt-1.5 space-y-1">
          <li v-for="t in u.top_targets" :key="t.target" class="flex gap-2 text-xs"><span class="min-w-0 flex-1 truncate font-mono text-slate-700">{{ t.target.split('\\').pop() }}</span><span class="font-semibold">{{ money(t.cost) }}</span></li>
          <li v-if="!u.top_targets.length" class="text-xs text-slate-400">None yet.</li>
        </ul>
      </Card>
    </div>
  </template>
</template>
