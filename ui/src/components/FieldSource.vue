<script setup>
// Where a setting's value comes from (.env / dashboard / default), with a reset link.
defineProps({ field: Object, canEdit: Boolean, inline: Boolean })
defineEmits(['reset'])
</script>

<template>
  <component :is="inline ? 'span' : 'p'" v-if="field" class="text-[11.5px] text-slate-500" :class="inline ? '' : 'mt-1'">
    <template v-if="field.locked"><span class="font-semibold text-slate-600">Locked · from .env </span><code class="font-mono">{{ field.env }}</code></template>
    <template v-else-if="field.source === 'dashboard'">
      Set in dashboard by {{ field.updated_by ?? 'someone' }}
      <button v-if="canEdit" type="button" class="ml-2 font-semibold text-brand-600" @click="$emit('reset')">Reset to default</button>
    </template>
    <template v-else-if="field.env">Default · or set <code class="font-mono">{{ field.env }}</code></template>
    <template v-else>Default</template>
  </component>
</template>
