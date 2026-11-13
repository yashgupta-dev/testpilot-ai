<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Badge from '../components/Badge.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import RiskBar from '../components/RiskBar.vue'
import { api, app, downloadExport } from '../lib/api.js'
import { targetFilters } from '../lib/status.js'
import { toast } from '../lib/toast.js'

const route = useRoute()
const router = useRouter()
const data = ref(null)
const status = ref(route.query.status ?? '')
const search = ref(route.query.search ?? '')
const selected = ref(new Set())
const busy = ref(false)
let timer

async function load() {
  const params = new URLSearchParams()
  if (status.value) params.set('status', status.value)
  if (search.value) params.set('search', search.value)
  data.value = await api(`/targets?${params}`)
}

watch(status, () => { router.replace({ query: { ...route.query, status: status.value || undefined } }); load() })
watch(search, () => { clearTimeout(timer); timer = setTimeout(load, 250) })

function toggle(id) {
  const next = new Set(selected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selected.value = next
}

async function planSelected() {
  busy.value = true
  let ok = 0
  for (const id of selected.value) {
    try { await api(`/targets/${id}/plan`, { method: 'POST' }); ok++ } catch (e) { toast(e.message, 'error') }
  }
  busy.value = false
  if (ok) toast(`Planning started for ${ok} target(s). Plans appear on each target when ready.`)
  selected.value = new Set()
  load()
}

async function scan() {
  try {
    const s = await api('/scan', { method: 'POST' })
    toast(`Scan complete: ${s.targets} targets.`)
    load()
  } catch (e) { toast(e.message, 'error') }
}

const moduleLabel = { unit: 'Unit', fuzz: 'Fuzz', validation: 'Validation', ratelimit: 'Rate limit' }
onMounted(load)

function exportCases(format) {
  if (!downloadExport({ format, targets: [...selected.value] })) toast('Export needs the Laravel app (not available in the demo).')
}
</script>

<template>
  <PageHeader
    :crumbs="[{ label: app.project }, { label: 'Targets' }]"
    title="Targets"
    subtitle="Annotated functions, methods and routes discovered from your code. Sorted by risk — test the top first."
    :more="[
      { label: 'Scan now', action: scan },
      { label: selected.size ? `Export ${selected.size} selected to Excel (.xlsx)` : 'Export test cases to Excel (.xlsx)', action: () => exportCases('xlsx') },
      { label: 'Export test cases as CSV', action: () => exportCases('csv') },
    ]"
  >
    <template #actions>
      <button class="btn-primary" :disabled="!selected.size || busy" @click="planSelected">Plan selected with AI{{ selected.size ? ` (${selected.size})` : '' }}</button>
    </template>
  </PageHeader>

  <div class="mb-4 flex flex-wrap items-center gap-2">
    <input v-model="search" class="input max-w-sm" placeholder="Search functions, classes, files…" aria-label="Search targets" />
    <button
      v-for="[value, label, countKey] in targetFilters" :key="value" class="chip"
      :class="status === value ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'"
      @click="status = value"
    >{{ label }}<span v-if="data?.counts?.[countKey ?? value] !== undefined" class="ml-1.5 opacity-70">{{ data.counts[countKey ?? value] }}</span></button>
  </div>

  <section class="card overflow-hidden">
    <header class="flex items-center justify-between px-5 py-4">
      <h2 class="text-[15px] font-semibold">{{ data?.data.length ?? '…' }} targets</h2>
      <p class="text-xs text-slate-500">Risk = complexity + recent changes + no tests + public route + money / auth / data</p>
    </header>
    <EmptyState v-if="data && !data.data.length" icon="targets" title="No targets match" text="Add #[TestPilot] to a class or method, then scan." />
    <table v-else class="w-full">
      <thead class="bg-slate-50">
        <tr><th class="th w-10 pl-5"></th><th class="th">Target</th><th class="th">Type</th><th class="th">Modules</th><th class="th">Risk</th><th class="th">Status</th><th class="th pr-5">Last run</th></tr>
      </thead>
      <tbody>
        <tr v-for="t in data?.data ?? []" :key="t.id" class="cursor-pointer border-t border-slate-100 hover:bg-slate-50" @click="router.push(`/targets/${t.id}`)">
          <td class="td pl-5" @click.stop>
            <input type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" :checked="selected.has(t.id)" :aria-label="`Select ${t.name}`" @change="toggle(t.id)" />
          </td>
          <td class="td">
            <p class="font-semibold text-slate-900">{{ t.route ? `${t.route.methods.join('|')} ${t.route.uri} → ${t.name}` : t.name }}</p>
            <p class="text-xs text-slate-500">{{ t.file }}:{{ t.line }}<span v-if="t.area"> · {{ t.area }}</span></p>
          </td>
          <td class="td capitalize text-slate-600">{{ t.type }}</td>
          <td class="td text-slate-600">{{ t.modules.map((m) => moduleLabel[m] ?? m).join(' · ') || '—' }}</td>
          <td class="td"><RiskBar :score="t.risk.score" :level="t.risk.level" /></td>
          <td class="td"><Badge :status="t.status" /></td>
          <td class="td pr-5"><Badge v-if="t.last_result" :status="t.last_result" /><span v-else class="text-slate-400">—</span></td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
