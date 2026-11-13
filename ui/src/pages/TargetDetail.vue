<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import Badge from '../components/Badge.vue'
import Card from '../components/Card.vue'
import CodeBlock from '../components/CodeBlock.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import Spinner from '../components/Spinner.vue'
import Tabs from '../components/Tabs.vue'
import RateLimitTimeline from '../components/RateLimitTimeline.vue'
import ValidationGrid from '../components/ValidationGrid.vue'
import { api, app, downloadExport } from '../lib/api.js'
import { ago, duration, scenarioBlock } from '../lib/format.js'
import { riskColor } from '../lib/status.js'
import { toast } from '../lib/toast.js'

const props = defineProps({ id: String, tab: String })
const router = useRouter()
const t = ref(null)
const busy = ref(false)
const selection = ref(new Set())
const edits = ref({})
const editing = ref(null)
const addition = ref('')
const rejectReason = ref('')
const module = ref(null)
let poll

const tab = computed({
  get: () => props.tab || 'plan',
  set: (value) => router.replace(`/targets/${props.id}/${value}`),
})

const working = computed(() => ['planning', 'generating'].includes(t.value?.status) || t.value?.test?.status === 'generating')

async function load() {
  t.value = await api(`/targets/${props.id}${module.value ? `?module=${module.value}` : ''}`)
  module.value = t.value.module
  if (t.value.plan?.status === 'proposed' && selection.value.size === 0) {
    selection.value = new Set(t.value.plan.scenarios.filter((s) => s.selected).map((s) => s.id))
  }
}

watch(working, (now) => {
  clearInterval(poll)
  if (now) poll = setInterval(load, 2500)
})
onMounted(async () => { await load(); if (working.value) poll = setInterval(load, 2500) })
onBeforeUnmount(() => clearInterval(poll))

async function act(fn, success) {
  busy.value = true
  try { await fn(); if (success) toast(success); await load() } catch (e) { toast(e.message, 'error') } finally { busy.value = false }
}

const isValidation = computed(() => t.value?.module === 'validation')
const isRateLimit = computed(() => t.value?.module === 'ratelimit')
const isFeature = computed(() => t.value?.module === 'feature')
const deterministic = computed(() => isValidation.value || isRateLimit.value)
const moduleLabels = { unit: 'Unit & fuzz', feature: 'HTTP feature', validation: 'Validation', ratelimit: 'Rate limit' }
const planLabel = computed(() => (isValidation.value ? 'Plan validation tests' : isRateLimit.value ? 'Plan rate-limit tests' : 'Plan with AI'))
const canPlan = computed(() => !!t.value?.enabled && (t.value?.plannable_modules ?? []).length > 0 && (!t.value.plan || t.value.plan.status === 'failed' || ['not_planned', 'needs_attention', 'stale'].includes(t.value.status)))

function switchModule(m) {
  if (m === module.value) return
  module.value = m
  selection.value = new Set()
  edits.value = {}
  editing.value = null
  load()
}

const planNow = () => act(
  () => api(`/targets/${props.id}/plan`, { method: 'POST', body: { module: module.value ?? 'unit' } }),
  isValidation.value ? 'Reading the FormRequest rules…' : isRateLimit.value ? 'Reading the route throttle and #[RateLimit]…' : 'Planning started. Scenarios appear here when the AI is done.',
)
const approvePlan = () => act(async () => {
  await api(`/targets/${props.id}/plans/${t.value.plan.id}/approve`, {
    method: 'POST',
    body: { selected: [...selection.value], edits: Object.entries(edits.value).map(([id, e]) => ({ id, ...e })), additions: addition.value.trim() ? [addition.value.trim()] : [] },
  })
  addition.value = ''
  edits.value = {}
}, 'Plan approved. Generating and verifying tests…')
const approveTest = () => act(() => api(`/tests/${t.value.test.id}/approve`, { method: 'POST' }), 'Approved. The test is now part of tests/TestPilot/Generated.')
const rejectTest = () => act(async () => { await api(`/tests/${t.value.test.id}/reject`, { method: 'POST', body: { reason: rejectReason.value } }); rejectReason.value = '' }, 'Rejected.')
const copyKey = () => { navigator.clipboard?.writeText(t.value.key); toast('Target key copied.') }
const runTests = () => act(async () => { const run = await api('/runs', { method: 'POST', body: { targets: [props.id] } }); router.push(`/runs/${run.id}`) })

