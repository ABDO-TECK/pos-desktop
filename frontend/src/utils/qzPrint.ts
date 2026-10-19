/**
 * qzPrint.js — QZ Tray integration for the POS React app
 *
 * How it works:
 *  1. qz-tray.js is loaded as a plain <script> in index.html → puts `qz` on window
 *  2. qz-config.js is loaded next → puts `QZ_CONFIG` on window
 *  3. This module is imported by React components; it reads window.qz at call-time
 *     (not at import-time), so there's no timing issue.
 *
 * Digital signature:
 *  • Tries to load /digital-certificate.txt from the public folder.
 *  • If the file is absent, unsigned mode is permitted only in Vite development;
 *    production rejects missing certificates/signatures.
 *  • sign-message.php lives in backend/ and is accessible at
 *    /pos/backend/sign-message.php (Apache serves it directly because it is a
 *    real file — the .htaccess rewrite condition is !-f).
 */

import { buildReceiptHTML, buildPurchaseReceiptHTML } from './receiptBuilder'

export type QZStatus = 'idle' | 'connecting' | 'ready' | 'error' | 'unavailable'

export interface QZRemoteErrorDetails {
    message: string
    certUrl: string
}

export type QZPrintResult =
    | { ok: true; error?: undefined }
    | { ok: false; error: string }

type ReceiptSettings = Parameters<typeof buildReceiptHTML>[2]
type ReceiptPrintOptions = Parameters<typeof buildReceiptHTML>[4]

interface QZRemoteError extends Error, QZRemoteErrorDetails {
    isQZError: true
    isRemoteQZ: true
}

declare global {
  interface Window {
    qz: QZ;
    QZ_CONFIG?: QZConfig;
  }
}

// ── Helpers ────────────────────────────────────────────────────────────────

function getQZ(): QZ {
    if (typeof window === 'undefined' || typeof window.qz === 'undefined') {
        throw new Error('مكتبة QZ Tray غير محملة. تأكد من تشغيل QZ Tray على جهازك.')
    }
    return window.qz
}

/** هل نحن داخل Electron؟ */
function getElectronQZApi(): ElectronAPI | undefined {
    return typeof window === 'undefined' ? undefined : window.electronAPI
}

function getCfg(): Required<Pick<QZConfig, 'host' | 'signUrl' | 'certUrl'>> & Omit<QZConfig, 'host' | 'signUrl' | 'certUrl'> {
    const configured = window.QZ_CONFIG
    const base = {
        host: configured?.host ?? 'localhost',
        signUrl: configured?.signUrl ?? '/pos/backend/sign-message.php',
        certUrl: configured?.certUrl ?? '/digital-certificate.txt',
        port: configured?.port,
        keepAlive: configured?.keepAlive,
        retries: configured?.retries,
        delay: configured?.delay,
    }
    // QZ Tray يعمل على السيرفر المحلي (جهاز الكاشير الرئيسي).
    // لذلك نعتمد على الإعدادات القادمة من qz-config.js لكي يتمكن الهاتف من الطباعة عبر الـ IP.
    if (base.host === 'pos-app' || !base.host) {
        base.host = 'localhost';
    }
    return base;
}

// ── Security setup (runs once) ─────────────────────────────────────────────

