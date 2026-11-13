<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import Badge from '../components/Badge.vue'
import Card from '../components/Card.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import StatCard from '../components/StatCard.vue'
import { api, app } from '../lib/api.js'
import { ago, duration, money, pct } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const router = useRouter()
const data = ref(null)
const busy = ref('')

async function load() {
  data.value = await api('/overview')
}

async function scan() {
  busy.value = 'scan'
  try {
    const s = await api('/scan', { method: 'POST' })
    toast(`Scan complete: ${s.targets} targets (${s.added} new, ${s.changed} changed, ${s.stale} now stale).`)
    await load()
  } catch (e) {
    toast(e.message, 'error')
  } finally {
    busy.value = ''
  }
}

async function runAll() {
  busy.value = 'run'
  try {
    const run = await api('/runs', { method: 'POST', body: {} })
    router.push(`/runs/${run.id}`)
  } catch (e) {
    toast(e.message, 'error')
  } finally {
    busy.value = ''
  }
}

function exportJson() {
  const blob = new Blob([JSON.stringify(data.value, null, 2)], { type: 'application/json' })
  const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: 'testpilot-overview.json' })
  a.click()
}

const env = computed(() => data.value?.environment)
const readinessChecks = computed(() => env.value?.readiness?.checks ?? [])
const blockingChecks = computed(() => readinessChecks.value.filter((check) => check.status === 'blocked' && check.area === 'run'))
const canRun = computed(() => !!env.value?.readiness?.can_run_tests && (data.value?.stats.with_approved_tests ?? 0) > 0)
const runDisabledReason = computed(() => {
  if (!env.value?.readiness) return 'Checking project readiness…'
  if (blockingChecks.value.length) return blockingChecks.value.map((check) => check.action).join(' ')
  if ((data.value?.stats.with_approved_tests ?? 0) === 0) return 'No approved tests yet. Review and approve a test before running it.'
  return ''
})
const readinessStatus = computed(() => !canRun.value ? 'warning' : 'passed')
const readinessLabel = computed(() => canRun.value ? 'Ready to run' : blockingChecks.value.length ? `${blockingChecks.value.length} setup item(s)` : 'Approve tests first')

const maxTrend = 100
onMounted(load)
</script>

