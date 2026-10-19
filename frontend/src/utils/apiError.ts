import { AxiosError } from 'axios'

/**
 * استخراج رسالة الخطأ من response الـ API أو من كائن Error عادي.
 *
 * الاستخدام:
 *   } catch (err) {
 *     toast.error(extractApiError(err, 'فشلت العملية'))
 *   }
 */
export function extractApiError(err: unknown, fallback = 'حدث خطأ غير متوقع'): string {
  if (err instanceof AxiosError) {
    const data = err.response?.data as Record<string, unknown> | undefined
    if (typeof data?.message === 'string') return data.message
    if (typeof data?.error === 'string') return data.error
  }
  if (err instanceof Error) return err.message
  return fallback
}