function ensureSecurity(): void {
    const qz  = getQZ()
    const cfg = getCfg()
    const electronApi = getElectronQZApi()

    const allowUnsignedDevelopment = import.meta.env.DEV

    if (electronApi?.getQZCert && electronApi.signQZMessage) {
        // ═══ Electron Mode: الشهادة والتوقيع عبر IPC ═══
        qz.security.setCertificatePromise((resolve, reject) => {
            electronApi.getQZCert()
                .then((cert: string | null) => {
                    if (cert) { resolve(cert) }
                    else if (allowUnsignedDevelopment) resolve()
                    else reject(new Error('[QZ] Electron certificate is unavailable'))
                })
                .catch(reject)
        })

        qz.security.setSignatureAlgorithm('SHA512')

        qz.security.setSignaturePromise((toSign) => (resolve, reject) => {
            electronApi.signQZMessage(toSign)
                .then((sig: string | null) => {
                    if (sig) resolve(sig)
                    else if (allowUnsignedDevelopment) resolve()
                    else reject(new Error('[QZ] Electron signature is unavailable'))
                })
                .catch(reject)
        })
    } else {
        // ═══ Browser Mode: الطريقة القديمة (fetch) — Fallback للمتصفح العادي ═══
        qz.security.setCertificatePromise((resolve, reject) => {
            fetch(cfg.certUrl, { cache: 'no-store', headers: { 'Content-Type': 'text/plain' } })
                .then((r: Response) => {
                    if (r.ok) { r.text().then((certificate) => resolve(certificate), reject) }
                    else if (allowUnsignedDevelopment) resolve()
                    else reject(new Error('[QZ] Digital certificate is unavailable'))
                })
                .catch(reject)
        })

        qz.security.setSignatureAlgorithm('SHA512')

        qz.security.setSignaturePromise((toSign) => (resolve, reject) => {
            fetch(`${cfg.signUrl}?request=${encodeURIComponent(toSign)}`, {
                cache: 'no-store', credentials: 'include',
                headers: { 'Content-Type': 'text/plain' },
            })
                .then((r: Response) => {
                    if (r.ok) {
                        r.text().then((signature) => {
                            if (signature) resolve(signature)
                            else if (allowUnsignedDevelopment) resolve()
                            else reject(new Error('[QZ] Signature is unavailable'))
                        })
                    }
                    else reject(new Error(`[QZ] sign-message returned ${r.status}`))
                })
                .catch(reject)
        })
    }

}

// ── Connection management ──────────────────────────────────────────────────

let _connecting: Promise<true> | null = null  // in-flight promise guard

/**
 * رسالة تعليمات واضحة عند فشل الاتصال من جهاز خارجي.
 * السبب الأكثر شيوعاً: المتصفح لم يقبل شهادة QZ Tray بعد.
 */
function buildQZError(host: string): QZRemoteErrorDetails {
    return {
        message: [
            'لا يمكن الاتصال بـ QZ Tray على هذا الجهاز.',
            '',
            'للطباعة من هذا الجهاز يجب:',
            '1. التأكد من تشغيل QZ Tray على السيرفر (جهاز الكاشير).',
            '2. قبول شهادة الأمان — افتح هذا الرابط في المتصفح:',
            `   https://${host}:8181`,
            '   ثم اضغط Advanced → Proceed',
            '3. ارجع لهذه الصفحة وأعد المحاولة',
        ].join('\n'),
        certUrl: `https://${host}:8181`,
    }
}

function createQZRemoteError(details: QZRemoteErrorDetails): QZRemoteError {
    const remoteDetails: QZRemoteErrorDetails & Pick<QZRemoteError, 'isQZError' | 'isRemoteQZ'> = {
        certUrl: details.certUrl,
        message: details.message,
        isQZError: true,
        isRemoteQZ: true,
    }
    return Object.assign(new Error(details.message), remoteDetails)
}

export function isQZRemoteError(error: unknown): error is QZRemoteError {
    return error instanceof Error
        && Reflect.get(error, 'isQZError') === true
        && Reflect.get(error, 'isRemoteQZ') === true
        && typeof Reflect.get(error, 'certUrl') === 'string'
}

export async function connectQZ(): Promise<true> {
    const qz  = getQZ()
    const cfg = getCfg()

    if (qz.websocket.isActive()) return true

    if (_connecting) return _connecting

    // Re-apply security every time (QZ Tray resets callbacks after disconnect)
    ensureSecurity()

    const connectOpts: QZConfig = {
        host:      cfg.host,   // Use the dynamic host configured in qz-config.js (e.g. server IP)
        port:      cfg.port ?? { secure: [8181, 8282, 8383, 8484], insecure: [8182, 8283, 8384, 8485] },
        keepAlive: cfg.keepAlive ?? 60,
        retries:   cfg.retries ?? 2,
        delay:     cfg.delay ?? 0,
    }

    console.info(`[QZ] Connecting to QZ Tray on ${cfg.host} ports=${JSON.stringify(connectOpts.port)}`)

    const connectingPromise = qz.websocket
        .connect(connectOpts)
        .then((): true => { _connecting = null; return true })
        .catch(() => {
            _connecting = null
            const info = buildQZError(cfg.host ?? 'localhost')
            console.error(`[QZ] ${info.message}`)
            throw createQZRemoteError(info)
        })

    _connecting = connectingPromise
    return connectingPromise
}

