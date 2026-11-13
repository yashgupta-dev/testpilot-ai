<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Badge from '../components/Badge.vue'
import Card from '../components/Card.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import StatCard from '../components/StatCard.vue'
import { api, app } from '../lib/api.js'

const targets = ref(null)
onMounted(async () => { targets.value = (await api('/targets')).data })

const all = computed(() => targets.value ?? [])
const tested = (t) => t.status === 'approved'
const highRiskUntested = computed(() => all.value.filter((t) => t.risk.level === 'high' && !tested(t)))
const coverage = computed(() => (all.value.length ? Math.round((all.value.filter(tested).length / all.value.length) * 100) : 0))
const avgMutation = computed(() => {
  const scores = all.value.map((t) => t.mutation_score).filter((s) => s !== null && s !== undefined)
  return scores.length ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length) : null
})

const focus = computed(() => all.value
  .filter((t) => t.last_result === 'failed' || !tested(t) || (t.mutation_score ?? 100) < 60)
  .map((t) => {
    const why = t.last_result === 'failed' ? ['Failing test', 'red', 'Inspect'] : !tested(t) ? ['No approved tests', t.risk.level === 'high' ? 'red' : 'amber', 'Plan tests'] : [`Weak tests (mutation ${t.mutation_score}%)`, 'amber', 'Strengthen']
    return { ...t, why: why[0], tone: why[1], action: why[2], rank: (t.last_result === 'failed' ? 200 : 0) + t.risk.score }
  })
  .sort((a, b) => b.rank - a.rank)
  .slice(0, 5))

const areas = computed(() => {
  const groups = {}
  for (const t of all.value) {
    const key = t.area ?? 'Unassigned'
    groups[key] ??= { area: key, targets: 0, tested: 0, risk: 0, failing: 0, mutation: [] }
    const g = groups[key]
    g.targets++
    g.risk += t.risk.score
    if (tested(t)) g.tested++
    if (t.last_result === 'failed') g.failing++
    if (t.mutation_score != null) g.mutation.push(t.mutation_score)
  }
  return Object.values(groups).map((g) => {
    const coverage = Math.round((g.tested / g.targets) * 100)
    const strength = g.mutation.length ? Math.round(g.mutation.reduce((a, b) => a + b, 0) / g.mutation.length) : null
    const avgRisk = Math.round(g.risk / g.targets)
    const score = Math.round((coverage + (strength ?? 0) + (100 - avgRisk)) / 3 - g.failing * 15)
    return { ...g, coverage, strength, avgRisk, score }
  }).sort((a, b) => a.score - b.score)
})

const tone = (value, good, ok, invert = false) => {
  if (value === null || value === undefined) return 'bg-slate-100 text-slate-500'
  const v = invert ? -value : value
  return v >= (invert ? -good : good) ? 'bg-green-100 text-green-700' : v >= (invert ? -ok : ok) ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'
}
</script>

<template>
  <PageHeader :crumbs="[{ label: app.project }, { label: 'Analytics' }]" title="Analytics · Where to focus" subtitle="Your test quality mapped to business areas, so developers, QA and managers know what to fix first and why." />

  <template v-if="targets">
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
      <StatCard label="Targets with approved tests" :value="`${coverage}%`" hint="Share of annotated code that is proven" :tone="coverage >= 75 ? 'green' : 'amber'" />
      <StatCard label="High-risk code without tests" :value="highRiskUntested.length" :hint="highRiskUntested.length ? 'Start with these' : 'All high-risk code is covered'" :tone="highRiskUntested.length ? 'red' : 'green'" />
      <StatCard label="Failing targets" :value="all.filter((t) => t.last_result === 'failed').length" hint="Last run failed" :tone="all.some((t) => t.last_result === 'failed') ? 'red' : 'green'" />
      <StatCard label="Test trust (avg. mutation score)" :value="avgMutation === null ? '—' : `${avgMutation}%`" hint="How many injected bugs the tests catch" tone="gray" />
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_620px]">
      <Card title="Focus now · top 5" hint="Ranked by failing first, then risk. Start at #1.">
        <EmptyState v-if="!focus.length" icon="check" title="Nothing urgent" text="Every target has approved, passing, strong tests." />
        <ol class="space-y-2.5">
          <li v-for="(t, i) in focus" :key="t.id" class="flex items-center gap-3 rounded-xl bg-slate-50 p-3">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[13px] font-bold" :class="t.tone === 'red' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">{{ i + 1 }}</span>
            <div class="min-w-0 flex-1">
              <p class="text-xs font-bold text-brand-600">{{ t.area ?? 'Unassigned' }}</p>
              <p class="truncate text-sm font-semibold">{{ t.name }}</p>
              <p class="text-xs text-slate-500">{{ t.why }} · risk {{ t.risk.score }}</p>
            </div>
            <RouterLink :to="`/targets/${t.id}${t.action === 'Plan tests' ? '' : '/evidence'}`" :class="t.tone === 'red' ? 'btn-primary' : 'btn-secondary'">{{ t.action }}</RouterLink>
          </li>
        </ol>
      </Card>

      <Card title="Risk map by business area" hint='Areas come from #[TestPilot(area: "…")]. Red = focus here.' :padded="false">
        <table class="w-full">
          <thead class="bg-slate-50"><tr><th class="th pl-5">Area</th><th class="th text-center">Targets</th><th class="th text-center">Tested</th><th class="th text-center">Strength</th><th class="th text-center">Failing</th><th class="th text-center">Avg. risk</th><th class="th pr-5 text-center">Score</th></tr></thead>
          <tbody>
            <tr v-for="a in areas" :key="a.area" class="border-t border-slate-100">
              <td class="td pl-5 font-semibold">{{ a.area }}</td>
              <td class="td text-center text-slate-600">{{ a.targets }}</td>
              <td class="td text-center"><span class="inline-block w-14 rounded-md py-1 text-xs font-semibold" :class="tone(a.coverage, 75, 50)">{{ a.coverage }}%</span></td>
              <td class="td text-center"><span class="inline-block w-14 rounded-md py-1 text-xs font-semibold" :class="tone(a.strength, 75, 60)">{{ a.strength === null ? '—' : a.strength + '%' }}</span></td>
              <td class="td text-center"><span class="inline-block w-14 rounded-md py-1 text-xs font-semibold" :class="a.failing ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'">{{ a.failing }}</span></td>
              <td class="td text-center"><span class="inline-block w-14 rounded-md py-1 text-xs font-semibold" :class="tone(a.avgRisk, 40, 70, true)">{{ a.avgRisk }}</span></td>
              <td class="td pr-5 text-center"><span class="inline-block w-14 rounded-md py-1 text-xs font-bold" :class="tone(a.score, 75, 50)">{{ a.score }}</span></td>
            </tr>
          </tbody>
        </table>
      </Card>
    </div>

    <Card class="mt-4" title="Coming next: business flows, release readiness and team ownership" hint="These need flow and team definitions in config/testpilot.php; they are planned for the next phase.">
      <div class="flex flex-wrap gap-2 text-xs text-slate-600">
        <Badge status="queued" label="Critical flow chains (e.g. Browse → Cart → Coupon → Pay)" />
        <Badge status="queued" label="Release readiness score & gate" />
        <Badge status="queued" label="Teams & ownership" />
        <Badge status="queued" label="Coverage trends" />
      </div>
    </Card>
  </template>
</template>
