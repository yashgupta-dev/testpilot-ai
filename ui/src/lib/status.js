// One place that decides how every status looks and reads.
const tones = {
  green: 'bg-green-100 text-green-700',
  red: 'bg-red-100 text-red-700',
  amber: 'bg-amber-100 text-amber-700',
  blue: 'bg-blue-100 text-blue-700',
  indigo: 'bg-indigo-100 text-indigo-700',
  gray: 'bg-slate-100 text-slate-600',
}

const map = {
  // targets
  not_planned: ['Not planned', 'gray'],
  planning: ['Planning…', 'blue'],
  plan_ready: ['Plan ready', 'amber'],
  generating: ['Generating…', 'blue'],
  awaiting_approval: ['Awaiting approval', 'amber'],
  approved: ['Approved', 'indigo'],
  stale: ['Stale', 'gray'],
  needs_attention: ['Needs attention', 'red'],
  skipped: ['Skipped', 'gray'],
  // generated tests
  verified: ['Tests passed', 'green'],
  suspected_bug: ['Expected behavior failed', 'red'],
  unsafe: ['Unsafe — not run', 'red'],
  rejected: ['Rejected', 'gray'],
  // runs and results
  queued: ['Queued', 'gray'],
  running: ['Running…', 'blue'],
  completed: ['Passed', 'green'],
  failed: ['Failed', 'red'],
  cancelled: ['Cancelled', 'gray'],
  passed: ['Passed', 'green'],
  error: ['Error', 'red'],
  // plans / scenarios
  proposed: ['Awaiting plan approval', 'amber'],
  superseded: ['Superseded', 'gray'],
  confirmed: ['Confirmed', 'green'],
  assumption: ['Assumption', 'amber'],
  // stages
  warning: ['Warning', 'amber'],
  needs_human: ['Needs a human', 'amber'],
}

export function statusLabel(status) {
  return map[status]?.[0] ?? String(status ?? '—').replaceAll('_', ' ')
}

export function statusClass(status, tone) {
  return tones[tone ?? map[status]?.[1] ?? 'gray']
}

export function riskColor(level) {
  return { high: '#DC2626', medium: '#D97706', low: '#16A34A' }[level] ?? '#64748B'
}

export const targetFilters = [
  ['', 'All', 'all'],
  ['not_planned', 'Not planned'],
  ['plan_ready', 'Plan ready'],
  ['awaiting_approval', 'Awaiting approval'],
  ['approved', 'Approved'],
  ['stale', 'Stale'],
  ['failing', 'Failing'],
  ['needs_attention', 'Needs attention'],
]
