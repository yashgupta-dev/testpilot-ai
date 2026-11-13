<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import Badge from '../components/Badge.vue'
import Card from '../components/Card.vue'
import Icon from '../components/Icon.vue'
import FieldSource from '../components/FieldSource.vue'
import PageHeader from '../components/PageHeader.vue'
import { api, app } from '../lib/api.js'
import { ago } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const props = defineProps({ section: { type: String, default: 'general' } })
const router = useRouter()
const shell = inject('session', null)

const sections = [
  { id: 'general', label: 'General', icon: 'overview', hint: 'Environment and discovery' },
  { id: 'ai', label: 'AI', icon: 'sparkles', hint: 'Provider, models, caching, budgets' },
  { id: 'security', label: 'Security', icon: 'lock', hint: 'Passcode and access' },
  { id: 'integrations', label: 'Integrations', icon: 'plug', hint: 'GitHub, GitLab, Slack…' },
  { id: 'limits', label: 'Policy limits', icon: 'shield', hint: 'Hard limits set in code' },
]
const current = computed(() => (sections.some((s) => s.id === props.section) ? props.section : 'general'))

const env = ref(null)
const settings = ref(null)
const draft = ref({})
const replacingKey = ref(false)
const saving = ref(false)
const testing = ref(null)

async function load() {
  ;[env.value, settings.value] = await Promise.all([api('/environment'), api('/settings')])
  draft.value = {}
  replacingKey.value = false
}
onMounted(load)

// ---- AI form ------------------------------------------------------------------------------
const f = (key) => settings.value?.fields[key]
const canEdit = computed(() => !!settings.value?.can_manage && !!settings.value?.editable)
const value = (key) => (key in draft.value ? draft.value[key] : f(key)?.value)
function set(key, v) {
  const original = f(key)?.value
  const next = { ...draft.value }
  if (v === original || (v === '' && original === null)) delete next[key]
  else next[key] = v
  draft.value = next
}
const dirty = computed(() => Object.keys(draft.value).length)
const disabled = (key) => !canEdit.value || f(key)?.locked

async function save(values = draft.value) {
  saving.value = true
  try {
    const r = await api('/settings', { method: 'PUT', body: { values } })
    settings.value = { ...settings.value, fields: r.fields }
    draft.value = {}
    replacingKey.value = false
    toast(r.changed.length ? `Saved ${r.changed.length} setting(s). New AI calls use them right away.` : 'Nothing changed.')
  } catch (e) { toast(e.message, 'error') } finally { saving.value = false }
}
const reset = (key) => save({ [key]: '__default' })

async function testConnection() {
  testing.value = { busy: true }
  try {
    const r = await api('/settings/ai/test', { method: 'POST' })
    testing.value = { ok: true, text: `Connected to ${r.model} in ${r.latency_ms} ms.` }
  } catch (e) { testing.value = { ok: false, text: e.message } }
}

const claudeModels = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-2']
const routing = [
  ['ai.models.plan', 'Reasoning about intent and edge cases'],
  ['ai.models.generate', 'Writing the Pest file'],
  ['ai.models.repair', 'First repair: imports, mocks, typos'],
  ['ai.models.repair_escalation', 'When the cheap fix did not work'],
  ['ai.models.validation', 'Regex / custom-rule values and rule-gap review (empty = Plan)'],
  ['ai.models.critical', "Targets with criticality: 'high' (plan + generate + validation)"],
]
const policyHelp = { cloud: 'Cloud providers allowed. Secrets are redacted before anything is sent.', local: 'Only on-premise / localhost models (e.g. Ollama). Code never leaves your network.', off: 'No AI calls at all. Validation and rate-limit tests still work.' }
const cacheHelp = { '5m': 'Cache writes cost 1.25× input; reads ~10% or less. Best for interactive work.', '1h': 'Writes cost 2× input. Better when plans are approved later in the same hour.', off: 'Every call pays full input price.' }

