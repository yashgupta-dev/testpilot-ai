<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import { api, app } from '../lib/api.js'
import { toast } from '../lib/toast.js'

const targets = ref(null)
const filter = ref('all')
const dismissed = ref(new Set())

onMounted(async () => { targets.value = (await api('/targets')).data })

// Rule-based suggestions computed from real data. AI-written suggestions are planned for a later phase.
const suggestions = computed(() => {
  const list = []
  for (const t of targets.value ?? []) {
    if (t.last_result === 'failed') list.push({ id: `fail-${t.id}`, kind: 'Failing', tone: 'red', priority: 'High', title: `${t.name} has a failing test`, reason: 'The last run failed. Check whether the application or the expectation is wrong.', to: `/targets/${t.id}/evidence`, action: 'Inspect', primary: true })
    if (t.status === 'not_planned' && t.risk.level !== 'low') list.push({ id: `plan-${t.id}`, kind: 'Missing tests', tone: 'amber', priority: t.risk.level === 'high' ? 'High' : 'Medium', title: `${t.name} has no tests`, reason: `Risk ${t.risk.score}. ${t.area ? `Business area: ${t.area}.` : ''}`, to: `/targets/${t.id}`, action: 'Plan tests', primary: t.risk.level === 'high' })
    if (t.status === 'stale') list.push({ id: `stale-${t.id}`, kind: 'Stale', tone: 'gray', priority: 'Medium', title: `${t.name}: approved tests are stale`, reason: 'The source changed after the tests were approved. Regenerate them so they match the new code.', to: `/targets/${t.id}`, action: 'Regenerate' })
    if (t.mutation_score !== null && t.mutation_score !== undefined && t.mutation_score < 60) list.push({ id: `weak-${t.id}`, kind: 'Weak tests', tone: 'amber', priority: 'Medium', title: `${t.name} tests catch only ${t.mutation_score}% of injected bugs`, reason: 'The assertions likely check status or types but not the actual result. Add stronger expectations.', to: `/targets/${t.id}/evidence`, action: 'Strengthen', primary: true })
    if (t.route && !t.route.throttle && t.route.methods.includes('POST')) list.push({ id: `throttle-${t.id}`, kind: 'Security', tone: 'red', priority: 'High', title: `${t.route.methods.join('|')} ${t.route.uri} has no rate limit`, reason: 'A public POST route without throttle middleware is a common abuse target.', to: `/targets/${t.id}`, action: 'Review route', evidence: "->middleware('throttle:5,1')" })
    if (t.status === 'awaiting_approval') list.push({ id: `review-${t.id}`, kind: 'Review', tone: 'blue', priority: 'Medium', title: `${t.name}: verified tests are waiting for you`, reason: 'Nothing joins the suite until it is approved.', to: '/review', action: 'Review', primary: true })
  }
  const order = { High: 0, Medium: 1, Low: 2 }
  return list.filter((s) => !dismissed.value.has(s.id)).sort((a, b) => order[a.priority] - order[b.priority])
})

const kinds = computed(() => ['all', ...new Set(suggestions.value.map((s) => s.kind))])
const shown = computed(() => suggestions.value.filter((s) => filter.value === 'all' || s.kind === filter.value))
const toneClass = { red: 'bg-red-100 text-red-700', amber: 'bg-amber-100 text-amber-700', gray: 'bg-slate-100 text-slate-600', blue: 'bg-blue-100 text-blue-700' }
const priorityClass = { High: 'text-red-600', Medium: 'text-amber-600', Low: 'text-slate-500' }

function dismiss(s) {
  dismissed.value = new Set([...dismissed.value, s.id])
  toast('Dismissed for this session.')
}
</script>

<template>
  <PageHeader :crumbs="[{ label: app.project }, { label: 'Suggestions' }]" title="Suggestions" subtitle="Findings from your targets, tests and runs. Each shows its reason — nothing is ever applied to your code automatically." />

  <div class="mb-4 flex flex-wrap gap-2">
    <button v-for="k in kinds" :key="k" class="chip" :class="filter === k ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700'" @click="filter = k">
      {{ k === 'all' ? 'All' : k }} <span class="ml-1.5 opacity-70">{{ k === 'all' ? suggestions.length : suggestions.filter((s) => s.kind === k).length }}</span>
    </button>
  </div>

  <section v-if="targets && !shown.length" class="card"><EmptyState icon="check" title="No suggestions right now" text="Everything annotated has approved, passing tests." /></section>

  <div class="space-y-3">
    <article v-for="s in shown" :key="s.id" class="card flex items-center gap-5 p-5">
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
          <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="toneClass[s.tone]">{{ s.kind }}</span>
          <span class="text-xs font-medium" :class="priorityClass[s.priority]">Priority: {{ s.priority }}</span>
        </div>
        <p class="mt-1.5 text-[15px] font-semibold">{{ s.title }}</p>
        <p class="mt-0.5 text-[13px] text-slate-600">{{ s.reason }}</p>
        <code v-if="s.evidence" class="mt-2 inline-block rounded-md bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{{ s.evidence }}</code>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <RouterLink :to="s.to" :class="s.primary ? 'btn-primary' : 'btn-secondary'">{{ s.action }}</RouterLink>
        <button class="text-xs font-medium text-slate-500 hover:text-slate-800" @click="dismiss(s)">Dismiss</button>
      </div>
    </article>
  </div>
</template>
