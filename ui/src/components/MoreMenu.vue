<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Icon from './Icon.vue'

defineProps({ items: { type: Array, default: () => [] } }) // [{ label, action, danger }]
const open = ref(false)
const root = ref(null)
const close = (e) => { if (root.value && !root.value.contains(e.target)) open.value = false }
onMounted(() => document.addEventListener('click', close))
onBeforeUnmount(() => document.removeEventListener('click', close))
</script>

<template>
  <div ref="root" class="relative">
    <button class="btn-secondary px-2.5" aria-label="More actions" @click="open = !open"><Icon name="more" :size="16" /></button>
    <div v-if="open" class="absolute right-0 z-20 mt-1 w-56 rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
      <button
        v-for="item in items" :key="item.label" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"
        :class="item.danger ? 'text-red-600' : 'text-slate-700'" @click="open = false; item.action()"
      >{{ item.label }}</button>
    </div>
  </div>
</template>