export function disconnectQZ(): void {
    try {
        const qz = getQZ()
        if (qz.websocket.isActive()) qz.websocket.disconnect()
    } catch { /* ignore */ }
}

export function isQZAvailable(): boolean {
    return typeof window !== 'undefined' && typeof window.qz !== 'undefined'
}

export function isQZConnected(): boolean {
    try { return getQZ().websocket.isActive() } catch { return false }
}

// ── Printer management ─────────────────────────────────────────────────────

const STORAGE_KEY = 'pos_qz_printer'

export function getSavedPrinter(): string | null {
    try { return localStorage.getItem(STORAGE_KEY) || null } catch { return null }
}

export function savePrinter(name: string): void {
    try { localStorage.setItem(STORAGE_KEY, name) } catch { /* ignore */ }
}

export async function listPrinters(): Promise<string[]> {
    await connectQZ()
    const printers = await getQZ().printers.find()
    return Array.isArray(printers) ? printers : [printers]
}

// ── Core print function ────────────────────────────────────────────────────

/**
 * Print raw HTML to the chosen printer via QZ Tray.
 * @param {string} html  - Complete HTML string to print
 * @param {string} [printerName] - Override printer (otherwise uses saved or prompts)
 */
export async function printHTML(html: string, printerName: string | null = null): Promise<void> {
    await connectQZ()
    const qz = getQZ()

    const printer = printerName ?? getSavedPrinter()
    if (!printer) throw new Error('لم يتم اختيار طابعة')

    const config = qz.configs.create(printer, {
        orientation: 'portrait',
        margins: 0,
        altPrinting: true,   // Uses browser's native renderer — fixes Arabic BiDi with MS Print to PDF
        encoding: 'UTF-8',
    })
    
    await qz.print(config, [{ 
        type: 'html', 
        format: 'plain', 
        data: html 
    }])
}

/**
 * Print a PDF (from Base64 data) via QZ Tray.
 * @param {string} base64Data  - Base64 encoded PDF string
 * @param {string} [printerName] - Override printer
 */
export async function printPDFBase64(base64Data: string, printerName: string | null = null): Promise<void> {
    await connectQZ()
    const qz = getQZ()

    const printer = printerName ?? getSavedPrinter()
    if (!printer) throw new Error('لم يتم اختيار طابعة')

    const config = qz.configs.create(printer)
    
    await qz.print(config, [{ 
        type: 'pdf', 
        format: 'base64', 
        data: base64Data 
    }])
}

// ── High-level helper: print an invoice object ─────────────────────────────

/**
 * Build and print a POS invoice via QZ Tray.
 * Uses buildReceiptHTML so QZ Tray output is identical to browser-print output.
 *
 * @param {object} invoice      - Invoice object from the API
 * @param {number} change       - Change due (computed client-side)
 * @param {object} settings     - { storeName, taxEnabled, taxRate }
 * @param {string} [printerName]
 */
export async function printInvoice(
    invoice: Sale & { cashier_name?: string; amount_paid?: number; change_due?: number; items_count?: number },
    change = 0,
    settings: ReceiptSettings = {},
    printerName: string | null = null,
    paperSize = '80mm',
    options: ReceiptPrintOptions = {},
): Promise<void> {
    const html = buildReceiptHTML(invoice, change, settings, paperSize, options)
    await printHTML(html, printerName)
}

/**
 * Build and print a purchase invoice via QZ Tray.
 */
export async function printPurchaseInvoice(
    invoice: PurchaseInvoice & { items_count?: number },
    settings: ReceiptSettings = {},
    printerName: string | null = null,
    paperSize = '80mm',
    options: ReceiptPrintOptions = {},
): Promise<void> {
    const html = buildPurchaseReceiptHTML(invoice, settings, paperSize, options)
    await printHTML(html, printerName)
}
