<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Badge from '../components/Badge.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import { api, app } from '../lib/api.js'
import { ago, duration } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const router = useRouter()
const runs = ref(null)
const readiness = ref(null)
const approvedCount = ref(0)
const busy = ref(false)

async function load() {
  const [runData, overview] = await Promise.all([api('/runs'), api('/overview')])
  runs.value = runData.data
  readiness.value = overview.environment.readiness
  approvedCount.value = overview.stats.with_approved_tests
}

async function start() {
  busy.value = true
  try {
    const run = await api('/runs', { method: 'POST', body: {} })
    router.push(`/runs/${run.id}`)
  } catch (e) { toast(e.message, 'error') } finally { busy.value = false }
}

const canRun = computed(() => !!readiness.value?.can_run_tests && approvedCount.value > 0)
const runDisabledReason = computed(() => {
  if (!readiness.value) return 'Checking project readiness…'
  const blockers = readiness.value.checks.filter((check) => check.status === 'blocked' && check.area === 'run')
  if (blockers.length) return blockers.map((check) => check.action).join(' ')
  if (approvedCount.value === 0) return 'No approved tests yet. Review and approve a test before running it.'
  return ''
})

onMounted(load)
</script>

<template>
  <PageHeader :crumbs="[{ label: app.project }, { label: 'Runs' }]" title="Runs" subtitle="Every execution of approved TestPilot tests — from the dashboard, the CLI and CI.">
    <template #actions><button class="btn-primary" :disabled="busy || !canRun" @click="start">{{ busy ? 'Starting…' : 'Run approved tests' }}</button></template>
  </PageHeader>

  <p v-if="!canRun && runDisabledReason" class="mb-4 rounded-lg bg-amber-50 p-3 text-[13px] text-amber-900">{{ runDisabledReason }}</p>

  <section class="card overflow-hidden">
    <EmptyState v-if="runs && !runs.length" icon="runs" title="No runs yet" text="Approved tests can be run here, with php artisan testpilot:run, or in CI with --ci." />
    <table v-else class="w-full">
      <thead class="bg-slate-50"><tr><th class="th pl-5">Run</th><th class="th">Status</th><th class="th">Passed</th><th class="th">Failed</th><th class="th">Trigger</th><th class="th">Commit</th><th class="th">Duration</th><th class="th pr-5">Started</th></tr></thead>
      <tbody>
        <tr v-for="r in runs ?? []" :key="r.id" class="cursor-pointer border-t border-slate-100 hover:bg-slate-50" @click="router.push(`/runs/${r.id}`)">
          <td class="td pl-5 font-semibold">#{{ r.number }}</td>
          <td class="td"><Badge :status="r.status" /></td>
          <td class="td text-green-700">{{ r.passed }}</td>
          <td class="td" :class="r.failed ? 'font-semibold text-red-700' : 'text-slate-500'">{{ r.failed }}</td>
          <td class="td capitalize text-slate-600">{{ r.trigger }}<span class="text-slate-400"> · {{ r.started_by }}</span></td>
          <td class="td font-mono text-xs text-slate-500">{{ r.git_sha?.slice(0, 7) ?? '—' }}</td>
          <td class="td text-slate-600">{{ duration(r.duration_ms) }}</td>
          <td class="td pr-5 text-slate-500">{{ ago(r.created_at) }}</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
