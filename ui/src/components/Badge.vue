<script setup>
import { computed } from 'vue'
import { statusClass, statusLabel } from '../lib/status.js'

const props = defineProps({ status: String, label: String, tone: String })
const text = computed(() => props.label ?? statusLabel(props.status))
</script>

<template>
  <span class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusClass(status, tone)">{{ text }}</span>
</template>
