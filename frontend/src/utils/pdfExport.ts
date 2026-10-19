import api from '../api/axios'

type LedgerId = number | string | null | undefined

function pdfBlobToBase64(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onloadend = () => {
      if (typeof reader.result !== 'string') {
        reject(new Error('Unable to read the PDF data.'))
        return
      }

      const base64 = reader.result.split(',', 2)[1]
      if (!base64) {
        reject(new Error('Unable to encode the PDF data.'))
        return
      }

      resolve(base64)
    }
    reader.onerror = () => reject(reader.error ?? new Error('Unable to read the PDF data.'))
    reader.readAsDataURL(blob)
  })
}

/**
 * Export Customer account statement as PDF.
 * Uses axios to pass the Auth token.
 * If asBase64 is true, returns base64 string. Otherwise triggers download.
 */
export function exportCustomerLedgerPDF(customerId: LedgerId, asBase64: true): Promise<string | undefined>
export function exportCustomerLedgerPDF(customerId: LedgerId, asBase64?: false): Promise<void>
export async function exportCustomerLedgerPDF(customerId: LedgerId, asBase64 = false): Promise<string | undefined | void> {
  if (!customerId) return
  
  try {
    const res = await api.get<Blob>(`/customers/${customerId}/pdf`, { responseType: 'blob' })
    if (asBase64) {
      return pdfBlobToBase64(res.data)
    }

    const url = window.URL.createObjectURL(new Blob([res.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `customer_ledger_${customerId}.pdf`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Failed to download PDF:', error)
    alert('فشل تحميل كشف الحساب')
  }
}

/**
 * Export Supplier account statement as PDF.
 * Uses axios to pass the Auth token.
 * If asBase64 is true, returns base64 string. Otherwise triggers download.
 */
export function exportSupplierLedgerPDF(supplierId: LedgerId, asBase64: true): Promise<string | undefined>
export function exportSupplierLedgerPDF(supplierId: LedgerId, asBase64?: false): Promise<void>
export async function exportSupplierLedgerPDF(supplierId: LedgerId, asBase64 = false): Promise<string | undefined | void> {
  if (!supplierId) return
  
  try {
    const res = await api.get<Blob>(`/suppliers/${supplierId}/pdf`, { responseType: 'blob' })
    if (asBase64) {
      return pdfBlobToBase64(res.data)
    }

    const url = window.URL.createObjectURL(new Blob([res.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `supplier_ledger_${supplierId}.pdf`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Failed to download PDF:', error)
    alert('فشل تحميل كشف الحساب')
  }
}
