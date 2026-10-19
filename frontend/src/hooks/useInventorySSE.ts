/// <reference types="vite/client" />
import { useEffect, useRef } from 'react'
import useProductStore from '../store/productStore'

/** بيانات حدث تحديث المخزون القادمة من SSE */
interface InventoryUpdateEvent {
  product_id: number
  action: string
  quantity: number
  delta: number
  timestamp: string
}

/**
 * Hook يفتح اتصال SSE لاستقبال تحديثات المخزون اللحظية.
 * يعمل فقط عندما يكون المستخدم مسجلاً دخوله.
 */
export function useInventorySSE(enabled: boolean = true) {
  const lastIdRef = useRef<number>(0)

  useEffect(() => {
    if (!enabled) return

    const configuredBase = window.API_BASE_URL
      || import.meta.env.VITE_API_ORIGIN
      || window.location.origin
    const apiOrigin = new URL(configuredBase, window.location.origin).origin
    const url = new URL('/api/sse/inventory', apiOrigin)

    if (typeof EventSource === 'undefined') {
      console.warn('[SSE] EventSource not supported in this browser. Real-time updates disabled.')
      return
    }

    url.searchParams.set('last_id', String(lastIdRef.current))
    const source = new EventSource(url.toString(), { withCredentials: true })

    source.addEventListener('inventory_update', (e: MessageEvent) => {
      try {
        const data: InventoryUpdateEvent = JSON.parse(e.data as string)
        // تحديث المنتج في الـ store محلياً
        const { products, setProducts } = useProductStore.getState()
        const updated = products.map(p =>
          p.id === data.product_id
            ? { ...p, quantity: data.quantity }
            : p
        )
        setProducts(updated)

        // حفظ آخر ID لإعادة الاتصال
        if (e.lastEventId) {
          lastIdRef.current = parseInt(e.lastEventId, 10)
        }
      } catch (err) { // تجاهل أخطاء الـ parsing
      }
    })

    source.onerror = () => {
      // المتصفح يعيد الاتصال تلقائياً
      console.warn('[SSE] Connection lost, will reconnect...')
    }

    return () => {
      source.close()
    }
  }, [enabled])
}
