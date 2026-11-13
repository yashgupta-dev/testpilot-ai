<script setup>
import { inject, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api, app } from '../lib/api.js'
import Icon from './Icon.vue'

const route = useRoute()
const shell = inject('session', null)
const counts = ref({ review: 0, suggestions: 0 })
const env = ref(null)

const groups = [
  { label: 'MONITOR', items: [{ to: '/', name: 'Overview', icon: 'overview', match: ['overview'] }, { to: '/analytics', name: 'Analytics', icon: 'analytics', match: ['analytics'] }, { to: '/ai', name: 'AI usage', icon: 'sparkles', match: ['ai'] }] },
  { label: 'TEST', items: [
    { to: '/targets', name: 'Targets', icon: 'targets', match: ['targets', 'target'] },
    { to: '/review', name: 'Review queue', icon: 'review', match: ['review'], count: 'review' },
    { to: '/runs', name: 'Runs', icon: 'runs', match: ['runs', 'run'] },
  ] },
  { label: 'IMPROVE', items: [{ to: '/suggestions', name: 'Suggestions', icon: 'suggestions', match: ['suggestions'], count: 'suggestions' }] },
]

const isActive = (item) => item.match.includes(route.name)

onMounted(async () => {
  try {
    const overview = await api('/overview')
    env.value = overview.environment
    counts.value.review = overview.stats.awaiting_approval
    counts.value.suggestions = (overview.stats.failing_targets ?? 0) + (overview.stats.stale ?? 0)
  } catch {
    // The sidebar must render even if the API is unavailable.
  }
})

const initials = (app.user ?? '?').replace(/@.*/, '').split(/[.\s_-]/).map((p) => p[0]).join('').slice(0, 2).toUpperCase()
</script>

<template>
  <aside class="sticky top-0 flex h-screen w-[248px] shrink-0 flex-col bg-slate-900 px-4 pb-4 pt-5 text-sm">
    <div class="flex items-center gap-2.5 rounded-xl bg-slate-800 p-2.5">
      <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-xs font-bold text-white">TP</span>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-bold text-white">TestPilot AI</p>
        <p class="truncate text-[11px] font-medium text-slate-400">{{ app.project }}</p>
      </div>
    </div>

    <RouterLink to="/targets" class="mt-3 flex items-center gap-2 rounded-lg border border-slate-800 bg-slate-950/60 px-3 py-2 text-slate-500 hover:text-slate-300">
      <Icon name="search" :size="15" />
      <span class="flex-1">Search or jump to…</span>
    </RouterLink>

    <nav class="mt-1 flex-1 overflow-y-auto" aria-label="Main">
      <div v-for="group in groups" :key="group.label">
        <p class="px-3 pb-1.5 pt-5 text-[11px] font-semibold tracking-[0.08em] text-slate-500">{{ group.label }}</p>
        <RouterLink
          v-for="item in group.items" :key="item.to" :to="item.to"
          class="mb-0.5 flex items-center gap-2.5 rounded-lg px-3 py-2 transition"
          :class="isActive(item) ? 'bg-slate-800 font-semibold text-white' : 'font-medium text-slate-400 hover:bg-slate-800/60 hover:text-slate-200'"
        >
          <Icon :name="item.icon" :size="18" :class="isActive(item) ? 'text-brand-300' : ''" />
          <span class="flex-1">{{ item.name }}</span>
          <span v-if="item.count && counts[item.count]" class="rounded-full px-1.5 text-[11px] font-semibold" :class="isActive(item) ? 'bg-brand-600 text-white' : 'bg-slate-800 text-slate-300'">{{ counts[item.count] }}</span>
        </RouterLink>
      </div>
    </nav>

    <RouterLink to="/settings" class="mb-2 flex items-center gap-2.5 rounded-lg px-3 py-2 transition" :class="route.name === 'settings' ? 'bg-slate-800 font-semibold text-white' : 'font-medium text-slate-400 hover:bg-slate-800/60 hover:text-slate-200'">
      <Icon name="settings" :size="18" :class="route.name === 'settings' ? 'text-brand-300' : ''" />
      Settings
    </RouterLink>

    <div class="rounded-xl bg-slate-800 p-3">
      <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-200">
        <span class="h-2 w-2 rounded-full" :class="env?.tests_allowed === false ? 'bg-red-400' : 'bg-green-400'" />
        <span class="capitalize">{{ env?.app_env ?? app.environment }}</span>
        <span class="font-normal text-slate-400">· {{ env?.tests_allowed === false ? 'tests blocked' : 'tests allowed' }}</span>
      </p>
      <p class="mt-0.5 text-[11px] text-slate-400">Queue {{ env?.queue ?? '…' }} · AI {{ env?.ai_driver ?? '…' }}</p>
    </div>

    <div class="mt-3 flex items-center gap-2.5 border-t border-slate-800 px-1 pt-3">
      <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white">{{ initials }}</span>
      <div class="min-w-0 flex-1">
        <p class="truncate text-[13px] font-semibold text-white">{{ app.user }}</p>
        <p class="text-[11px] text-slate-400">{{ app.canApprove ? 'Can approve tests' : 'Read-only' }}</p>
      </div>
      <button v-if="shell?.session.value?.passcode_enabled" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 hover:text-white" title="Lock dashboard" aria-label="Lock dashboard" @click="shell.lockNow()">
        <Icon name="lock" :size="16" />
      </button>
    </div>
  </aside>
</template>
