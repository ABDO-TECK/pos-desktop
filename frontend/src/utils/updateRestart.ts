const RESTART_TIMEOUT_MS = 120_000
let activeRestart: symbol | null = null
let restartDeadline = 0

export function isUpdateRestarting(): boolean {
  return activeRestart !== null && Date.now() < restartDeadline
}

/** Only expected transport failures are quiet; HTTP errors remain actionable. */
export function shouldSuppressUpdateConnectionError(error: {
  response?: unknown
  code?: string
}): boolean {
  return isUpdateRestarting() && !error.response && [
    'ERR_NETWORK', 'ECONNREFUSED', 'ECONNRESET', 'ETIMEDOUT', 'ECONNABORTED',
  ].includes(error.code ?? '')
}

/** Renderer-local scope: reload, success, rejection or timeout ends maintenance. */
export async function withUpdateRestart<T>(operation: () => Promise<T>): Promise<T> {
  if (isUpdateRestarting()) throw new Error('جاري إعادة تشغيل خدمات البرنامج بالفعل.')
  const owner = Symbol('update-restart')
  activeRestart = owner
  restartDeadline = Date.now() + RESTART_TIMEOUT_MS
  let timeout: ReturnType<typeof setTimeout> | undefined
  try {
    return await Promise.race([
      Promise.resolve().then(operation),
      new Promise<never>((_, reject) => {
        timeout = setTimeout(() => reject(new Error(
          'انتهت مهلة انتظار إعادة تشغيل الخدمات. راجع حالة التحديث قبل المحاولة مجددًا.',
        )), RESTART_TIMEOUT_MS)
      }),
    ])
  } finally {
    clearTimeout(timeout)
    if (activeRestart === owner) {
      activeRestart = null
      restartDeadline = 0
    }
  }
}