<template>
  <PageHeader
    :crumbs="[{ label: app.project }, { label: 'Overview' }]"
    title="Overview"
    :subtitle="data?.last_scan ? `Last scan ${ago(data.last_scan.at)} · ${data.stats.targets} annotated targets` : 'Run a scan to discover annotated code'"
    :more="[{ label: 'Export overview (JSON)', action: exportJson }, { label: 'Open settings', action: () => router.push('/settings') }]"
  >
    <template #actions>
      <button class="btn-secondary" :disabled="busy === 'scan'" @click="scan">{{ busy === 'scan' ? 'Scanning…' : 'Scan now' }}</button>
      <button class="btn-primary" :disabled="busy === 'run' || !canRun" @click="runAll">{{ busy === 'run' ? 'Starting…' : 'Run approved tests' }}</button>
    </template>
  </PageHeader>

  <template v-if="data">
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
      <StatCard label="Annotated targets" :value="data.stats.targets" :hint="`${data.stats.stale} stale after code changes`" :tone="data.stats.stale ? 'amber' : 'gray'" />
      <StatCard label="Targets with approved tests" :value="data.stats.with_approved_tests" :hint="`${data.stats.coverage_of_intent}% of annotated`" />
      <StatCard label="Pass rate (last run)" :value="pct(data.stats.pass_rate, 1)" :hint="`${data.stats.failing_targets} failing target(s)`" :tone="data.stats.failing_targets ? 'red' : 'green'" />
      <StatCard label="Mutation score (avg.)" :value="pct(data.stats.mutation_score)" hint="Share of injected bugs the tests catch" tone="gray" />
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_380px]">
      <Card title="Quality trend" hint="Weekly pass rate across all runs">
        <EmptyState v-if="!data.trend.length" icon="analytics" title="No runs yet" text="Approve a generated test, then run it to start the trend." />
        <div v-else class="flex h-48 items-end gap-6 pt-4">
          <div v-for="w in data.trend" :key="w.week" class="flex flex-1 flex-col items-center gap-1.5">
            <span class="text-[11px] font-semibold text-slate-500">{{ w.pass_rate ?? '—' }}</span>
            <div class="flex w-full items-end justify-center gap-1">
              <div class="w-4 rounded-t bg-brand-600" :style="{ height: `${((w.pass_rate ?? 0) / maxTrend) * 150}px` }" :title="`Pass rate ${w.pass_rate}%`" />
              <div v-if="w.mutation !== undefined" class="w-4 rounded-t bg-brand-300" :style="{ height: `${(w.mutation / maxTrend) * 150}px` }" :title="`Mutation ${w.mutation}%`" />
            </div>
            <span class="text-[11px] font-medium text-slate-400">{{ w.week }}</span>
          </div>
        </div>
      </Card>

      <Card title="Project readiness" hint="What is ready now and what needs attention">
        <template #actions><Badge :status="readinessStatus" :label="readinessLabel" /></template>
        <p v-if="!canRun" class="mb-3 rounded-lg bg-amber-50 p-3 text-[13px] text-amber-900">{{ runDisabledReason }}</p>
        <ul class="space-y-3">
          <li v-for="check in readinessChecks" :key="check.id" class="border-t border-slate-100 pt-3 first:border-0 first:pt-0">
            <div class="flex items-start gap-2.5">
              <span class="font-bold" :class="check.status === 'ready' ? 'text-green-600' : check.status === 'blocked' ? 'text-red-600' : 'text-amber-600'">
                {{ check.status === 'ready' ? '✓' : check.status === 'blocked' ? '!' : 'i' }}
              </span>
              <div class="min-w-0">
                <p class="text-[13px] font-semibold text-slate-800">{{ check.title }}</p>
                <p class="mt-0.5 text-[12px] text-slate-600">{{ check.detail }}</p>
                <p class="mt-0.5 text-[12px] text-slate-500">{{ check.action }}</p>
              </div>
            </div>
          </li>
        </ul>
      </Card>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_380px]">
      <Card title="Recent runs" :padded="false">
        <template #actions><RouterLink to="/runs" class="btn-secondary">View all</RouterLink></template>
        <EmptyState v-if="!data.recent_runs.length" icon="runs" title="No runs yet" />
        <table v-else class="w-full">
          <thead class="bg-slate-50"><tr><th class="th pl-5">Run</th><th class="th">Trigger</th><th class="th">Tests</th><th class="th">Result</th><th class="th">Duration</th><th class="th pr-5">When</th></tr></thead>
          <tbody>
            <tr v-for="r in data.recent_runs" :key="r.id" class="cursor-pointer border-t border-slate-100 hover:bg-slate-50" @click="router.push(`/runs/${r.id}`)">
              <td class="td pl-5 font-semibold">#{{ r.number }}</td>
              <td class="td capitalize text-slate-600">{{ r.trigger }}</td>
              <td class="td">{{ r.passed + r.failed + r.skipped }}</td>
              <td class="td"><Badge :status="r.status" :label="r.outcome?.title ?? (r.failed ? `${r.failed} failed` : undefined)" /></td>
              <td class="td text-slate-600">{{ duration(r.duration_ms) }}</td>
              <td class="td pr-5 text-slate-500">{{ ago(r.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </Card>

      <Card title="Needs attention">
        <div class="divide-y divide-slate-100">
          <div class="flex items-center gap-3 pb-3">
            <div class="min-w-0 flex-1"><p class="text-sm font-semibold">{{ data.stats.awaiting_approval }} tests awaiting approval</p><p class="text-xs text-slate-500">Verified — review before they join the suite</p></div>
            <RouterLink to="/review" class="btn-primary">Review</RouterLink>
          </div>
          <div class="flex items-center gap-3 py-3">
            <div class="min-w-0 flex-1"><p class="text-sm font-semibold">{{ data.stats.failing_targets }} failing targets</p><p class="text-xs text-slate-500">Last run failed for these targets</p></div>
            <RouterLink to="/targets?status=failing" class="btn-secondary">Inspect</RouterLink>
          </div>
          <div class="flex items-center gap-3 py-3">
            <div class="min-w-0 flex-1"><p class="text-sm font-semibold">{{ data.stats.stale }} stale tests</p><p class="text-xs text-slate-500">Source changed after approval</p></div>
            <RouterLink to="/targets?status=stale" class="btn-secondary">Open</RouterLink>
          </div>
          <div class="pt-3">
            <p class="text-[13px] font-medium text-slate-700">AI spend this month · {{ money(data.ai.spent) }} of {{ money(data.ai.budget) }}</p>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-brand-600" :style="{ width: `${Math.min(100, data.ai.budget ? (data.ai.spent / data.ai.budget) * 100 : 0)}%` }" /></div>
          </div>
        </div>
      </Card>
    </div>
  </template>
</template>
