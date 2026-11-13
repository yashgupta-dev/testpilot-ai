<script setup>
import { RouterLink } from 'vue-router'
import MoreMenu from './MoreMenu.vue'

defineProps({ crumbs: { type: Array, default: () => [] }, title: String, subtitle: String, more: { type: Array, default: () => [] } })
</script>

<template>
  <div class="mb-6">
    <nav class="mb-2 flex items-center gap-1.5 text-xs font-medium text-slate-500" aria-label="Breadcrumb">
      <template v-for="(crumb, i) in crumbs" :key="i">
        <span v-if="i > 0">/</span>
        <RouterLink v-if="crumb.to" :to="crumb.to" class="hover:text-slate-800">{{ crumb.label }}</RouterLink>
        <span v-else class="truncate">{{ crumb.label }}</span>
      </template>
    </nav>
    <div class="flex flex-wrap items-center gap-3">
      <div class="min-w-0 flex-1">
        <h1 class="truncate text-[26px] font-bold leading-tight text-slate-900">{{ title }}</h1>
        <p v-if="subtitle" class="mt-1 text-sm text-slate-500">{{ subtitle }}</p>
      </div>
      <div class="flex items-center gap-2">
        <slot name="actions" />
        <MoreMenu v-if="more.length" :items="more" />
      </div>
    </div>
    <slot name="tabs" />
  </div>
</template>
