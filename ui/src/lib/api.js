import { mockApi } from './mock.js'

const config = window.TestPilot ?? null

/** True when the dashboard runs outside Laravel (vite dev / preview) with sample data. */
export const isMock = config === null

export const app = config ?? {
  basePath: '',
  apiPath: '/api',
  user: 'yash@example.com',
  canApprove: true,
  project: 'laravel-shop',
  environment: 'local',
}

export class ApiError extends Error {
  constructor(message, status, errors = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

/**
 * Downloads test cases as a spreadsheet (GET /api/export). The browser handles the file, so dashboard auth
 * and the passcode cookie apply as for any other API call.
 */
export function exportUrl({ format = 'xlsx', module = null, targets = [], selected = false } = {}) {
  const q = new URLSearchParams({ format })
  if (module) q.set('module', module)
  if (selected) q.set('selected', '1')
  targets.forEach((id) => q.append('targets[]', id))
  return `${app.apiPath}/export?${q}`
}

export function downloadExport(options) {
  if (isMock) return false
  window.location.href = exportUrl(options)
  return true
}

function csrf() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

export async function api(path, { method = 'GET', body } = {}) {
  if (isMock) {
    return mockApi(path, { method, body })
  }

  const response = await fetch(app.apiPath + path, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrf(),
    },
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  const data = await response.json().catch(() => ({}))

  if (response.status === 423) {
    // Dashboard passcode required: App.vue shows the lock screen.
    window.dispatchEvent(new CustomEvent('testpilot:locked'))
  }

  if (!response.ok) {
    const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null
    throw new ApiError(firstError ?? data.message ?? `Request failed (${response.status})`, response.status, data.errors ?? {})
  }

  return data
}
