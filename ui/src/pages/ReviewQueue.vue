<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Badge from '../components/Badge.vue'
import CodeBlock from '../components/CodeBlock.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import { api, app } from '../lib/api.js'
import { ago } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const items = ref(null)
const filter = ref('review')
const open = ref(null)
const reasons = ref({})
const busy = ref(null)

async function load() {
  items.value = (await api('/review')).data
}

const shown = computed(() => (items.value ?? []).filter((t) => {
  if (filter.value === 'review') return ['verified', 'suspected_bug'].includes(t.status)
  if (filter.value === 'problems') return ['failed', 'unsafe'].includes(t.status)
  return true
}))

async function decide(test, action) {
  busy.value = test.id
  try {
    await api(`/tests/${test.id}/${action}`, { method: 'POST', body: action === 'reject' ? { reason: reasons.value[test.id] } : undefined })
    toast(action === 'approve' ? `Approved ${test.target?.name}. It now runs with your suite.` : `Rejected ${test.target?.name}.`)
    await load()
  } catch (e) {
    toast(e.message, 'error')
  } finally {
    busy.value = null
  }
}

const stageTone = { passed: 'bg-green-100 text-green-700', failed: 'bg-red-100 text-red-700', warning: 'bg-amber-100 text-amber-700', skipped: 'bg-slate-100 text-slate-500' }
onMounted(load)
</script>

<template>
  <PageHeader :crumbs="[{ label: app.project }, { label: 'Review queue' }]" title="Review queue" subtitle="Generated tests that passed verification. Nothing enters your test suite until someone approves it here." />

  <div class="mb-4 flex gap-2">
    <button v-for="[value, label] in [['review', 'Awaiting review'], ['problems', 'Failed or unsafe'], ['all', 'All']]" :key="value" class="chip" :class="filter === value ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700'" @click="filter = value">{{ label }}</button>
  </div>

  <section v-if="items && !shown.length" class="card"><EmptyState icon="review" title="Nothing waiting for review" text="Approve a test plan on a target; verified tests appear here." /></section>

  <div class="space-y-3">
    <article v-for="test in shown" :key="test.id" class="card p-5">
      <div class="flex flex-wrap items-start gap-4">
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2">
            <Badge :status="test.status" />
            <span class="text-xs text-slate-500">{{ ago(test.created_at) }} · {{ test.model }} · {{ test.repair_attempts }} repair(s)</span>
          </div>
          <RouterLink :to="`/targets/${test.target_id}/evidence`" class="mt-1.5 block text-[15px] font-semibold hover:text-brand-600">{{ test.target?.name }}</RouterLink>
          <p class="text-xs text-slate-500">{{ test.target?.file }}:{{ test.target?.line }} · {{ test.scenarios.filter((s) => s.selected).length }} scenarios · {{ test.scenarios.filter((s) => s.selected && s.basis === 'assumption').length }} assumption(s)</p>
          <div class="mt-3 flex flex-wrap gap-1.5">
            <span v-for="s in test.verification?.stages ?? []" :key="s.stage + s.summary" class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="stageTone[s.status]" :title="s.summary">{{ s.stage }}</span>
            <span v-if="test.mutation_score !== null" class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">mutation {{ test.mutation_score }}%</span>
          </div>
        </div>
        <div v-if="app.canApprove && ['verified', 'suspected_bug'].includes(test.status)" class="flex items-center gap-2">
          <button class="btn-secondary" @click="open = open === test.id ? null : test.id">{{ open === test.id ? 'Hide code' : 'View code' }}</button>
          <button class="btn-primary" :disabled="busy === test.id" @click="decide(test, 'approve')">Approve</button>
        </div>
        <button v-else class="btn-secondary" @click="open = open === test.id ? null : test.id">{{ open === test.id ? 'Hide code' : 'View code' }}</button>
      </div>

      <div v-if="open === test.id" class="mt-4 border-t border-slate-200 pt-4">
        <CodeBlock :code="test.code ?? ''" max-height="28rem" />
        <div v-if="app.canApprove" class="mt-3 flex gap-2">
          <input v-model="reasons[test.id]" class="input max-w-lg" placeholder="Why reject? (required, e.g. 'asserts the wrong rounding rule')" />
          <button class="btn-danger" :disabled="busy === test.id || (reasons[test.id] ?? '').trim().length < 3" @click="decide(test, 'reject')">Reject</button>
        </div>
      </div>
    </article>
  </div>
</template>
