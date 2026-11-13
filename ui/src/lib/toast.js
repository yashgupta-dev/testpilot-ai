import { reactive } from 'vue'

export const toasts = reactive([])

export function toast(message, tone = 'success') {
  const id = Math.random().toString(36).slice(2)
  toasts.push({ id, message, tone })
  setTimeout(() => {
    const index = toasts.findIndex((t) => t.id === id)
    if (index !== -1) toasts.splice(index, 1)
  }, tone === 'error' ? 8000 : 4000)
}
