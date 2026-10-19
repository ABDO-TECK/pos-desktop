/**
 * يولّد HTML لملصقات باركود لمنتج واحد أو أكثر.
 * يعتمد على مكتبة JsBarcode (CDN) لتوليد SVG في الملصق.
 */
export interface LabelProduct {
  name: string
  barcode: string
  price: number
}

export function buildLabelHTML(products: LabelProduct[], copies: number = 1): string {
  // أبعاد الملصق: 50mm × 25mm (حجم قياسي)
  const labels = products.flatMap(p =>
    Array.from({ length: copies }, () => `
      <div class="label">
        <div class="label-name">${p.name}</div>
        <svg class="barcode" data-barcode="${p.barcode}"></svg>
        <div class="label-price">${p.price.toFixed(2)} ج.م</div>
      </div>
    `)
  )

  return `<!DOCTYPE html>
<html dir="rtl"><head>
<meta charset="utf-8">
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3/dist/JsBarcode.all.min.js"></script>
<style>
  @page { size: 50mm 25mm; margin: 0; }
  body { margin: 0; font-family: Arial, sans-serif; }
  .label {
    width: 50mm; height: 25mm;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    page-break-after: always; padding: 1mm;
    box-sizing: border-box;
  }
  .label-name { font-size: 8pt; font-weight: bold; text-align: center; max-width: 100%; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
  .barcode { max-width: 44mm; height: 12mm; }
  .label-price { font-size: 10pt; font-weight: bold; }
</style>
</head><body>
${labels.join('')}
<script>
  document.querySelectorAll('.barcode').forEach(el => {
    JsBarcode(el, el.dataset.barcode, {
      format: 'CODE128', width: 1.5, height: 30,
      displayValue: true, fontSize: 10, margin: 0
    })
  })
</script>
</body></html>`
}
