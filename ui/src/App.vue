<script setup>
import { onBeforeUnmount, onMounted, provide, ref } from 'vue'
import { RouterView } from 'vue-router'
import LockScreen from './components/LockScreen.vue'
import Sidebar from './components/Sidebar.vue'
import { api, isMock } from './lib/api.js'
import { toasts } from './lib/toast.js'

const session = ref(null)
const locked = ref(false)
const viewKey = ref(0)

async function loadSession() {
  try {
    session.value = await api('/session')
    locked.value = session.value.locked
  } catch {
    session.value = { locked: false, passcode_enabled: false }
  }
}

function onLocked() { locked.value = true }
function onUnlocked() { locked.value = false; viewKey.value++; loadSession() }
async function lockNow() {
  await api('/lock', { method: 'POST' })
  locked.value = true
}

provide('session', { session, lockNow, reload: loadSession })
onMounted(() => { loadSession(); window.addEventListener('testpilot:locked', onLocked) })
onBeforeUnmount(() => window.removeEventListener('testpilot:locked', onLocked))
</script>

<template>
  <div v-if="session === null" class="min-h-screen bg-slate-50" />
  <LockScreen v-else-if="locked" @unlocked="onUnlocked" />
  <div v-else class="flex min-h-screen">
    <div class="shrink-0 bg-slate-900"><Sidebar :key="viewKey" /></div>
    <main class="min-w-0 flex-1 px-8 py-7">
      <div v-if="isMock" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-medium text-amber-800">
        Preview mode: showing built-in sample data. Inside Laravel the dashboard uses your real project.
      </div>
      <RouterView :key="viewKey" />
    </main>
  </div>

  <div class="fixed bottom-5 right-5 z-50 flex w-96 flex-col gap-2" aria-live="polite">
    <div
      v-for="t in toasts" :key="t.id"
      class="rounded-lg px-4 py-3 text-sm font-medium shadow-lg"
      :class="t.tone === 'error' ? 'bg-red-600 text-white' : 'bg-slate-900 text-white'"
    >{{ t.message }}</div>
  </div>
</template>
