<script setup>
import { nextTick, onMounted, ref } from 'vue'
import { api, app } from '../lib/api.js'

const emit = defineEmits(['unlocked'])
const passcode = ref('')
const error = ref('')
const busy = ref(false)
const input = ref(null)

onMounted(() => nextTick(() => input.value?.focus()))

async function unlock() {
  if (!passcode.value) return
  busy.value = true
  error.value = ''
  try {
    await api('/unlock', { method: 'POST', body: { passcode: passcode.value } })
    emit('unlocked')
  } catch (e) {
    error.value = e.message
    passcode.value = ''
    nextTick(() => input.value?.focus())
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-900 px-4">
    <form class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl" @submit.prevent="unlock">
      <div class="flex items-center gap-2.5">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">TP</span>
        <div><p class="font-bold">TestPilot AI</p><p class="text-xs text-slate-500">{{ app.project }}</p></div>
      </div>
      <h1 class="mt-6 text-xl font-bold">Dashboard locked</h1>
      <p class="mt-1 text-sm text-slate-500">Enter the TestPilot passcode to continue. Your team admin can change it in Settings → Security.</p>
      <label class="mt-5 block">
        <span class="label">Passcode</span>
        <input ref="input" v-model="passcode" type="password" autocomplete="current-password" class="input mt-1.5" :aria-invalid="!!error" aria-describedby="lock-error" />
      </label>
      <p id="lock-error" class="mt-2 min-h-[1.25rem] text-sm font-medium text-red-600" role="alert">{{ error }}</p>
      <button type="submit" class="btn-primary mt-2 w-full justify-center" :disabled="busy || !passcode">{{ busy ? 'Checking…' : 'Unlock' }}</button>
      <p class="mt-4 text-center text-xs text-slate-400">Signed in as {{ app.user }}</p>
    </form>
  </div>
</template>
