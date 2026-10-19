/**
 * useQZPrinter — Reusable React hook for QZ Tray printer management.
 * Provides connection status, printer selection, and a print helper.
 */
import { useState, useEffect, useCallback } from 'react'
import {
    isQZAvailable,
    isQZConnected,
    connectQZ,
    listPrinters,
    getSavedPrinter,
    savePrinter,
    printHTML,
    printPDFBase64,
    isQZRemoteError,
} from '../utils/qzPrint'
import type { QZPrintResult, QZRemoteErrorDetails, QZStatus } from '../utils/qzPrint'

function getErrorMessage(error: unknown): string {
    return error instanceof Error ? error.message : 'Printing failed'
}

export default function useQZPrinter() {
    const [qzStatus,          setQzStatus]          = useState<QZStatus>('idle')
    const [printers,          setPrinters]           = useState<string[]>([])
    const [selectedPrinter,   setSelectedPrinter]    = useState(getSavedPrinter() ?? '')
    const [showPrinterPicker, setShowPrinterPicker]  = useState(false)
    const [printing,          setPrinting]           = useState(false)
    const [remoteError,       setRemoteError]        = useState<QZRemoteErrorDetails | null>(null)

    const loadPrinters = useCallback(async () => {
        try {
            const list = await listPrinters()
            setPrinters(list)
            const saved = getSavedPrinter()
            if (saved && list.includes(saved)) setSelectedPrinter(saved)
            else if (list.length === 1) { savePrinter(list[0]); setSelectedPrinter(list[0]) }
        } catch {  /* ignore */ }
    }, [])

    useEffect(() => {
        if (!isQZAvailable()) { setQzStatus('unavailable'); return }
        if (isQZConnected())  { setQzStatus('ready'); loadPrinters(); return }
        setQzStatus('connecting')
        connectQZ()
            .then(() => { setQzStatus('ready'); setRemoteError(null); loadPrinters() })
            .catch((error: unknown) => {
                setQzStatus('error')
                if (isQZRemoteError(error)) {
                    setRemoteError({ message: error.message, certUrl: error.certUrl })
                }
            })
    }, [loadPrinters])

    const handlePrinterSelect = useCallback((name: string) => {
        savePrinter(name)
        setSelectedPrinter(name)
        setShowPrinterPicker(false)
    }, [])

    /** محاولة إعادة الاتصال (بعد قبول الشهادة) */
    const retryConnect = useCallback(() => {
        setQzStatus('connecting')
        setRemoteError(null)
        connectQZ()
            .then(() => { setQzStatus('ready'); loadPrinters() })
            .catch((error: unknown) => {
                setQzStatus('error')
                if (isQZRemoteError(error)) {
                    setRemoteError({ message: error.message, certUrl: error.certUrl })
                }
            })
    }, [loadPrinters])

    /** Print raw HTML via QZ Tray. Returns { ok, error }. */
    const qzPrint = useCallback(async (html: string): Promise<QZPrintResult> => {
        if (!selectedPrinter) { setShowPrinterPicker(true); return { ok: false, error: 'لم يتم اختيار طابعة' } }
        setPrinting(true)
        try {
            await printHTML(html, selectedPrinter)
            return { ok: true }
        } catch (error) {
            return { ok: false, error: getErrorMessage(error) }
        } finally {
            setPrinting(false)
        }
    }, [selectedPrinter])

    /** Print PDF (base64) via QZ Tray. Returns { ok, error }. */
    const qzPrintPDF = useCallback(async (base64: string): Promise<QZPrintResult> => {
        if (!selectedPrinter) { setShowPrinterPicker(true); return { ok: false, error: 'لم يتم اختيار طابعة' } }
        setPrinting(true)
        try {
            await printPDFBase64(base64, selectedPrinter)
            return { ok: true }
        } catch (error) {
            return { ok: false, error: getErrorMessage(error) }
        } finally {
            setPrinting(false)
        }
    }, [selectedPrinter])

    return {
        qzStatus,
        qzReady: qzStatus === 'ready',
        printers,
        selectedPrinter,
        showPrinterPicker,
        setShowPrinterPicker,
        handlePrinterSelect,
        printing,
        qzPrint,
        qzPrintPDF,
        remoteError,
        retryConnect,
    }
}
