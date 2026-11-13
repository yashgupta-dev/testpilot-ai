export function ago(iso) {
  if (!iso) return '—'
  const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000)
  if (seconds < 60) return 'just now'
  const minutes = Math.round(seconds / 60)
  if (minutes < 60) return `${minutes} min ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours} h ago`
  const days = Math.round(hours / 24)
  return `${days} d ago`
}

export function duration(ms) {
  if (ms === null || ms === undefined) return '—'
  if (ms < 1000) return `${Math.round(ms)} ms`
  const s = ms / 1000
  if (s < 60) return `${s.toFixed(1)}s`
  return `${Math.floor(s / 60)}m ${Math.round(s % 60)}s`
}

export function pct(value, digits = 0) {
  return value === null || value === undefined ? '—' : `${Number(value).toFixed(digits)}%`
}

export function money(value) {
  return `$${Number(value ?? 0).toFixed(2)}`
}

/** Extracts the Pest test block for a scenario id from generated code. */
export function scenarioBlock(code, id) {
  if (!code) return null
  const start = code.search(new RegExp(`(?:it|test)\\(\\s*['"]\\[${id}\\]`))
  if (start === -1) return null
  const rest = code.slice(start)
  const next = rest.slice(1).search(/\n(?:it|test|describe|dataset)\(/)
  return (next === -1 ? rest : rest.slice(0, next + 1)).trim()
}
