<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Badge from '../components/Badge.vue'
import Card from '../components/Card.vue'
import PageHeader from '../components/PageHeader.vue'
import Spinner from '../components/Spinner.vue'
import StatCard from '../components/StatCard.vue'
import { api } from '../lib/api.js'
import { ago, duration } from '../lib/format.js'

const props = defineProps({ id: String })
const run = ref(null)
const expanded = ref(null)
let poll

async function load() {
  run.value = await api(`/runs/${props.id}`)
  if (!['queued', 'running'].includes(run.value.status)) clearInterval(poll)
}

const byTarget = computed(() => {
  const groups = {}
  for (const r of run.value?.results ?? []) {
    const key = r.target ?? 'Unmatched'
    groups[key] ??= { name: key, id: r.target_id, results: [], failed: 0 }
    groups[key].results.push(r)
    if (['failed', 'error'].includes(r.status)) groups[key].failed++
  }
  return Object.values(groups).sort((a, b) => b.failed - a.failed)
})

function download() {
  const blob = new Blob([JSON.stringify(run.value, null, 2)], { type: 'application/json' })
  Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `testpilot-run-${run.value.number}.json` }).click()
}

onMounted(async () => { await load(); if (['queued', 'running'].includes(run.value.status)) poll = setInterval(load, 2000) })
onBeforeUnmount(() => clearInterval(poll))
</script>

<template>
  <template v-if="run">
    <PageHeader
      :crumbs="[{ label: 'Runs', to: '/runs' }, { label: `Run #${run.number}` }]"
      :title="`Run #${run.number}`"
      :subtitle="`${run.trigger} · started by ${run.started_by ?? '—'} ${ago(run.created_at)} · commit ${run.git_sha?.slice(0, 7) ?? 'unknown'}`"
      :more="[{ label: 'Download results (JSON)', action: download }]"
    >
      <template #actions><Badge :status="run.status" /></template>
    </PageHeader>

    <div v-if="['queued', 'running'].includes(run.status)" class="mb-4 flex items-center gap-3 rounded-lg bg-blue-50 p-4 text-sm text-blue-800"><Spinner /> Running approved tests in a separate process… this page updates automatically.</div>
    <div
      v-if="run.outcome"
      class="mb-4 rounded-lg border p-4"
      :class="{
        'border-green-200 bg-green-50 text-green-900': run.outcome.tone === 'green',
        'border-red-200 bg-red-50 text-red-900': run.outcome.tone === 'red',
        'border-blue-200 bg-blue-50 text-blue-900': run.outcome.tone === 'blue',
        'border-slate-200 bg-slate-50 text-slate-800': run.outcome.tone === 'gray',
        'border-amber-200 bg-amber-50 text-amber-900': !['green', 'red', 'blue', 'gray'].includes(run.outcome.tone),
      }"
      role="status"
    >
      <p class="text-sm font-bold">{{ run.outcome.title }}</p>
      <p class="mt-1 text-[13px]">{{ run.outcome.summary }}</p>
      <p class="mt-1 text-[13px] font-medium">{{ run.outcome.next_step }}</p>
    </div>

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
      <StatCard label="Passed" :value="run.passed" :hint="`of ${run.passed + run.failed + run.skipped} tests`" />
      <StatCard label="Failed" :value="run.failed" :hint="run.failed ? 'See failures below' : 'No failures'" :tone="run.failed ? 'red' : 'green'" />
      <StatCard label="Assertions" :value="run.assertions" hint="Checked by Pest" tone="gray" />
      <StatCard label="Duration" :value="duration(run.duration_ms)" :hint="run.finished_at ? `Finished ${ago(run.finished_at)}` : 'In progress'" tone="gray" />
    </div>

    <Card class="mt-4" title="What each test checked" hint="Open a result for the technical details." :padded="false">
      <div v-for="group in byTarget" :key="group.name" class="border-t border-slate-100">
        <div class="flex items-center gap-3 bg-slate-50 px-5 py-2.5">
          <RouterLink v-if="group.id" :to="`/targets/${group.id}/evidence`" class="flex-1 text-sm font-semibold hover:text-brand-600">{{ group.name }}</RouterLink>
          <span v-else class="flex-1 text-sm font-semibold">{{ group.name }}</span>
          <Badge :status="group.failed ? 'failed' : 'passed'" :label="group.failed ? `${group.failed} failed` : 'Passed'" />
        </div>
        <div v-for="r in group.results" :key="r.id" class="border-t border-slate-100 px-5 py-2.5" :class="r.details || r.message ? 'cursor-pointer' : ''" @click="expanded = expanded === r.id ? null : r.id">
          <div class="flex items-center gap-3 text-sm">
            <span class="w-4 font-bold" :class="r.status === 'passed' ? 'text-green-600' : r.status === 'skipped' ? 'text-amber-600' : 'text-red-600'">{{ r.status === 'passed' ? '✓' : r.status === 'skipped' ? '–' : '✗' }}</span>
            <span class="min-w-0 flex-1 truncate">{{ r.name }}</span>
            <span class="text-xs text-slate-500">{{ r.time_ms }} ms</span>
          </div>
          <p v-if="r.message && expanded !== r.id" class="ml-7 mt-0.5 truncate text-xs text-red-700">{{ r.message }}</p>
          <pre v-if="expanded === r.id && (r.details || r.message)" class="ml-7 mt-2 overflow-x-auto whitespace-pre-wrap rounded bg-slate-900 p-3 font-mono text-[11.5px] text-slate-200">{{ r.details ?? r.message }}</pre>
        </div>
      </div>
    </Card>

    <Card class="mt-4" title="Technical details" hint="Raw output from the Pest command. Share this with a developer when troubleshooting.">
      <template v-if="run.error">
        <p class="mb-2 text-xs font-semibold text-slate-600">Runner error</p>
        <pre class="mb-3 overflow-x-auto whitespace-pre-wrap rounded-lg bg-slate-900 p-3 font-mono text-[11.5px] text-slate-200">{{ run.error }}</pre>
      </template>
      <p class="mb-2 font-mono text-xs text-slate-500">$ {{ run.command }}</p>
      <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-900 p-3 font-mono text-[12px] text-slate-200">{{ run.output || 'No output yet.' }}</pre>
    </Card>
  </template>
</template>
