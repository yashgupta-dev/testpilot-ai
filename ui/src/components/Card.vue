<script setup>
defineProps({ title: String, hint: String, padded: { type: Boolean, default: true } })
</script>

<template>
  <section class="card min-w-0" :class="padded ? 'card-pad' : ''">
    <header v-if="title || $slots.actions" class="mb-4 flex items-start gap-3" :class="padded ? '' : 'px-5 pt-5'">
      <div class="min-w-0 flex-1">
        <h2 class="text-[15px] font-semibold text-slate-900">{{ title }}</h2>
        <p v-if="hint" class="mt-0.5 text-xs text-slate-500">{{ hint }}</p>
      </div>
      <slot name="actions" />
    </header>
    <slot />
  </section>
</template>