// ---- Security -----------------------------------------------------------------------------
const pass = ref({ current: '', next: '', confirm: '' })
const passBusy = ref(false)
const passError = computed(() => {
  if (!pass.value.next) return ''
  if (pass.value.next.length < 6) return 'Use at least 6 characters.'
  if (pass.value.confirm && pass.value.confirm !== pass.value.next) return 'The two passcodes do not match.'
  return ''
})
async function savePasscode(remove = false) {
  passBusy.value = true
  try {
    const r = await api('/settings/passcode', { method: 'PUT', body: remove ? { current: pass.value.current, remove: true } : { current: pass.value.current || null, passcode: pass.value.next } })
    settings.value = { ...settings.value, passcode: r.passcode }
    pass.value = { current: '', next: '', confirm: '' }
    shell?.reload()
    toast(remove ? 'Passcode removed. The dashboard opens without one.' : 'Passcode saved. Other browsers must enter it to continue.')
  } catch (e) { toast(e.message, 'error') } finally { passBusy.value = false }
}

// ---- Integrations -------------------------------------------------------------------------
const integrations = [
  { name: 'GitHub', mark: 'GH', tone: 'bg-slate-900 text-white', what: 'Pull-request comments with results, a TestPilot status check, and a merge gate for failing or stale tests.', eta: 'Next release' },
  { name: 'GitLab', mark: 'GL', tone: 'bg-orange-600 text-white', what: 'Merge-request notes, pipeline gate and MR status, for gitlab.com and self-managed GitLab.', eta: 'Next release' },
  { name: 'Bitbucket', mark: 'BB', tone: 'bg-blue-600 text-white', what: 'Pull-request comments and build status for Bitbucket Cloud and Data Center.', eta: 'Planned' },
  { name: 'Slack', mark: 'SL', tone: 'bg-purple-700 text-white', what: 'Alerts for failing runs, suspected bugs and AI budget warnings, per channel.', eta: 'Planned' },
  { name: 'Microsoft Teams', mark: 'MT', tone: 'bg-indigo-600 text-white', what: 'The same alerts as Slack, for Teams channels.', eta: 'Planned' },
  { name: 'Jira', mark: 'JI', tone: 'bg-sky-600 text-white', what: 'Link requirements to issues, and file a bug from a suspected bug in one click.', eta: 'Planned' },
]

const row = (label, v, ok = null) => ({ label, value: v, ok })
watch(() => props.section, () => { draft.value = {} })
</script>

