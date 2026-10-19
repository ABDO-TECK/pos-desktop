/**
 * receiptBuilder.js
 * Builds a complete, print-ready HTML invoice string.
 * Used by both browser-print (new window) and QZ Tray.
 * Layout and CSS are taken directly from qz_tray/print-invoice.css.
 */

const PAYMENT_LABELS: Record<string, string> = {
    cash:          'نقدي',
    card:          'بطاقة ائتمان',
    vodafone_cash: 'فودافون كاش',
    instapay:      'انستاباي',
    other_wallet:  'محفظة إلكترونية',
    credit:        'آجل',
}

const AR = 'ar-EG-u-nu-latn'

function numeric(value: unknown): number {
    const parsed = Number(value ?? 0)
    return Number.isFinite(parsed) ? parsed : 0
}
function numericMarkup(value: string): string {
    return `<span class="numeric" dir="ltr">${value}</span>`
}
function numberText(value: unknown, maximumFractionDigits = 2): string {
    return new Intl.NumberFormat(AR, {
        minimumFractionDigits: 0,
        maximumFractionDigits,
    }).format(numeric(value))
}
function fc(n: unknown): string {
    return numericMarkup(new Intl.NumberFormat(AR, {
        style: 'currency',
        currency: 'EGP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(numeric(n)))
}
function fn(n: unknown): string {
    return new Intl.NumberFormat(AR).format(numeric(n))
}
// number with 2 decimal places, no currency symbol (for table cells)
function fd2(n: unknown): string {
    return numericMarkup(numberText(n, 2))
}
function fp(n: unknown): string {
    return `${new Intl.NumberFormat(AR).format(numeric(n))}%`
}
function itemColumnGroup(showQty: boolean, showPrice: boolean): string {
    const nameWidth = showQty && showPrice ? 36 : showQty ? 52 : showPrice ? 54 : 92
    const columns = [
        '<col style="width: 8%">',
        `<col style="width: ${nameWidth}%">`,
        ...(showQty ? [`<col style="width: ${showQty && showPrice ? 20 : 40}%">`] : []),
        ...(showPrice ? ['<col style="width: 18%">', '<col style="width: 18%">'] : []),
    ]
    return `<colgroup>${columns.join('')}</colgroup>`
}
function fd(d: string | number | Date | null | undefined): string {
    if (!d) return ''
    return new Intl.DateTimeFormat(AR, {
        year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date(d))
}
function ft(d: string | number | Date | null | undefined): string {
    if (!d) return ''
    return new Intl.DateTimeFormat(AR, {
        hour: 'numeric', minute: '2-digit', hour12: true,
    }).format(new Date(d))
}

// ── CSS ──────────────────────────────────────────────────────────────────────
const LEGACY_PRINT_CSS = `
* { box-sizing: border-box; }
body {
    font-family: Arial, Tahoma, 'DejaVu Sans', sans-serif;
    font-size: 9px;
    font-weight: 700;
    line-height: 1.3;
    margin: 0; padding: 0;
    direction: rtl;
    unicode-bidi: embed;
    color: #000;
    background: #fff;
    width: 100%;
    margin: 0 auto;
    text-align: center;
}
.invoice-container {
    max-width: 80mm;
    width: 100%;
    margin: 0 auto;
    padding: 1.5mm;
    text-align: right;
    display: inline-block;
}
.invoice-header {
    text-align: center;
    margin-bottom: 3mm;
    padding-bottom: 2mm;
    border-bottom: 1.5pt solid #000;
}
.invoice-header h2 {
    font-size: 4.8mm;
    margin: 1mm 0;
    font-weight: 900;
    color: #000;
}
.invoice-title {
    font-weight: 900;
    font-size: 3.2mm;
    margin: 1.5mm 0 0;
    text-align: center;
}
.invoice-details {
    margin: 2mm 0;
    padding-bottom: 1mm;
}
.info-row {
    display: flex;
    justify-content: space-between;
    margin: 1mm 0;
    font-size: 2.8mm;
    line-height: 1.3;
}
.info-row .lbl { font-weight: 900; white-space: nowrap; }
.info-row .val { text-align: left; }
.table {
    width: 100%;
    border-collapse: collapse;
    margin: 2mm 0;
}
.table th, .table td {
    padding: 1.2mm 1mm;
    font-size: 2.8mm;
    border: 1pt solid #000;
    text-align: center;
    vertical-align: middle;
    font-weight: 700;
    color: #000;
    background: #fff;
    line-height: 1.3;
}
.table th { font-weight: 900; font-size: 2.8mm; }
.table .name { text-align: right; max-width: 25mm; word-break: break-word; }
.total-section { margin-top: 1.5mm; }
.total-row {
    display: flex;
    justify-content: space-between;
    margin: 1.2mm 0;
    font-size: 2.8mm;
    font-weight: 700;
    color: #000;
    line-height: 1.3;
}
.total-row.grand {
    font-size: 3.8mm;
    font-weight: 900;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
    padding: 1.2mm 0;
    margin-top: 1.5mm;
    margin-bottom: 1mm;
}
.invoice-footer {
    text-align: center;
    margin-top: 3.5mm;
    font-size: 2.8mm;
    font-weight: 700;
    color: #000;
    line-height: 1.4;
}
.invoice-footer p { margin: 0.5mm 0; }
.no-print { display: none !important; }
@media print {
    @page { size: 80mm auto; margin: 0; }
    body {
        width: 100%;
        text-align: center;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .invoice-container { max-width: 80mm; width: 100%; margin: 0 auto; padding: 1.5mm; display: inline-block; text-align: right; }
    .no-print { display: none !important; }
}
`

const LEGACY_SCOPED_PRINT_CSS = `
.thermal-preview * { box-sizing: border-box; }
.thermal-preview {
    font-family: Arial, Tahoma, 'DejaVu Sans', sans-serif;
    font-size: 9px;
    font-weight: 700;
    line-height: 1.3;
    direction: rtl;
    unicode-bidi: embed;
    color: #000;
    background: #fff;
    width: 100%;
    text-align: center;
}
.thermal-preview .invoice-container {
    max-width: 80mm;
    width: 100%;
    margin: 0 auto;
    padding: 1.5mm;
    text-align: right;
    display: inline-block;
}
.thermal-preview .invoice-header {
    text-align: center;
    margin-bottom: 3mm;
    padding-bottom: 2mm;
    border-bottom: 1.5pt solid #000;
}
.thermal-preview .invoice-header h2 {
    font-size: 4.8mm;
    margin: 1mm 0;
    font-weight: 900;
    color: #000;
}
.thermal-preview .invoice-title {
    font-weight: 900;
    font-size: 3.2mm;
    margin: 1.5mm 0 0;
    text-align: center;
}
.thermal-preview .invoice-details {
    margin: 2mm 0;
    padding-bottom: 1mm;
}
.thermal-preview .info-row {
    display: flex;
    justify-content: space-between;
    margin: 1mm 0;
    font-size: 2.8mm;
    line-height: 1.3;
}
.thermal-preview .info-row .lbl { font-weight: 900; white-space: nowrap; }
.thermal-preview .info-row .val { text-align: left; }
.thermal-preview .table {
    width: 100%;
    border-collapse: collapse;
    margin: 2mm 0;
}
.thermal-preview .table th, .thermal-preview .table td {
    padding: 1.2mm 1mm;
    font-size: 2.8mm;
    border: 1pt solid #000;
    text-align: center;
    vertical-align: middle;
    font-weight: 700;
    color: #000;
    background: #fff;
    line-height: 1.3;
}
.thermal-preview .table th { font-weight: 900; font-size: 2.8mm; }
.thermal-preview .table .name { text-align: right; max-width: 25mm; word-break: break-word; }
.thermal-preview .total-section { margin-top: 1.5mm; }
.thermal-preview .total-row {
    display: flex;
    justify-content: space-between;
    margin: 1.2mm 0;
    font-size: 2.8mm;
    font-weight: 700;
    color: #000;
    line-height: 1.3;
}
.thermal-preview .total-row.grand {
    font-size: 3.8mm;
    font-weight: 900;
    border-top: 1.5pt solid #000;
    border-bottom: 1.5pt solid #000;
    padding: 1.2mm 0;
    margin-top: 1.5mm;
    margin-bottom: 1mm;
}
.thermal-preview .invoice-footer {
    text-align: center;
    margin-top: 3.5mm;
    font-size: 2.8mm;
    font-weight: 700;
    color: #000;
    line-height: 1.4;
}
.thermal-preview .invoice-footer p { margin: 0.5mm 0; }
`

// The same rules are appended to the legacy stylesheet so browser print,
// QZ Tray and the in-app preview share one predictable invoice layout.
const PROFESSIONAL_PRINT_CSS = `
@page { size: 80mm auto; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    width: 100%;
    min-width: 0;
    font-family: Tahoma, Arial, 'DejaVu Sans', sans-serif;
    font-size: 9px;
    font-weight: 600;
    line-height: 1.35;
    direction: rtl;
    unicode-bidi: plaintext;
    color: #111827;
    background: #fff;
    text-align: center;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.invoice-container {
    width: 80mm;
    max-width: 100%;
    margin: 0 auto;
    padding: 2.5mm 2.5mm;
    display: block;
    text-align: right;
    overflow-wrap: anywhere;
}
.invoice-header {
    margin: 0 0 2.2mm;
    padding: 0 0 1.8mm;
    text-align: center;
    border-bottom: .35mm solid #000;
    background: transparent;
}
.invoice-header h2 {
    margin: 0;
    font-size: 5mm;
    line-height: 1.25;
    font-weight: 800;
    color: #0f172a;
    background: transparent;
}
.invoice-title {
    margin: 1.5mm 0 0;
    font-size: 3.3mm;
    line-height: 1.25;
    font-weight: 800;
    color: #1e293b;
    background: transparent;
}
.invoice-details {
    margin: 0 0 1.8mm;
    padding: 0;
}
.info-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 2mm;
    margin: .8mm 0;
    font-size: 2.55mm;
    line-height: 1.25;
}
.info-row > span { min-width: 0; overflow-wrap: anywhere; }
.info-row .lbl { font-weight: 800; white-space: nowrap; }
.info-row .val { text-align: left; }
.numeric {
    direction: ltr;
    unicode-bidi: isolate;
    white-space: nowrap;
    text-align: left;
}
.table {
    width: 100%;
    margin: 1.5mm 0 2mm;
    border-collapse: collapse;
    table-layout: fixed;
}
.table thead { display: table-header-group; }
.table tr { page-break-inside: avoid; }
.table th, .table td {
    padding: .9mm .7mm;
    border: .35mm solid #000;
    font-size: 2.55mm;
    line-height: 1.2;
    text-align: center;
    vertical-align: middle;
    color: #000;
    background: #fff;
    overflow-wrap: anywhere;
    word-break: break-word;
    white-space: normal;
}
.table th {
    font-weight: 800;
    color: #000;
    background: transparent;
    white-space: nowrap;
}
.table .name { max-width: none; text-align: right; white-space: normal; overflow-wrap: anywhere; word-break: break-word; }
.table td:not(.name) { white-space: nowrap; }
.quantity-value { display: inline-block; direction: ltr; unicode-bidi: isolate; white-space: nowrap; text-align: center; }
.total-section { margin-top: 1mm; page-break-inside: avoid; }
.total-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 2mm;
    margin: 1mm 0;
    font-size: 2.55mm;
    line-height: 1.25;
    color: #1e293b;
}
.total-row > span:last-child { white-space: nowrap; }
.total-row.grand {
    margin: 1.5mm 0 1mm;
    padding: 1.2mm 0;
    border-top: .35mm solid #000;
    border-bottom: .35mm solid #000;
    font-size: 3.5mm;
    font-weight: 800;
    color: #0f172a;
}
.invoice-footer {
    margin-top: 2.5mm;
    padding-top: 1.5mm;
    border-top: .25mm solid #000;
    text-align: center;
    font-size: 2.6mm;
    line-height: 1.45;
    color: #000;
}
.invoice-footer p { margin: .5mm 0; }
.no-print { display: none !important; }
@media print {
    .invoice-container { width: 80mm; max-width: 100%; padding: 2.5mm; }
    .no-print { display: none !important; }
}
`

const PROFESSIONAL_SCOPED_PRINT_CSS = `
.thermal-preview {
    width: 100%;
    min-width: 0;
    padding: 0;
    font-family: Tahoma, Arial, 'DejaVu Sans', sans-serif;
    font-size: 9px;
    font-weight: 600;
    line-height: 1.35;
    direction: rtl;
    unicode-bidi: plaintext;
    color: #111827;
    background: #fff;
    text-align: center;
}
.thermal-preview .invoice-container {
    width: 80mm;
    max-width: 100%;
    margin: 0 auto;
    padding: 2.5mm;
    display: block;
    text-align: right;
    overflow-wrap: anywhere;
}
.thermal-preview .invoice-header { margin: 0 0 2.2mm; padding: 0 0 1.8mm; border-bottom: .35mm solid #000; text-align: center; background: transparent; }
.thermal-preview .invoice-header h2 { margin: 0; font-size: 5mm; line-height: 1.25; font-weight: 800; color: #0f172a; background: transparent; }
.thermal-preview .invoice-title { margin: 1.5mm 0 0; font-size: 3.3mm; line-height: 1.25; font-weight: 800; color: #1e293b; background: transparent; }
.thermal-preview .invoice-details { margin: 0 0 1.8mm; padding: 0; }
.thermal-preview .info-row { display: flex; align-items: baseline; justify-content: space-between; gap: 2mm; margin: .8mm 0; font-size: 2.55mm; line-height: 1.25; }
.thermal-preview .info-row > span { min-width: 0; overflow-wrap: anywhere; }
.thermal-preview .info-row .lbl { font-weight: 800; white-space: nowrap; }
.thermal-preview .numeric { direction: ltr; unicode-bidi: isolate; white-space: nowrap; text-align: left; }
.thermal-preview .table { width: 100%; margin: 1.5mm 0 2mm; border-collapse: collapse; table-layout: fixed; }
.thermal-preview .table thead { display: table-header-group; }
.thermal-preview .table tr { page-break-inside: avoid; }
.thermal-preview .table th, .thermal-preview .table td { padding: .9mm .7mm; border: .35mm solid #000; font-size: 2.55mm; line-height: 1.2; text-align: center; vertical-align: middle; color: #000; background: #fff; overflow-wrap: anywhere; word-break: break-word; white-space: normal; }
.thermal-preview .table th { font-weight: 800; color: #000; background: transparent; white-space: nowrap; }
.thermal-preview .table .name { max-width: none; text-align: right; white-space: normal; overflow-wrap: anywhere; word-break: break-word; }
.thermal-preview .table td:not(.name) { white-space: nowrap; }
.thermal-preview .quantity-value { display: inline-block; direction: ltr; unicode-bidi: isolate; white-space: nowrap; text-align: center; }
.thermal-preview .total-section { margin-top: 1mm; page-break-inside: avoid; }
.thermal-preview .total-row { display: flex; align-items: baseline; justify-content: space-between; gap: 2mm; margin: 1mm 0; font-size: 2.55mm; line-height: 1.25; color: #1e293b; }
.thermal-preview .total-row > span:last-child { white-space: nowrap; }
.thermal-preview .total-row.grand { margin: 1.5mm 0 1mm; padding: 1.2mm 0; border-top: .35mm solid #000; border-bottom: .35mm solid #000; font-size: 3.5mm; font-weight: 800; color: #0f172a; }
.thermal-preview .invoice-footer { margin-top: 2.5mm; padding-top: 1.5mm; border-top: .25mm solid #000; text-align: center; font-size: 2.6mm; line-height: 1.45; color: #000; }
.thermal-preview .invoice-footer p { margin: .5mm 0; }
`

export const PRINT_CSS = `${LEGACY_PRINT_CSS}\n${PROFESSIONAL_PRINT_CSS}`
export const SCOPED_PRINT_CSS = `${LEGACY_SCOPED_PRINT_CSS}\n${PROFESSIONAL_SCOPED_PRINT_CSS}`

function getA4OverrideCss(paperSize: string): string {
    if (paperSize !== 'A4') return ''
    return `
    @media print { @page { size: A4 portrait; margin: 10mm; } }
    .invoice-container { width: 190mm !important; max-width: 190mm !important; font-size: 14px; padding: 10mm; }
    .invoice-header h2 { font-size: 8mm !important; }
    .invoice-title { font-size: 5mm !important; }
    .info-row { font-size: 4.5mm !important; margin: 2mm 0 !important; }
    .table th, .table td { font-size: 4mm !important; padding: 2mm !important; }
    .table .name { max-width: none !important; }
    .total-row { font-size: 4.5mm !important; margin: 1.5mm 0 !important; }
    .total-row.grand { font-size: 6mm !important; padding: 2mm 0 !important; }
    .invoice-footer { font-size: 4.5mm !important; margin-top: 5mm !important; }
    `
}

// ── HTML builder ────────────────────────────────────────────────────────────
interface ReceiptSettings {
  storeName?: string;
  storeLogo?: string | null;
  taxEnabled?: boolean;
  taxRate?: number;
}

function escapeHtml(value: unknown): string {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;')
}

function safeImageSource(value: unknown): string {
    const source = String(value ?? '')
    return /^data:image\/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=\s]+$/i.test(source)
        ? source.replace(/\s+/g, '')
        : ''
}

export function buildReceiptInnerHTML(
    invoice: Sale & { cashier_name?: string, amount_paid?: number, change_due?: number, items_count?: number }, 
    change = 0, 
    settings: ReceiptSettings = {}, 
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const storeName  = settings.storeName  ?? 'سوبر ماركت'
    const storeLogo  = settings.storeLogo  ?? null
    const taxEnabled = settings.taxEnabled !== false
    const taxRate    = settings.taxRate    ?? 15

    const isCash    = invoice.payment_method === 'cash'
    const changeAmt = invoice.change_due ?? change
    const payLabel  = PAYMENT_LABELS[invoice.payment_method] ?? invoice.payment_method

    const showQty = !options.hideQuantities
    const showPrice = !options.hidePrices

    // ── Items rows — no currency symbol in table ──
    const itemRows = (invoice.items ?? []).map((item, i) => {
        const qty = numeric(item.quantity)
        const unitType = item.unit_type ?? (numeric(item.sell_by_weight) === 1 ? 'weight' : 'piece')
        const isByWeight = unitType === 'weight'
        const isByLiter = unitType === 'liter'
        const qtyStr = isByWeight ? `${qty.toFixed(3)} كجم` : (isByLiter ? `${qty.toFixed(2)} لتر` : fn(item.quantity))
        const quantityText = isByWeight ? `${numberText(qty, 3)} kg` : (isByLiter ? `${numberText(qty, 2)} L` : qtyStr)
        const qtyMarkup = `<span class="quantity-value" dir="ltr">${quantityText}</span>`
        const rawName = String(item.product_name ?? item.name ?? '')
        const rawSize = String(item.size_name ?? '').trim()
        const includesSize = rawSize !== ''
            && rawName.toLocaleLowerCase().includes(rawSize.toLocaleLowerCase())
        const nameStr = escapeHtml(rawName)
            + (rawSize && !includesSize ? ` (${escapeHtml(rawSize)})` : '')
        return `
        <tr>
            <td>${fn(i + 1)}</td>
            <td class="name">${nameStr}</td>
            ${showQty ? `<td>${qtyMarkup}</td>` : ''}
            ${showPrice ? `<td>${fd2(item.price ?? item.unit_price)}</td><td>${fd2(numeric(item.price ?? item.unit_price) * qty)}</td>` : ''}
        </tr>`
    }).join('')

    // ── Totals ──
    const discountRow = numeric(invoice.discount) > 0
        ? `<div class="total-row discount"><span>الخصم</span><span>- ${fc(invoice.discount)}</span></div>` : ''

    const taxRow = taxEnabled && numeric(invoice.tax) > 0
        ? `<div class="total-row"><span>ضريبة القيمة المضافة (${fp(taxRate)})</span><span>${fc(invoice.tax)}</span></div>` : ''

    const shippingRow = numeric(invoice.shipping_cost) > 0
        ? `<div class="total-row"><span>تكلفة الشحن</span><span>${fc(invoice.shipping_cost)}</span></div>` : ''

    const cashRows = isCash ? `
        <div class="total-row"><span>المبلغ المدفوع</span><span>${fc(invoice.amount_paid ?? invoice.paid_amount)}</span></div>
        <div class="total-row"><span>المبلغ المسترد</span><span>${fc(changeAmt)}</span></div>` : ''

    const isCredit = invoice.payment_method === 'credit'
    const amountDue = numeric(invoice.amount_due ?? invoice.due_amount ?? (numeric(invoice.total ?? invoice.net_amount) - numeric(invoice.amount_paid ?? invoice.paid_amount)))
    const creditRows = isCredit ? `
        ${numeric(invoice.amount_paid ?? invoice.paid_amount) > 0 ? `<div class="total-row"><span>عربون مدفوع</span><span>${fc(invoice.amount_paid ?? invoice.paid_amount)}</span></div>` : ''}
        <div class="total-row grand" style="border-top: none; margin-top: 0;"><span>متبقي آجلاً</span><span>${fc(amountDue)}</span></div>` : ''

    const safeLogo = safeImageSource(storeLogo)
    const logoHtml = safeLogo ? `
    <div style="text-align: center; margin-bottom: 2.5mm;">
        <img src="${safeLogo}" alt="" style="max-height: 20mm; max-width: 40mm; object-fit: contain;" />
    </div>` : '';
    const customerName = String(invoice.customer_name ?? '').trim()
    const customerNameRow = customerName
        ? `<div class="info-row customer-name-row"><span><span class="lbl">اسم العميل:</span> <span dir="auto">${escapeHtml(customerName)}</span></span></div>`
        : ''

    return `
<div class="invoice-container">
    ${logoHtml}

    <!-- Header -->
    <div class="invoice-header">
        <h2> ${escapeHtml(storeName)}</h2>
        <div class="invoice-title">فاتورة رقم: #${fn(invoice.id)}</div>
    </div>

    <!-- Details -->
    <div class="invoice-details">
        <div class="info-row">
            <span><span class="lbl">التاريخ:</span> ${fd(invoice.created_at)}</span>
            <span><span class="lbl">طريقة الدفع:</span> ${escapeHtml(payLabel)}</span>
        </div>
        <div class="info-row">
            <span><span class="lbl">الوقت:</span> ${ft(invoice.created_at)}</span>
            <span><span class="lbl">الكاشير:</span> ${escapeHtml(invoice.cashier_name)}</span>
        </div>
        ${customerNameRow}
    </div>

    <!-- Items -->
    <table class="table">
        ${itemColumnGroup(showQty, showPrice)}
        <thead>
            <tr>
                <th>#</th>
                <th>المنتج</th>
                ${showQty ? '<th>الكمية</th>' : ''}
                ${showPrice ? '<th>السعر</th><th>الإجمالي</th>' : ''}
            </tr>
        </thead>
        <tbody>${itemRows}</tbody>
    </table>

    <!-- Totals -->
    ${showPrice ? `
    <div class="total-section">
        <div class="total-row"><span>المجموع الجزئي</span><span>${fc(invoice.subtotal ?? invoice.total_amount)}</span></div>
        ${discountRow}${taxRow}${shippingRow}
        <div class="total-row grand"><span>الإجمالي</span><span>${fc(invoice.total ?? invoice.net_amount)}</span></div>
        ${cashRows}
        ${creditRows}
    </div>` : ''}

    <!-- Footer -->
    <div class="invoice-footer">
        <p>شكراً لزيارتكم — نتمنى لكم تجربة ممتعة</p>
    </div>
</div>`
}

export function buildReceiptHTML(
    invoice: Sale & { cashier_name?: string, amount_paid?: number, change_due?: number, items_count?: number }, 
    change = 0, 
    settings: ReceiptSettings = {}, 
    paperSize = '80mm',
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const inner = buildReceiptInnerHTML(invoice, change, settings, options)
    const a4Css = getA4OverrideCss(paperSize)

    return `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة #${fn(invoice.id)}</title>
    <style>${PRINT_CSS} ${a4Css}</style>
</head>
<body>
${inner}
</body>
</html>`
}

/**
 * Open a new browser print window and print.
 * Works correctly from inside modals because the content is in a separate window.
 */
export function browserPrint(
    invoice: Sale & { cashier_name?: string, amount_paid?: number, change_due?: number, items_count?: number }, 
    change: number, 
    settings: ReceiptSettings, 
    paperSize = '80mm',
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const html    = buildReceiptHTML(invoice, change, settings, paperSize, options)
    const win     = window.open('', 'pos-receipt-print', 'width=800,height=800,scrollbars=yes')
    if (!win) { alert('يرجى السماح بالنوافذ المنبثقة لهذا الموقع'); return }
    win.document.open()
    win.document.write(html)
    win.document.close()
    win.addEventListener('load', () => { win.focus(); win.print() })
}


// ── Purchase Invoice printing ─────────────────────────────────────────────
export function buildPurchaseReceiptInnerHTML(
    invoice: PurchaseInvoice & { items_count?: number }, 
    settings: ReceiptSettings = {}, 
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const storeName  = settings.storeName  ?? 'سوبر ماركت'
    const storeLogo  = settings.storeLogo  ?? null

    const showQty = !options.hideQuantities
    const showPrice = !options.hidePrices

    const itemRows = (invoice.items ?? []).map((item, i) => {
        const qty = numeric(item.quantity)
        const unitType = item.unit_type ?? (numeric(item.sell_by_weight) === 1 ? 'weight' : 'piece')
        const isByWeight = unitType === 'weight'
        const isByLiter = unitType === 'liter'
        const qtyStr = isByWeight ? `${qty.toFixed(3)} كجم` : (isByLiter ? `${qty.toFixed(2)} لتر` : fn(item.quantity))
        const quantityText = isByWeight ? `${numberText(qty, 3)} kg` : (isByLiter ? `${numberText(qty, 2)} L` : qtyStr)
        const qtyMarkup = `<span class="quantity-value" dir="ltr">${quantityText}</span>`
        const nameStr = escapeHtml(item.product_name ?? item.name ?? '')
            + (item.size_name ? ` (${escapeHtml(item.size_name)})` : '')
        return `
        <tr>
            <td>${fn(i + 1)}</td>
            <td class="name">${nameStr}</td>
            ${showQty ? `<td>${qtyMarkup}</td>` : ''}
            ${showPrice ? `<td>${fd2(item.cost ?? item.unit_cost)}</td><td>${fd2(numeric(item.cost ?? item.unit_cost) * qty)}</td>` : ''}
        </tr>`
    }).join('')

    const purchaseSubtotal = (invoice.items ?? []).reduce((sum, item) => {
        const quantity = numeric(item.quantity)
        const unitCost = numeric(item.cost ?? item.unit_cost)
        return sum + (quantity * unitCost)
    }, 0)
    const purchaseDiscount = numeric(invoice.discount)
    const purchaseShippingCost = numeric(invoice.shipping_cost)
    const purchaseDiscountRow = purchaseDiscount > 0
        ? `<div class="total-row discount"><span>خصم المورد</span><span>- ${fc(purchaseDiscount)}</span></div>`
        : ''
    const purchaseShippingRow = purchaseShippingCost > 0
        ? `<div class="total-row"><span>تكلفة الشحن</span><span>${fc(purchaseShippingCost)}</span></div>`
        : ''

    const safeLogo = safeImageSource(storeLogo)
    const logoHtml = safeLogo ? `
    <div style="text-align: center; margin-bottom: 2.5mm;">
        <img src="${safeLogo}" alt="" style="max-height: 20mm; max-width: 40mm; object-fit: contain;" />
    </div>` : '';

    return `
<div class="invoice-container">
    ${logoHtml}

    <div class="invoice-header">
        <h2>${escapeHtml(storeName)}</h2>
        <div class="invoice-title">فاتورة مشتريات: #${fn(invoice.id)}</div>
    </div>

    <div class="invoice-details">
        <div class="info-row">
            <span><span class="lbl">التاريخ:</span> ${fd(invoice.created_at)}</span>
            <span><span class="lbl">الوقت:</span> ${ft(invoice.created_at)}</span>
        </div>
        <div class="info-row" style="justify-content: center; margin-top: 2mm">
            <span><span class="lbl">المورد:</span> ${escapeHtml(invoice.supplier_name)}</span>
        </div>
    </div>

    <table class="table">
        ${itemColumnGroup(showQty, showPrice)}
        <thead>
            <tr>
                <th>#</th>
                <th>المنتج</th>
                ${showQty ? '<th>الكمية</th>' : ''}
                ${showPrice ? '<th>التكلفة</th><th>الإجمالي</th>' : ''}
            </tr>
        </thead>
        <tbody>${itemRows}</tbody>
    </table>

    ${showPrice ? `
    <div class="total-section">
        <div class="total-row"><span>إجمالي الأصناف</span><span>${fc(purchaseSubtotal)}</span></div>
        ${purchaseDiscountRow}${purchaseShippingRow}
        <div class="total-row grand"><span>الإجمالي</span><span>${fc(invoice.total)}</span></div>
        <div class="total-row"><span>عدد الأصناف</span><span>${fn(invoice.items_count)}</span></div>
    </div>` : ''}
</div>`
}

export function buildPurchaseReceiptHTML(
    invoice: PurchaseInvoice & { items_count?: number }, 
    settings: ReceiptSettings = {}, 
    paperSize = '80mm',
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const inner = buildPurchaseReceiptInnerHTML(invoice, settings, options)
    const a4Css = getA4OverrideCss(paperSize)

    return `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة مشتريات #${fn(invoice.id)}</title>
    <style>${PRINT_CSS} ${a4Css}</style>
</head>
<body>
${inner}
</body>
</html>`
}

export function browserPrintPurchase(
    invoice: PurchaseInvoice & { items_count?: number }, 
    settings: ReceiptSettings, 
    paperSize = '80mm',
    options: { hidePrices?: boolean, hideQuantities?: boolean } = {}
) {
    const html    = buildPurchaseReceiptHTML(invoice, settings, paperSize, options)
    const win     = window.open('', 'pos-receipt-print', 'width=800,height=800,scrollbars=yes')
    if (!win) { alert('يرجى السماح بالنوافذ المنبثقة لهذا الموقع'); return }
    win.document.open()
    win.document.write(html)
    win.document.close()
    win.addEventListener('load', () => { win.focus(); win.print() })
}