function toggle(id) {
  const next = new Set(selection.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selection.value = next
}

// ---- derived views -------------------------------------------------------------------------
const steps = computed(() => {
  const order = ['scan', 'plan', 'approve', 'generate', 'review']
  // Per module: the target status is shared, but each module has its own plan and test.
  const plan = t.value?.plan
  const test = t.value?.test
  let at = 1
  if (t.value?.status === 'skipped') at = 0
  else if (test?.status === 'approved') at = 5
  else if (test && test.status !== 'generating') at = 4
  else if (test?.status === 'generating' || plan?.status === 'approved') at = 3
  else if (plan?.status === 'proposed') at = 2
  const labels = deterministic.value
    ? ['Scan', isValidation.value ? (t.value?.plan?.context?.origin === 'inline' ? 'Read rules' : 'Read rules()') : 'Read limit', 'Approve cases', 'Generate + run', 'Review & approve']
    : ['Scan', 'Plan with AI', 'Approve plan', 'Generate + verify', 'Review & approve']
  return labels.map((label, i) => ({ label, key: order[i], state: i < at ? 'done' : i === at ? 'current' : 'todo' }))
})

const stages = computed(() => Object.fromEntries((t.value?.test?.verification?.stages ?? []).map((s) => [s.stage, s])))
const scenarioResults = computed(() => t.value?.test?.verification?.scenario_results ?? {})
const approvedScenarios = computed(() => (t.value?.test?.scenarios ?? t.value?.plan?.scenarios ?? []).filter((s) => s.selected))

const proofs = computed(() => {
  const v = t.value?.test?.verification
  if (!v) return []
  const run = v.run
  const confirmed = approvedScenarios.value.filter((s) => s.basis === 'confirmed').length
  const mutation = v.mutation
  if (isRateLimit.value) {
    const ctxv = t.value.plan?.context ?? {}
    const byKind = Object.fromEntries((t.value.test?.scenarios ?? t.value.plan?.scenarios ?? []).map((s) => [s.meta?.kind, s]))
    const result = (kind) => (byKind[kind] && !byKind[kind].meta?.todo ? scenarioResults.value[byKind[kind].id] : undefined)
    const tile = (kind) => (result(kind) === undefined ? null : result(kind) === 'passed')
    return [
      { title: 'Executed', ok: run ? run.counts.passed > 0 && run.assertions > 0 : false, value: run ? (run.assertions === 0 ? '0 assertions' : `${run.counts.passed} of ${run.counts.passed + run.counts.failed} passed`) : 'Not run', caption: run ? `${run.assertions} assertions · ${duration(run.duration_ms)}` : stages.value.safety?.summary, how: 'Real requests through ThrottleRequests' },
      { title: 'Limit enforced', ok: tile('block'), value: tile('block') === false ? 'Not enforced' : `429 after ${ctxv.max_attempts}`, caption: tile('block') === false ? `Request ${ctxv.max_attempts + 1} was not blocked` : `${ctxv.max_attempts} per ${ctxv.decay_seconds}s`, how: 'Request N+1 must get 429' },
      { title: 'Retry-After', ok: tile('retry_after'), value: tile('retry_after') === false ? 'Missing' : `≤ ${ctxv.decay_seconds}s`, caption: 'Clients are told when to retry', how: 'Header on the 429' },
      { title: 'Resets', ok: tile('reset'), value: tile('reset') === false ? 'Still blocked' : `After ${ctxv.decay_seconds}s`, caption: 'Clock moved with travel()', how: 'No sleeping in tests' },
      { title: 'Per client', ok: tile('isolation'), value: ctxv.keyed_by === 'user' ? 'Per user' : 'Per IP', caption: tile('isolation') === false ? 'One client blocks everyone' : 'Others are not blocked', how: 'Second client after the limit' },
    ]
  }
  if (isValidation.value) {
    const all = t.value.plan?.scenarios ?? approvedScenarios.value
    const runnable = approvedScenarios.value.filter((s) => !s.meta?.todo)
    const todo = all.filter((s) => s.meta?.todo).length
    const negatives = runnable.filter((s) => s.meta?.expect === 'error')
    const enforced = negatives.filter((s) => scenarioResults.value[s.id] === 'passed').length
    const bugs = Object.keys(v.suspected_bugs ?? {}).length
    const ruleCount = (t.value.plan?.context?.fields ?? []).reduce((n, f) => n + f.rules.length, 0)
    return [
      { title: 'Executed', ok: run ? run.counts.passed > 0 && run.assertions > 0 : false, value: run ? (run.assertions === 0 ? '0 assertions' : `${run.counts.passed} of ${run.counts.passed + run.counts.failed} passed`) : 'Not run', caption: run ? `${run.assertions} assertions · ${duration(run.duration_ms)}` : stages.value.safety?.summary, how: 'Real HTTP requests to the route, in a separate Pest process' },
      { title: 'Rules enforced', ok: enforced === negatives.length, value: `${enforced} of ${negatives.length}`, caption: `Invalid inputs rejected (${failureText.value})`, how: { status: 'assertStatus', redirect_errors: 'assertSessionHasErrors' }[ctx.value?.error_mode] ?? 'assertJsonValidationErrors' },
      { title: 'Not enforced', ok: bugs === 0, value: bugs ? `${bugs} rule${bugs > 1 ? 's' : ''}` : 'None', caption: bugs ? 'Declared in rules() but the endpoint accepts it' : 'Every declared rule holds', how: 'Failing cases while the rest pass' },
      { title: 'Rules covered', ok: todo === 0, value: `${ruleCount - todo} of ${ruleCount}`, caption: todo ? `${todo} case(s) need a human` : 'Every rule has a case', how: 'unique, regex and custom rules are flagged' },
      { title: 'Expectations', ok: true, value: ctx.value?.origin === 'inline' ? 'From the action' : 'From rules()', caption: 'No AI, no assumptions', how: `${ctx.value?.rules_origin ?? 'rules()'} · line ${ctx.value?.rules_line ?? '?'}` },
    ]
  }
  return [
    { title: 'Executed', ok: !!run?.passed && run.assertions > 0, value: run ? (run.assertions === 0 ? '0 assertions' : `${run.counts.passed} passed`) : 'Not run', caption: run ? `${run.assertions} assertions · ${duration(run.duration_ms)}` : stages.value.safety?.summary, how: 'Real Pest run in a separate process' },
    { title: 'Stable', ok: stages.value.stability?.status === 'passed', value: stages.value.stability?.summary ?? '—', caption: 'Random order each time', how: 'Flaky tests are rejected' },
    isFeature.value
      ? { title: 'Through the route', ok: run ? run.counts.passed > 0 : null, value: `${ctx.value?.method ?? ''} ${ctx.value?.uri ?? ''}`, caption: `Fresh database · fakes: ${(ctx.value?.fakes ?? []).join(', ') || 'none'}`, how: 'Real requests, never the controller directly' }
      : { title: 'Bugs caught', ok: (mutation?.score ?? 0) >= 60, value: mutation?.score != null ? `${mutation.caught ?? '?'} of ${mutation.tested ?? '?'} · ${mutation.score}%` : 'Not measured', caption: mutation?.score != null ? 'gate ≥ 60%' : (stages.value.mutation?.summary ?? 'Mutation testing skipped'), how: 'pest --mutate' },
    { title: 'Expectations', ok: confirmed === approvedScenarios.value.length, value: `${confirmed} of ${approvedScenarios.value.length} confirmed`, caption: `${approvedScenarios.value.length - confirmed} assumption(s), human-approved`, how: 'Source of each expected value' },
    { title: 'Coverage', ok: null, value: 'Next phase', caption: 'Line/branch coverage per target', how: 'pest --coverage (planned)' },
  ]
})

const verdict = computed(() => {
  const test = t.value?.test
  if (!test) return null
  const gaps = proofs.value.filter((p) => p.ok === false).length
  if (test.status === 'suspected_bug' && isValidation.value) {
    const names = Object.fromEntries((test.scenarios ?? []).map((s) => [s.id, s.name]))
    return { tone: 'red', title: 'Validation rules that are declared but not enforced', text: Object.keys(test.verification?.suspected_bugs ?? {}).map((id) => `${id} ${names[id] ?? ''}`).join(' · ') + '. The rest of the rules hold. Fix the endpoint, or approve to keep this as a failing regression test.' }
  }
  if (test.status === 'suspected_bug' && isRateLimit.value) {
    const names = Object.fromEntries((test.scenarios ?? []).map((s) => [s.id, s.name]))
    return { tone: 'red', title: 'The route does not enforce the declared limit', text: Object.keys(test.verification?.suspected_bugs ?? {}).map((id) => `${id} ${names[id] ?? ''}`).join(' · ') + `. Expected ${t.value.plan?.context?.max_attempts} requests per ${t.value.plan?.context?.decay_seconds}s (${t.value.plan?.context?.source}).` }
  }
  if (test.status === 'suspected_bug') return { tone: 'red', title: 'Possible bug in the application', text: Object.entries(test.verification?.suspected_bugs ?? {}).map(([id, why]) => `${id}: ${why}`).join(' · ') || 'A confirmed scenario fails. Check the code before approving.' }
  if (test.status === 'unsafe') return { tone: 'red', title: 'Rejected by the safety check — never executed', text: (stages.value.safety?.data?.violations ?? []).map((v) => `line ${v.line}: ${v.message}`).join(' · ') }
  if (test.verification?.failure) {
    const failure = test.verification.failure
    return { tone: 'amber', title: failure.title, text: `${failure.summary} ${failure.next_step}` }
  }
  if (test.status === 'failed' && stages.value.setup) return { tone: 'amber', title: 'Test setup or discovery problem', text: stages.value.setup.summary }
  if (test.status === 'failed') return { tone: 'red', title: 'Could not produce a passing, stable test', text: test.error ?? 'See the stages below for the failure output.' }
  if (test.status === 'generating') return { tone: 'blue', title: 'Generating and verifying…', text: 'This page refreshes automatically.' }
  return gaps
    ? { tone: 'amber', title: `Logic verified — with ${gaps} gap${gaps > 1 ? 's' : ''}`, text: 'The tests run reliably. See the tiles marked "Gap" for what is not proven yet.' }
    : isRateLimit.value
      ? { tone: 'green', title: 'The rate limit is enforced', text: `Requests 1–${t.value.plan?.context?.max_attempts} pass, the next gets 429 with Retry-After, and access returns after ${t.value.plan?.context?.decay_seconds}s.` }
      : isFeature.value
        ? { tone: 'green', title: 'Business behaviour verified', text: `Every approved scenario passed through ${ctx.value?.method ?? ''} ${ctx.value?.uri ?? ''} with a fresh database, twice in random order. Mutation testing is not run for HTTP tests.` }
        : { tone: 'green', title: isValidation.value ? 'Every declared rule is enforced' : 'Logic verified', text: isValidation.value ? 'Each invalid input was rejected with a 422 on the right field, and the valid payload was accepted.' : 'The tests run reliably, catch injected bugs and every expectation is confirmed.' }
})

const sourceMarkers = computed(() => {
  const markers = {}
  for (const s of t.value?.test?.verification?.mutation?.survivors ?? []) {
    if (s.line) markers[s.line] = { tone: 'red', text: `✗ survived: ${s.description}` }
  }
  return markers
})

const ctx = computed(() => t.value?.plan?.context ?? null)
const failureText = computed(() => ({
  status: `HTTP ${ctx.value?.error_status} (your response)`,
  redirect_errors: '302 + session errors',
}[ctx.value?.error_mode] ?? '422 + field errors'))
const todoCount = computed(() => (t.value?.plan?.scenarios ?? []).filter((s) => s.meta?.todo).length)

const traceRows = computed(() => t.value?.config?.trace ?? [])
const banners = { green: 'border-green-200 bg-green-50 text-green-900', amber: 'border-amber-300 bg-amber-50 text-amber-900', red: 'border-red-200 bg-red-50 text-red-900', blue: 'border-blue-200 bg-blue-50 text-blue-900' }
const annotationText = computed(() => [...(t.value?.annotations?.class ?? []), ...(t.value?.annotations?.method ?? [])].map((a) => `#[${a.name}${Object.keys(a.arguments).length ? '(' + Object.entries(a.arguments).map(([k, v]) => `${k}: ${JSON.stringify(v)}`).join(', ') + ')' : ''}]`).join(' '))

function exportCases() {
  if (!downloadExport({ format: 'xlsx', module: module.value ?? t.value?.module, targets: [props.id] })) toast('Export needs the Laravel app (not available in the demo).')
}
</script>

<template>
  <template v-if="t">
    <PageHeader
      :crumbs="[{ label: 'Targets', to: '/targets' }, { label: t.name }, { label: { plan: 'Test plan', evidence: 'Test evidence', code: 'Generated code', trace: 'Settings trace' }[tab] }]"
      :title="t.name"
      :subtitle="`${t.file}:${t.line}  ·  ${annotationText}`"
      :more="[{ label: isValidation ? 'Re-read rules()' : isRateLimit ? 'Re-read the limit' : 'Plan again with AI', action: planNow }, { label: 'Copy target key', action: copyKey }, { label: 'Export test cases (.xlsx)', action: exportCases }]"
    >
      <template #actions>
        <Badge :status="t.status" />
        <button v-if="canPlan" class="btn-primary" :disabled="busy" @click="planNow">{{ planLabel }}</button>
        <button v-if="t.status === 'approved'" class="btn-primary" :disabled="busy" @click="runTests">Run tests</button>
      </template>
      <template #tabs>
        <div v-if="(t.plannable_modules ?? []).length > 1" class="mt-4 flex flex-wrap items-center gap-2" role="group" aria-label="Test module">
          <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Module</span>
          <button
            v-for="m in t.plannable_modules" :key="m"
            class="flex items-center gap-2 rounded-lg border px-3 py-1.5 text-[13px] font-semibold"
            :class="m === t.module ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
            :aria-pressed="m === t.module" @click="switchModule(m)"
          >
            {{ moduleLabels[m] ?? m }}
            <Badge v-if="t.modules_summary?.[m]?.test ?? t.modules_summary?.[m]?.plan" :status="t.modules_summary[m].test ?? t.modules_summary[m].plan" />
            <span v-else class="text-[11px] font-medium text-slate-400">no plan</span>
          </button>
        </div>
        <div class="mt-5"><Tabs v-model="tab" :tabs="[{ id: 'plan', label: 'Test plan' }, { id: 'evidence', label: 'Test evidence' }, { id: 'code', label: 'Generated code' }, { id: 'trace', label: 'Settings trace' }]" /></div>
      </template>
    </PageHeader>

    <!-- ============ TEST PLAN ============ -->
    <template v-if="tab === 'plan'">
      <ol class="card mb-5 flex flex-wrap items-center gap-2 px-4 py-3">
        <template v-for="(step, i) in steps" :key="step.key">
          <li class="flex items-center gap-2">
            <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold" :class="step.state === 'done' ? 'bg-green-600 text-white' : step.state === 'current' ? 'bg-brand-600 text-white' : 'bg-slate-200 text-slate-500'">{{ step.state === 'done' ? '✓' : i + 1 }}</span>
            <span class="text-[13px]" :class="step.state === 'todo' ? 'font-medium text-slate-400' : step.state === 'current' ? 'font-semibold' : 'font-medium'">{{ step.label }}</span>
          </li>
          <li v-if="i < steps.length - 1" class="h-0.5 w-6 bg-slate-200" aria-hidden="true" />
        </template>
      </ol>

      <div class="grid gap-5 xl:grid-cols-[360px_1fr]">
        <div class="space-y-5">
          <Card title="Why test this">
            <template #actions><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :style="{ background: riskColor(t.risk.level) + '1a', color: riskColor(t.risk.level) }">Risk {{ t.risk.score }} · {{ t.risk.level }}</span></template>
            <ul class="space-y-3">
              <li v-for="r in t.risk_reasons" :key="r.label" class="flex gap-2.5">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :style="{ background: riskColor(t.risk.level) }" />
                <div><p class="text-[13px] font-semibold">{{ r.label }} <span class="font-normal text-slate-400">+{{ r.points }}</span></p><p class="text-xs text-slate-500">{{ r.detail }}</p></div>
              </li>
            </ul>
          </Card>
          <Card v-if="t.route" title="HTTP route">
            <p class="break-all font-mono text-sm">{{ t.route.methods.join('|') }} {{ t.route.uri }}</p>
            <p class="mt-1 text-xs text-slate-500">Throttle: {{ t.route.throttle ?? 'none' }}</p>

          </Card>
          <Card v-if="isValidation && ctx" title="Rules source" :hint="ctx.origin === 'inline' ? 'Read from the controller action — no FormRequest needed. Nothing is executed to plan.' : 'Read statically from the FormRequest — the code is never executed to plan.'">
            <p class="break-words font-mono text-xs text-slate-800">{{ ctx.rules_origin ?? `${ctx.request_class}::rules()` }}</p>
            <p class="mt-1 font-mono text-[11px] text-slate-500">{{ ctx.request_file }}:{{ ctx.rules_line }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
              <dt class="text-slate-500">Fields</dt><dd class="font-semibold">{{ ctx.fields.length }}</dd>
              <dt class="text-slate-500">Request</dt><dd class="break-all font-mono font-semibold">{{ ctx.method }} {{ ctx.uri }}</dd>
              <dt class="text-slate-500">Signed-in user</dt><dd class="font-semibold">{{ ctx.authenticate ? 'Yes (factory user)' : 'No' }}</dd>
              <dt class="text-slate-500">On failure</dt><dd class="font-semibold">{{ failureText }}</dd>
              <template v-if="ctx.origin !== 'inline'"><dt class="text-slate-500">authorize()</dt><dd class="font-semibold" :class="ctx.authorize === 'true' ? '' : 'text-amber-700'">{{ ctx.authorize }}</dd></template>
              <dt class="text-slate-500">Needs a human</dt><dd class="font-semibold" :class="todoCount ? 'text-amber-700' : ''">{{ todoCount }} case(s)</dd>
            </dl>
            <ul v-if="ctx.warnings.length || ctx.baseline_issues.length" class="mt-3 space-y-1.5">
              <li v-for="w in [...ctx.warnings, ...ctx.baseline_issues]" :key="w" class="rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11.5px] text-amber-900">{{ w }}</li>
            </ul>
          </Card>
          <Card v-if="isValidation && ctx?.ai?.status === 'used'" title="AI rule review" :hint="`Findings only: no test is generated from them. ${ctx.ai.model ?? ''}`">
            <p v-if="!ctx.ai.gaps.length" class="text-xs text-slate-500">No missing or weak rules found.</p>
            <ul class="space-y-1.5">
              <li v-for="(g, i) in ctx.ai.gaps" :key="i" class="rounded-lg px-2.5 py-1.5 text-[11.5px]" :class="g.severity === 'high' ? 'bg-red-50 text-red-900' : g.severity === 'medium' ? 'bg-amber-50 text-amber-900' : 'bg-slate-50 text-slate-700'">
                <span class="font-semibold uppercase">{{ g.severity }}</span>
                <span v-if="g.field" class="font-mono"> · {{ g.field }}</span> — {{ g.issue }}
                <span v-if="g.suggestion" class="mt-0.5 block font-mono text-[11px] opacity-80">{{ g.suggestion }}</span>
                <span v-if="g.basis_source" class="block text-[11px] opacity-70">{{ g.basis_source }}</span>
              </li>
            </ul>
            <p v-if="ctx.ai.rejected.length" class="mt-2 text-[11px] text-slate-500">{{ ctx.ai.rejected.length }} AI answer(s) discarded after local checks.</p>
          </Card>
          <Card v-if="isRateLimit && ctx" title="Expected limit" hint="Read from the route middleware and #[RateLimit] — no code is executed to plan.">
            <p class="text-2xl font-bold">{{ ctx.max_attempts }} <span class="text-sm font-medium text-slate-500">requests per {{ ctx.decay_seconds % 60 === 0 ? `${ctx.decay_seconds / 60} min` : `${ctx.decay_seconds} s` }}</span></p>
            <p class="mt-1 text-xs text-slate-500">From {{ ctx.source }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
              <dt class="text-slate-500">Route middleware</dt><dd class="font-mono font-semibold">{{ ctx.route_throttle?.source ?? 'none' }}</dd>
              <dt class="text-slate-500">#[RateLimit]</dt><dd class="font-semibold">{{ ctx.declared?.max_attempts ? `${ctx.declared.max_attempts} / ${ctx.declared.decay_seconds ?? '—'}s` : 'no numbers' }}</dd>
              <dt class="text-slate-500">Counted per</dt><dd class="font-semibold">{{ ctx.keyed_by === 'user' ? 'signed-in user' : 'client IP' }}</dd>
              <dt class="text-slate-500">Request</dt><dd class="break-all font-mono font-semibold">{{ ctx.method }} {{ ctx.uri }}</dd>
            </dl>
            <ul v-if="ctx.warnings.length" class="mt-3 space-y-1.5">
              <li v-for="w in ctx.warnings" :key="w" class="rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11.5px] text-amber-900">{{ w }}</li>
            </ul>
          </Card>
          <Card v-if="deterministic && ctx?.bindings" title="URL parameters" hint="Each test creates these records first, then requests the URL built from them.">
            <p class="break-all font-mono text-xs text-slate-800">{{ ctx.method }} {{ ctx.uri }}</p>
            <ul class="mt-3 space-y-1.5">
              <li v-for="line in ctx.bindings.description" :key="line" class="rounded-lg bg-slate-50 px-2.5 py-1.5 font-mono text-[11.5px] text-slate-700">{{ line }}</li>
            </ul>
            <p v-if="ctx.bindings.parameters.some((p) => p.kind === 'model')" class="mt-2 text-[11px] text-slate-500">Runs with RefreshDatabase. A 404 means a binding did not resolve and is reported as a setup problem, not a bug.</p>
          </Card>
          <Card v-if="isFeature && ctx" title="HTTP setup" hint="Sent to the AI with the action's source. Gathered without running anything.">
            <p class="break-all font-mono text-xs text-slate-800">{{ ctx.method }} {{ ctx.uri }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
              <dt class="text-slate-500">Signed-in user</dt><dd class="font-semibold">{{ ctx.authenticate ? 'Yes (factory user)' : 'No' }}</dd>
              <dt class="text-slate-500">Validation</dt><dd class="break-words font-semibold">{{ ctx.validation ? ctx.validation.origin : 'none found' }}</dd>
              <dt class="text-slate-500">Faked</dt><dd class="font-semibold">{{ ctx.fakes.join(', ') || 'nothing' }}</dd>
            </dl>
            <p class="mt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Models it touches</p>
            <ul class="mt-1.5 space-y-1">
              <li v-for="m in ctx.models" :key="m.class" class="flex items-center gap-2 text-xs">
                <span class="min-w-0 flex-1 truncate font-mono text-slate-700">{{ m.class.split('\\').pop() }} <span class="text-slate-400">({{ m.table }})</span></span>
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="m.factory ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-800'">{{ m.factory ? 'factory' : 'no factory' }}</span>
              </li>
            </ul>
            <ul v-if="ctx.bindings" class="mt-3 space-y-1">
              <li v-for="line in ctx.bindings.description" :key="line" class="rounded bg-slate-50 px-2 py-1 font-mono text-[11px] text-slate-700">{{ line }}</li>
            </ul>
          </Card>
          <Card title="Signature">
            <p class="break-words font-mono text-xs text-slate-700">{{ t.signature }}</p>
            <p class="mt-2 text-xs text-slate-500">Complexity {{ t.complexity }} · modules {{ t.modules.join(', ') || 'none' }}</p>
          </Card>
        </div>

        <Card :title="t.plan ? `${isValidation ? 'Validation cases' : isRateLimit ? 'Rate-limit cases' : 'Test plan'} · ${t.plan.scenarios.length} ${deterministic ? 'cases' : 'scenarios'} (${t.plan.status === 'proposed' ? selection.size : approvedScenarios.length} selected)` : 'Test plan'">
          <template #actions><Badge v-if="t.plan" :status="t.plan.status" /></template>

          <div v-if="t.status === 'planning'" class="flex items-center gap-3 rounded-lg bg-blue-50 p-4 text-sm text-blue-800"><Spinner /> The AI is analysing the code and proposing scenarios…</div>
          <EmptyState v-else-if="!t.plan && isValidation" title="No validation plan yet" text="TestPilot reads the FormRequest's rules() without running it, and turns every rule into a valid and an invalid request. No AI is used and nothing is generated until you approve.">
            <button class="btn-primary" :disabled="busy || !t.enabled" @click="planNow">Plan validation tests</button>
          </EmptyState>
          <EmptyState v-else-if="!t.plan && isRateLimit" title="No rate-limit plan yet" text="TestPilot reads the route's throttle middleware and #[RateLimit] without running anything, then plans: N requests allowed, request N+1 blocked with Retry-After, reset after the window, and per-client isolation. No AI is used.">
            <button class="btn-primary" :disabled="busy || !t.enabled" @click="planNow">Plan rate-limit tests</button>
          </EmptyState>
          <EmptyState v-else-if="!t.plan" title="No plan yet" text="TestPilot sends this method's source (secrets redacted) and its class outline to your AI provider and gets back scenarios to review. No code is generated until you approve.">
            <button class="btn-primary" :disabled="busy || !t.enabled" @click="planNow">Plan with AI</button>
          </EmptyState>
          <div v-else-if="t.plan.status === 'failed'" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <template v-if="t.plan.context?.failure">
              <p class="font-bold">{{ t.plan.context.failure.title }}</p>
              <p class="mt-1">{{ t.plan.context.failure.summary }}</p>
              <p class="mt-1 font-medium">{{ t.plan.context.failure.next_step }}</p>
              <details class="mt-2 text-xs"><summary class="cursor-pointer">Technical details</summary><pre class="mt-1 whitespace-pre-wrap">{{ t.plan.error }}</pre></details>
            </template>
            <template v-else>{{ t.plan.error }}</template>
          </div>

          <template v-else>
            <p v-if="t.plan.notes" class="mb-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ deterministic ? '' : 'AI note: ' }}{{ t.plan.notes }}</p>
            <ValidationGrid
              v-if="isValidation" class="-mx-5"
              :scenarios="t.plan.scenarios" :fields="ctx?.fields ?? []" :results="scenarioResults" :error-mode="ctx?.error_mode" :error-status="ctx?.error_status"
              :selection="t.plan.status === 'proposed' ? selection : null" @toggle="toggle"
            />
            <ul v-else class="space-y-2">
              <li v-for="s in t.plan.scenarios" :key="s.id" class="rounded-lg bg-slate-50 px-3 py-2.5">
                <div class="flex items-center gap-3">
                  <input
                    type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" :aria-label="`Include ${s.id}`"
                    :checked="t.plan.status === 'proposed' ? selection.has(s.id) : s.selected" :disabled="t.plan.status !== 'proposed'" @change="toggle(s.id)"
                  />
                  <span class="w-7 text-xs font-bold text-slate-400">{{ s.id }}</span>
                  <span class="min-w-0 flex-1 text-[13px] font-medium" :class="(t.plan.status === 'proposed' ? selection.has(s.id) : s.selected) ? 'text-slate-900' : 'text-slate-400'">{{ edits[s.id]?.name ?? s.name }}</span>
                  <span class="text-[11px] font-medium uppercase text-slate-400">{{ s.type }}</span>
                  <Badge :status="s.basis" />
                  <span class="w-16 text-right text-xs font-medium text-slate-500">conf {{ Number(s.confidence).toFixed(2) }}</span>
                  <Badge v-if="scenarioResults[s.id]" :status="scenarioResults[s.id]" />
                  <button v-if="t.plan.status === 'proposed'" class="text-xs font-semibold text-brand-600" @click="editing = editing === s.id ? null : s.id">{{ editing === s.id ? 'Done' : 'Edit' }}</button>
                </div>
                <p class="ml-[68px] mt-1 text-xs text-slate-500">Expected: {{ edits[s.id]?.expected ?? s.expected }} <span class="text-slate-400">· {{ s.basis_source }}</span></p>
                <p v-if="s.meta?.todo" class="ml-[68px] mt-1 text-xs text-amber-700">Needs a human: {{ s.meta.todo }}</p>
                <div v-if="editing === s.id" class="ml-[68px] mt-2 grid gap-2">
                  <input class="input" :value="edits[s.id]?.name ?? s.name" aria-label="Scenario name" @input="edits[s.id] = { ...edits[s.id], name: $event.target.value }" />
                  <input class="input" :value="edits[s.id]?.expected ?? s.expected" aria-label="Expected outcome" @input="edits[s.id] = { ...edits[s.id], expected: $event.target.value }" />
                </div>
              </li>
            </ul>

            <template v-if="t.plan.status === 'proposed'">
              <label v-if="!deterministic" class="mt-4 block">
                <span class="label">Add a scenario in plain English (optional)</span>
                <input v-model="addition" class="input mt-1.5 border-dashed" placeholder='e.g. "shipping is free when the total is over ₹500"' />
              </label>
              <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                <p class="min-w-0 flex-1 text-xs text-slate-500">{{ isValidation ? `Every expected result comes from ${ctx?.rules_origin ?? 'rules()'}. Cases marked "Needs a human" are not generated.` : isRateLimit ? 'Every expectation comes from the declared limit. Time is moved with travel(), so tests stay fast.' : 'Code is generated only after you approve. Assumptions stay flagged in the evidence.' }}</p>
                <button class="btn-primary" :disabled="busy || !selection.size" @click="approvePlan">Approve plan &amp; generate</button>
              </div>
            </template>
            <p v-else class="mt-4 text-xs text-slate-500">Approved by {{ t.plan.approved_by }} {{ ago(t.plan.approved_at) }} · {{ deterministic ? 'no AI (deterministic)' : t.plan.model }} · {{ t.plan.prompt_version }}</p>
            <div v-if="t.status === 'generating'" class="mt-4 flex items-center gap-3 rounded-lg bg-blue-50 p-3 text-sm text-blue-800"><Spinner /> Generating, running, repairing and verifying…</div>
            <RouterLink v-if="t.test && t.test.status !== 'generating'" :to="`/targets/${t.id}/evidence`" class="btn-secondary mt-4">See test evidence →</RouterLink>
          </template>
        </Card>
      </div>
    </template>

    <!-- ============ TEST EVIDENCE ============ -->
    <template v-else-if="tab === 'evidence'">
      <EmptyState v-if="!t.test" icon="shield" title="No generated test yet" text="Approve a test plan first. Evidence appears here once the tests have been generated and verified." />
      <template v-else>
        <div class="mb-4 flex items-center gap-4 rounded-xl border px-5 py-4" :class="banners[verdict.tone]">
          <div class="min-w-0 flex-1"><p class="text-base font-bold">{{ verdict.title }}</p><p class="mt-0.5 text-[13px] opacity-90">{{ verdict.text }}</p></div>
          <template v-if="['verified', 'suspected_bug'].includes(t.test.status) && app.canApprove">
            <button class="btn-primary" :disabled="busy" @click="approveTest">Approve test</button>
          </template>
        </div>

        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <div v-for="(p, i) in proofs" :key="p.title" class="card p-4">
            <div class="flex items-center gap-2"><p class="flex-1 text-xs font-semibold text-slate-500">{{ i + 1 }} · {{ p.title }}</p>
              <span class="rounded-full px-2 text-[11px] font-bold" :class="p.ok === null ? 'bg-slate-100 text-slate-500' : p.ok ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">{{ p.ok === null ? 'Planned' : p.ok ? (p.title === 'Expectations' ? '✓ Source-backed' : '✓ Passed') : '! Gap' }}</span>
            </div>
            <p class="mt-2 text-lg font-bold leading-snug">{{ p.value }}</p>
            <p class="mt-0.5 text-xs font-medium" :class="p.ok === false ? 'text-amber-700' : 'text-slate-500'">{{ p.caption }}</p>
            <p class="mt-1 text-[11px] text-slate-400">{{ p.how }}</p>
          </div>
        </div>

        <div v-if="isValidation" class="grid gap-5 xl:grid-cols-[1fr_380px]">
          <Card title="Rule → request → result" hint="Each row is one HTTP request sent to the real route. Open a row to see its Pest test." :padded="false">
            <ValidationGrid :scenarios="t.test.scenarios ?? t.plan?.scenarios ?? []" :fields="ctx?.fields ?? []" :results="scenarioResults" :code="t.test.code" :error-mode="ctx?.error_mode" :error-status="ctx?.error_status" show-results />
          </Card>
          <Card title="Valid payload" hint="Built from every rule. Each case changes exactly one field from this.">
            <CodeBlock :code="JSON.stringify(ctx?.baseline ?? {}, null, 2)" :numbered="false" wrap max-height="28rem" />
          </Card>
        </div>
        <div v-else class="grid gap-5 xl:grid-cols-[1fr_480px]">
          <Card v-if="isRateLimit && ctx" class="xl:self-start" title="What the tests send" :hint="`${ctx.method} ${ctx.uri} · expected ${ctx.max_attempts} per ${ctx.decay_seconds}s (${ctx.source})`">
            <RateLimitTimeline :max="ctx.max_attempts" :decay="ctx.decay_seconds" :results="scenarioResults" :scenarios="t.test.scenarios ?? t.plan.scenarios" :keyed-by="ctx.keyed_by" />
          </Card>
          <Card v-else class="xl:self-start" title="Source under test" :hint="isFeature ? 'Each scenario cites the line its expectation comes from.' : Object.keys(sourceMarkers).length ? 'Red lines: TestPilot injected a bug there and no test noticed.' : 'Mutation details per line appear here when mutation testing runs.'">
            <CodeBlock :code="t.source" :start-line="t.line" :markers="sourceMarkers" />
          </Card>
          <Card title="Scenario → Pest test → proof" hint="Each scenario links to the exact assertion that proves it.">
            <div class="space-y-3">
              <div v-for="s in approvedScenarios" :key="s.id" class="rounded-lg border border-slate-200 p-3">
                <div class="flex items-center gap-2">
                  <p class="min-w-0 flex-1 text-[13px] font-semibold">{{ s.id }} · {{ s.name }}</p>
                  <Badge :status="s.basis" /><Badge :status="scenarioResults[s.id] ?? 'queued'" :label="scenarioResults[s.id] ? undefined : 'Not run'" />
                </div>
                <CodeBlock v-if="scenarioBlock(t.test.code, s.id)" class="mt-2" :code="scenarioBlock(t.test.code, s.id)" :numbered="false" wrap max-height="14rem" />
                <p class="mt-2 text-[11px] text-slate-500">Expected value from: {{ s.basis_source }}</p>
              </div>
            </div>
          </Card>
        </div>

        <Card class="mt-5" title="Verification stages" hint="The unedited record of what TestPilot did, in order.">
          <ol class="space-y-2">
            <li v-for="(s, i) in t.test.verification?.stages ?? []" :key="i" class="rounded-lg bg-slate-50 px-3 py-2">
              <div class="flex items-center gap-3"><span class="w-20 text-xs font-bold uppercase text-slate-500">{{ s.stage }}</span><Badge :status="s.status" /><span class="text-[13px] text-slate-700">{{ s.summary }}</span></div>
              <pre v-if="s.data?.command || s.data?.failure || s.data?.error" class="mt-2 overflow-x-auto whitespace-pre-wrap rounded bg-slate-900 p-2.5 font-mono text-[11.5px] text-slate-200">{{ s.data.command ?? s.data.failure ?? s.data.error }}</pre>
            </li>
          </ol>
        </Card>
      </template>
    </template>

    <!-- ============ GENERATED CODE ============ -->
    <template v-else-if="tab === 'code'">
      <EmptyState v-if="!t.test?.code" icon="file" title="No generated code yet" />
      <Card v-else :title="t.test.approved_path ?? t.test.pending_path ?? 'Generated test'" :hint="isRateLimit ? `Built from the declared limit without AI · ${ago(t.test.created_at)}` : isValidation ? `Built from rules() without AI · ${ago(t.test.created_at)}` : `Model ${t.test.model} · ${t.test.repair_attempts} repair attempt(s) · ${ago(t.test.created_at)}`">
        <template #actions><Badge :status="t.test.status" /></template>
        <CodeBlock :code="t.test.code" max-height="40rem" />
        <div v-if="['verified', 'suspected_bug', 'failed', 'unsafe'].includes(t.test.status) && app.canApprove" class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-4">
          <input v-model="rejectReason" class="input max-w-md" placeholder="Reason for rejecting (required to reject)" />
          <button class="btn-danger" :disabled="busy || rejectReason.trim().length < 3" @click="rejectTest">Reject</button>
          <span class="flex-1" />
          <button v-if="['verified', 'suspected_bug'].includes(t.test.status)" class="btn-primary" :disabled="busy" @click="approveTest">Approve &amp; add to tests/</button>
        </div>
      </Card>
    </template>

    <!-- ============ SETTINGS TRACE ============ -->
    <template v-else>
      <Card title="Where every setting comes from" hint="Project config → class annotations → method annotations → policy limits (policy always wins)." :padded="false">
        <table class="w-full">
          <thead class="bg-slate-50"><tr><th class="th pl-5">Setting</th><th class="th">Value</th><th class="th pr-5">Source</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in traceRows" :key="i" class="border-t border-slate-100">
              <td class="td pl-5 font-semibold">{{ row.key }}</td>
              <td class="td font-mono text-brand-600">{{ JSON.stringify(row.value) }}</td>
              <td class="td pr-5 text-slate-500">{{ row.source }}</td>
            </tr>
          </tbody>
        </table>
      </Card>
    </template>
  </template>
</template>