<template>
  <PageHeader :crumbs="[{ label: app.project }, { label: 'Settings' }, { label: sections.find((s) => s.id === current)?.label }]" title="Settings" subtitle="AI settings and the passcode can be changed here; anything set in .env stays locked. Policy limits always live in code." />

  <div v-if="env && settings" class="grid gap-6 lg:grid-cols-[220px_1fr]">
    <nav class="space-y-1" aria-label="Settings sections">
      <RouterLink
        v-for="s in sections" :key="s.id" :to="`/settings/${s.id}`"
        class="flex items-start gap-2.5 rounded-lg px-3 py-2.5"
        :class="current === s.id ? 'bg-white font-semibold text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:bg-white/60'"
      >
        <Icon :name="s.icon" :size="17" :class="current === s.id ? 'mt-0.5 text-brand-600' : 'mt-0.5 text-slate-400'" />
        <span class="min-w-0"><span class="block text-[13.5px]">{{ s.label }}</span><span class="block text-[11.5px] font-normal text-slate-400">{{ s.hint }}</span></span>
        <span v-if="s.id === 'integrations'" class="ml-auto mt-0.5 rounded-full bg-slate-100 px-1.5 text-[10px] font-semibold uppercase text-slate-500">Soon</span>
      </RouterLink>
    </nav>

    <div class="min-w-0">
      <!-- ============ GENERAL ============ -->
      <div v-if="current === 'general'" class="grid gap-4 xl:grid-cols-2">
        <Card title="Execution environment" hint="Tests never run in blocked environments.">
          <dl class="divide-y divide-slate-100">
            <div v-for="r in [row('Application environment', env.app_env, env.tests_allowed), row('Database', env.database), row('Queue', env.queue, !env.queue_is_sync), row('PHP / Laravel', `${env.php} / ${env.laravel}`), row('Pest', env.pest_installed ? 'Installed' : 'Not found', env.pest_installed), row('Coverage driver', env.coverage_driver ?? 'None (mutation testing skipped)', !!env.coverage_driver)]" :key="r.label" class="flex items-center justify-between py-2.5 text-sm">
              <dt class="text-slate-500">{{ r.label }}</dt>
              <dd class="font-medium"><Badge v-if="r.ok !== null" :status="r.ok ? 'passed' : 'warning'" :label="r.value" /><span v-else>{{ r.value }}</span></dd>
            </div>
          </dl>
        </Card>
        <Card title="Test database" hint="Read from the project PHPUnit configuration; TestPilot does not connect to or migrate it here.">
          <dl class="divide-y divide-slate-100">
            <div class="flex items-center justify-between py-2.5 text-sm">
              <dt class="text-slate-500">Configuration</dt>
              <dd class="font-medium">{{ env.test_database.config_file ?? 'Not found' }}</dd>
            </div>
            <div class="flex items-center justify-between py-2.5 text-sm">
              <dt class="text-slate-500">Driver</dt>
              <dd class="font-medium">{{ env.test_database.driver ?? 'Not explicitly configured' }}</dd>
            </div>
            <div class="flex items-center justify-between py-2.5 text-sm">
              <dt class="text-slate-500">Database</dt>
              <dd class="font-medium">{{ env.test_database.database ?? 'Not explicitly configured' }}</dd>
            </div>
            <div class="flex items-center justify-between py-2.5 text-sm">
              <dt class="text-slate-500">Isolation</dt>
              <dd><Badge :status="env.test_database.isolation === 'isolated' ? 'passed' : 'warning'" :label="env.test_database.isolation === 'isolated' ? 'Appears test-only' : 'Review configuration'" /></dd>
            </div>
          </dl>
          <p class="mt-3 text-xs text-slate-600">{{ env.test_database.detail }}</p>
          <p class="mt-2 text-xs text-slate-600">{{ env.test_database.action }}</p>
          <p class="mt-2 text-xs font-medium text-slate-500">Schema status: checked only when the tests run.</p>
        </Card>
        <Card title="Discovery & output">
          <dl class="divide-y divide-slate-100">
            <div class="flex items-center justify-between py-2.5 text-sm"><dt class="text-slate-500">Scanned paths</dt><dd class="font-mono font-medium">{{ env.paths.join(', ') }}</dd></div>
            <div class="flex items-center justify-between py-2.5 text-sm"><dt class="text-slate-500">Approved tests go to</dt><dd class="font-mono font-medium">{{ env.generated_path }}</dd></div>
            <div class="flex items-center justify-between py-2.5 text-sm"><dt class="text-slate-500">Mutation testing</dt><dd class="font-medium">{{ env.mutation_enabled ? 'Enabled' : 'Disabled' }}</dd></div>
            <div class="flex items-center justify-between py-2.5 text-sm"><dt class="text-slate-500">AI provider</dt><dd class="font-medium">{{ env.ai_driver }} · <RouterLink to="/settings/ai" class="text-brand-600">change</RouterLink></dd></div>
          </dl>
        </Card>
      </div>

      <!-- ============ AI ============ -->
      <div v-else-if="current === 'ai'" class="space-y-4 pb-20">
        <p v-if="!canEdit" class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
          <Icon name="lock" :size="14" class="mr-1 inline" />
          {{ settings.editable ? 'You can view these settings. Ask an admin to change them.' : 'Editing from the dashboard is turned off (TESTPILOT_SETTINGS_EDITABLE=false). Change these in .env.' }}
        </p>

        <Card title="Provider" hint="Which AI TestPilot calls, and what it may send.">
          <template #actions>
            <button class="btn-secondary" :disabled="testing?.busy || dirty > 0" :title="dirty ? 'Save first' : ''" @click="testConnection">{{ testing?.busy ? 'Testing…' : 'Test connection' }}</button>
          </template>
          <p v-if="testing && !testing.busy" class="mb-4 rounded-lg px-3 py-2 text-sm" :class="testing.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">{{ testing.text }}</p>
          <div class="grid gap-5 md:grid-cols-2">
            <div v-for="key in ['ai.driver', 'ai.model']" :key="key">
              <label class="label" :for="key">{{ f(key).label }}</label>
              <select v-if="f(key).type === 'enum'" :id="key" class="input mt-1.5" :value="value(key)" :disabled="disabled(key)" @change="set(key, $event.target.value)">
                <option v-for="o in f(key).options" :key="o" :value="o">{{ { anthropic: 'Anthropic (Claude)', openai: 'OpenAI', groq: 'Groq (free tier)', openrouter: 'OpenRouter (free :free models)', gemini: 'Google Gemini (free tier)', ollama: 'Ollama (local, free)', custom: 'OpenAI-compatible' }[o] ?? o }}</option>
              </select>
              <template v-else>
                <input :id="key" class="input mt-1.5 font-mono" list="claude-models" :value="value(key)" :disabled="disabled(key)" placeholder="provider default" @input="set(key, $event.target.value)" />
              </template>
              <FieldSource :field="f(key)" :can-edit="canEdit" @reset="reset(key)" />
            </div>

            <div class="md:col-span-2">
              <span class="label block">{{ f('ai.api_key').label }}</span>
              <div v-if="!replacingKey" class="mt-1.5 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                <Icon name="lock" :size="15" class="text-slate-400" />
                <span class="flex-1 font-mono text-sm">{{ f('ai.api_key').set ? f('ai.api_key').hint ?? 'Set' : 'Not set' }}</span>
                <Badge :status="f('ai.api_key').set ? 'passed' : 'failed'" :label="f('ai.api_key').set ? 'Set' : 'Missing'" />
                <button v-if="!disabled('ai.api_key')" class="text-sm font-semibold text-brand-600" @click="replacingKey = true">{{ f('ai.api_key').set ? 'Replace' : 'Add key' }}</button>
              </div>
              <input v-else class="input mt-1.5 font-mono" type="password" autocomplete="off" placeholder="sk-ant-…" @input="set('ai.api_key', $event.target.value)" />
              <p class="mt-1 text-[11.5px] text-slate-500">Stored encrypted with your APP_KEY and never shown again. <FieldSource inline :field="f('ai.api_key')" :can-edit="false" /></p>
            </div>

            <div v-if="['groq', 'openrouter', 'gemini', 'ollama'].includes(value('ai.driver'))" class="md:col-span-2 rounded-lg bg-emerald-50 px-3 py-2.5 text-[12px] text-emerald-900">
              <p class="font-semibold">Free option</p>
              <p v-if="value('ai.driver') === 'groq'">Get a free key at console.groq.com. Default model: llama-3.3-70b-versatile. Limited requests per minute.</p>
              <p v-else-if="value('ai.driver') === 'openrouter'">Get a free key at openrouter.ai and use a model ending in <span class="font-mono">:free</span> (default google/gemma-4-31b-it:free).</p>
              <p v-else-if="value('ai.driver') === 'gemini'">Get a free key at aistudio.google.com. Default model: gemini-3.8-flash.</p>
              <p v-else>Runs on your machine, no key. Run <span class="font-mono">ollama pull qwen2.5-coder:7b</span> and set the data policy to local.</p>
              <p class="mt-1 text-emerald-800">Free models follow the JSON format less reliably than Claude; TestPilot validates every answer and retries once.</p>
              <label class="mt-2 flex items-center gap-2">
                <input type="checkbox" class="h-4 w-4 rounded border-slate-300" :checked="!!value('ai.free_tier')" :disabled="disabled('ai.free_tier')" @change="set('ai.free_tier', $event.target.checked)" />
                <span>{{ f('ai.free_tier')?.label ?? 'Free-tier key' }}</span>
              </label>
            </div>

            <div v-if="['custom', 'ollama', 'openai', 'gemini', 'groq', 'openrouter'].includes(value('ai.driver'))" class="md:col-span-2">
              <label class="label" for="base-url">{{ f('ai.base_url').label }}</label>
              <input id="base-url" class="input mt-1.5 font-mono" :value="value('ai.base_url')" :disabled="disabled('ai.base_url')" placeholder="https://…" @input="set('ai.base_url', $event.target.value)" />
              <FieldSource :field="f('ai.base_url')" :can-edit="canEdit" @reset="reset('ai.base_url')" />
            </div>

            <div class="md:col-span-2">
              <span class="label block">{{ f('ai.policy').label }}</span>
              <div class="mt-1.5 inline-flex rounded-lg border border-slate-200 bg-white p-0.5" role="radiogroup">
                <button v-for="o in f('ai.policy').options" :key="o" role="radio" :aria-checked="value('ai.policy') === o" class="rounded-md px-3 py-1.5 text-[13px] font-semibold capitalize" :class="value('ai.policy') === o ? 'bg-brand-600 text-white' : 'text-slate-600'" :disabled="disabled('ai.policy')" @click="set('ai.policy', o)">{{ o }}</button>
              </div>
              <p class="mt-1 text-[11.5px] text-slate-500">{{ policyHelp[value('ai.policy')] }}</p>
              <FieldSource :field="f('ai.policy')" :can-edit="canEdit" @reset="reset('ai.policy')" />
            </div>
          </div>
        </Card>

        <Card title="Model routing" hint="Each step uses the cheapest model that does it well. Empty = default model.">
          <div class="divide-y divide-slate-100">
            <div v-for="[key, why] in routing" :key="key" class="grid items-center gap-3 py-3 md:grid-cols-[180px_1fr_260px]">
              <div><p class="text-[13px] font-semibold">{{ f(key).label }}</p><p class="text-[11.5px] text-slate-500">{{ why }}</p></div>
              <input class="input font-mono" list="claude-models" :value="value(key)" :disabled="disabled(key)" :placeholder="`default (${value('ai.model') || 'claude-sonnet-5-5'})`" :aria-label="f(key).label" @input="set(key, $event.target.value)" />
              <FieldSource :field="f(key)" :can-edit="canEdit" @reset="reset(key)" />
            </div>
          </div>
          <datalist id="claude-models"><option v-for="m in claudeModels" :key="m" :value="m" /></datalist>
        </Card>

        <div class="grid gap-4 xl:grid-cols-2">
          <Card title="Caching" hint="Pay once for what repeats.">
            <span class="label block">{{ f('ai.prompt_cache').label }}</span>
            <div class="mt-1.5 inline-flex rounded-lg border border-slate-200 bg-white p-0.5" role="radiogroup">
              <button v-for="o in f('ai.prompt_cache').options" :key="o" role="radio" :aria-checked="value('ai.prompt_cache') === o" class="rounded-md px-3 py-1.5 text-[13px] font-semibold" :class="value('ai.prompt_cache') === o ? 'bg-brand-600 text-white' : 'text-slate-600'" :disabled="disabled('ai.prompt_cache')" @click="set('ai.prompt_cache', o)">{{ { '5m': '5 minutes', '1h': '1 hour', off: 'Off' }[o] }}</button>
            </div>
            <p class="mt-1 text-[11.5px] text-slate-500">{{ cacheHelp[value('ai.prompt_cache')] }}</p>
            <FieldSource :field="f('ai.prompt_cache')" :can-edit="canEdit" @reset="reset('ai.prompt_cache')" />

            <div class="mt-5 flex items-center gap-3">
              <button role="switch" :aria-checked="!!value('ai.response_cache.enabled')" class="relative h-6 w-11 rounded-full transition" :class="value('ai.response_cache.enabled') ? 'bg-brand-600' : 'bg-slate-300'" :disabled="disabled('ai.response_cache.enabled')" aria-label="Response cache" @click="set('ai.response_cache.enabled', !value('ai.response_cache.enabled'))">
                <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition" :class="value('ai.response_cache.enabled') ? 'left-[22px]' : 'left-0.5'" />
              </button>
              <div class="flex-1"><p class="text-[13px] font-semibold">Response cache</p><p class="text-[11.5px] text-slate-500">Unchanged code + prompt replays the last answer for free. "Plan again" always asks fresh.</p></div>
            </div>
            <label class="mt-3 flex items-center gap-3 text-[13px]">
              <span class="text-slate-500">Keep answers for</span>
              <input type="number" class="input w-24" :min="f('ai.response_cache.ttl_days').min" :max="f('ai.response_cache.ttl_days').max" :value="value('ai.response_cache.ttl_days')" :disabled="disabled('ai.response_cache.ttl_days') || !value('ai.response_cache.enabled')" @input="set('ai.response_cache.ttl_days', Number($event.target.value))" />
              <span class="text-slate-500">days</span>
            </label>
          </Card>

          <Card title="Budgets" hint="TestPilot stops calling the AI when a budget is used up.">
            <div class="grid gap-4 sm:grid-cols-2">
              <label v-for="key in ['ai.monthly_budget', 'ai.target_monthly_budget']" :key="key" class="block">
                <span class="label">{{ key === 'ai.monthly_budget' ? 'Monthly (USD)' : 'Per target / month (USD)' }}</span>
                <div class="relative mt-1.5"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">$</span>
                  <input type="number" step="1" min="0" class="input pl-7" :value="value(key)" :disabled="disabled(key)" @input="set(key, Number($event.target.value))" />
                </div>
                <FieldSource :field="f(key)" :can-edit="canEdit" @reset="reset(key)" />
              </label>
            </div>
            <label class="mt-4 block">
              <span class="label">Warn at {{ Math.round((value('ai.warn_at') ?? 0.8) * 100) }}% of the monthly budget</span>
              <input type="range" min="0.1" max="1" step="0.05" class="mt-2 w-full accent-brand-600" :value="value('ai.warn_at')" :disabled="disabled('ai.warn_at')" @input="set('ai.warn_at', Number($event.target.value))" />
              <p class="text-[11.5px] text-slate-500">Fires the AiBudgetWarning event once per month — hook it to Slack or email. "0" means no limit for the budgets above.</p>
            </label>
            <RouterLink to="/ai" class="mt-3 inline-block text-sm font-semibold text-brand-600">See spending →</RouterLink>
          </Card>
        </div>

        <div v-if="dirty" class="sticky bottom-4 z-10 flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-lg">
          <span class="h-2 w-2 rounded-full bg-amber-500" /><p class="flex-1 text-sm font-medium">{{ dirty }} unsaved change{{ dirty > 1 ? 's' : '' }}</p>
          <button class="btn-secondary" :disabled="saving" @click="draft = {}; replacingKey = false">Discard</button>
          <button class="btn-primary" :disabled="saving" @click="save()">{{ saving ? 'Saving…' : 'Save changes' }}</button>
        </div>
      </div>

      <!-- ============ SECURITY ============ -->
      <div v-else-if="current === 'security'" class="grid gap-4 xl:grid-cols-[1fr_360px]">
        <Card title="Dashboard passcode" hint="Asked before the dashboard opens, on top of your app's login and the auth callback.">
          <template #actions><Badge :status="settings.passcode.enabled ? 'passed' : 'queued'" :label="settings.passcode.enabled ? 'On' : 'Off'" /></template>
          <p v-if="settings.passcode.locked_by_env" class="rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600"><Icon name="lock" :size="14" class="mr-1 inline" /> Set in .env (TESTPILOT_DASHBOARD_PASSCODE). Change or remove it there.</p>
          <template v-else>
            <p v-if="settings.passcode.enabled" class="mb-4 text-sm text-slate-500">Set by {{ settings.passcode.updated_by }} {{ ago(settings.passcode.updated_at) }}. Changing it signs out every other browser.</p>
            <p v-else class="mb-4 text-sm text-slate-500">No passcode yet. Anyone who passes your app's login and the TestPilot auth callback can open the dashboard.</p>
            <form class="grid max-w-md gap-3" @submit.prevent="savePasscode(false)">
              <label v-if="settings.passcode.enabled" class="block"><span class="label">Current passcode</span><input v-model="pass.current" type="password" autocomplete="current-password" class="input mt-1.5" :disabled="!canEdit" /></label>
              <label class="block"><span class="label">{{ settings.passcode.enabled ? 'New passcode' : 'Passcode' }}</span><input v-model="pass.next" type="password" autocomplete="new-password" class="input mt-1.5" :disabled="!canEdit" /></label>
              <label class="block"><span class="label">Repeat it</span><input v-model="pass.confirm" type="password" autocomplete="new-password" class="input mt-1.5" :disabled="!canEdit" /></label>
              <p class="min-h-[1rem] text-xs font-medium text-red-600">{{ passError }}</p>
              <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primary" :disabled="!canEdit || passBusy || !pass.next || pass.next !== pass.confirm || !!passError || (settings.passcode.enabled && !pass.current)">{{ settings.passcode.enabled ? 'Change passcode' : 'Turn on passcode' }}</button>
                <button v-if="settings.passcode.enabled" type="button" class="btn-danger" :disabled="!canEdit || passBusy || !pass.current" @click="savePasscode(true)">Remove passcode</button>
              </div>
            </form>
          </template>
          <dl class="mt-5 grid grid-cols-[1fr_auto] gap-y-2 border-t border-slate-100 pt-4 text-[13px]">
            <dt class="text-slate-500">Stay unlocked for</dt><dd class="font-semibold">{{ Math.round(settings.passcode.unlock_minutes / 60 * 10) / 10 }} hours per browser</dd>
            <dt class="text-slate-500">Wrong guesses</dt><dd class="font-semibold">5 per minute, then blocked · each one is audited</dd>
            <dt class="text-slate-500">Stored as</dt><dd class="font-semibold">bcrypt hash (never readable)</dd>
          </dl>
          <button v-if="settings.passcode.enabled" class="btn-secondary mt-4" @click="shell?.lockNow()"><Icon name="lock" :size="14" /> Lock this browser now</button>
        </Card>

        <Card title="Who can do what" hint="Set in AppServiceProvider with TestPilotDashboard::auth(), approveUsing() and manageSettingsUsing().">
          <dl class="divide-y divide-slate-100 text-[13px]">
            <div class="flex items-center justify-between py-2.5"><dt class="text-slate-500">Open the dashboard</dt><dd><Badge status="passed" label="You" /></dd></div>
            <div class="flex items-center justify-between py-2.5"><dt class="text-slate-500">Approve / reject tests</dt><dd><Badge :status="settings.access.approver ? 'passed' : 'queued'" :label="settings.access.approver ? 'You' : 'Not you'" /></dd></div>
            <div class="flex items-center justify-between py-2.5"><dt class="text-slate-500">Change settings & passcode</dt><dd><Badge :status="settings.can_manage ? 'passed' : 'queued'" :label="settings.can_manage ? 'You' : 'Not you'" /></dd></div>
            <div class="flex items-center justify-between py-2.5"><dt class="text-slate-500">Environment</dt><dd class="font-semibold capitalize">{{ settings.access.environment }}</dd></div>
          </dl>
          <p class="mt-3 text-[11.5px] text-slate-500">The dashboard never loads in production unless you allow it, and tests never run there. Every approval, settings change and unlock is in the audit log.</p>
        </Card>
      </div>

      <!-- ============ INTEGRATIONS ============ -->
      <div v-else-if="current === 'integrations'">
        <p class="mb-4 text-sm text-slate-500">Connect TestPilot to where your team already works. These are on the roadmap; until then use <code class="rounded bg-slate-100 px-1 font-mono text-xs">php artisan testpilot:run --ci</code> in any pipeline.</p>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <div v-for="i in integrations" :key="i.name" class="card card-pad flex flex-col" aria-disabled="true">
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 items-center justify-center rounded-lg text-sm font-bold opacity-80" :class="i.tone" aria-hidden="true">{{ i.mark }}</span>
              <p class="flex-1 font-semibold">{{ i.name }}</p>
              <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="i.eta === 'Next release' ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-500'">{{ i.eta === 'Next release' ? 'Coming next' : 'Coming soon' }}</span>
            </div>
            <p class="mt-3 flex-1 text-[13px] text-slate-600">{{ i.what }}</p>
            <button class="btn-secondary mt-4 cursor-not-allowed justify-center opacity-60" disabled :title="`${i.name} integration is not available yet`">Connect</button>
          </div>
        </div>
      </div>

      <!-- ============ LIMITS ============ -->
      <Card v-else title="Policy limits" hint="Annotations and AI output can never exceed these. Change them in config/testpilot.php — deliberately not from the browser.">
        <dl class="divide-y divide-slate-100">
          <div v-for="(v, key) in env.limits" :key="key" class="flex items-center justify-between py-2.5 text-sm">
            <dt class="text-slate-500">{{ String(key).replaceAll('_', ' ') }}</dt>
            <dd class="font-mono font-medium">{{ Array.isArray(v) ? v.join(', ') : String(v) }}</dd>
          </div>
        </dl>
      </Card>
    </div>
  </div>
</template>
