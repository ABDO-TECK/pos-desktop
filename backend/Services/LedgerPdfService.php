<?php

namespace App\Services;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * LedgerPdfService — بناء وتصدير كشف حساب PDF.
 *
 * يستخرج HTML/CSS/mPDF logic من LedgerPdfController.
 */
class LedgerPdfService
{
    /* ── helpers ─────────────────────────────────────────────── */

    public function fmtCurrency(float $n): string
    {
        $amount = htmlspecialchars(number_format($n, 2), ENT_QUOTES, 'UTF-8');

        return '<span class="currency-amount" dir="ltr">' . $amount . '</span>'
            . ' <span class="currency-code" dir="rtl">ج.م.</span>';
    }

    public function fmtDate(?string $d): string
    {
        if (!$d) return '—';
        $ts = strtotime($d);
        if ($ts === false) return '—';
        // Keep the Arabic month name while isolating the complete value in an
        // LTR container so the month/day order remains stable in HTML and PDF.
        $months = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];
        $month = $months[(int) date('n', $ts)] ?? '';
        $meridiem = date('A', $ts) === 'AM' ? 'ص' : 'م';

        $rtlEmbed = "\u{202B}";
        $directionalPop = "\u{202C}";

        return date('d', $ts) . '/' . $rtlEmbed . $month . $directionalPop . '/' . date('Y', $ts)
            . ' - ' . date('h:i', $ts) . ' ' . $rtlEmbed . $meridiem . $directionalPop;
    }

    /**
     * mPDF reads TTF files with random-access operations that are not reliable
     * through a phar:// stream. Materialize the bundled Arabic font files in
     * writable storage before mPDF tries to parse them.
     */
    private function prepareArabicFontDir(string $storageDir): string
    {
        $fontDir = rtrim($storageDir, '/\\') . '/mpdf_fonts_v1';
        if (!is_dir($fontDir) && !@mkdir($fontDir, 0755, true) && !is_dir($fontDir)) {
            throw new \RuntimeException('Unable to prepare the PDF Arabic font directory.');
        }

        $bundledFontDir = dirname(__DIR__) . '/vendor/mpdf/mpdf/ttfonts';
        $fontFiles = [
            'XB Riyaz.ttf',
            'XB RiyazBd.ttf',
            'XB RiyazIt.ttf',
            'XB RiyazBdIt.ttf',
        ];

        foreach ($fontFiles as $fontFile) {
            $sourcePath = $bundledFontDir . '/' . $fontFile;
            $targetPath = $fontDir . '/' . $fontFile;
            $sourceSize = @filesize($sourcePath);

            if (is_file($targetPath) && $sourceSize !== false && @filesize($targetPath) === $sourceSize) {
                continue;
            }

            $contents = @file_get_contents($sourcePath);
            if ($contents === false || $contents === '') {
                throw new \RuntimeException('Unable to load the bundled PDF Arabic font.');
            }

            $temporaryPath = $targetPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $written = @file_put_contents($temporaryPath, $contents, LOCK_EX);
            if ($written !== strlen($contents)) {
                @unlink($temporaryPath);
                throw new \RuntimeException('Unable to cache the PDF Arabic font.');
            }

            if (!@rename($temporaryPath, $targetPath)) {
                $targetSize = @filesize($targetPath);
                @unlink($temporaryPath);
                if ($targetSize !== strlen($contents)) {
                    throw new \RuntimeException('Unable to finalize the PDF Arabic font cache.');
                }
            }
        }

        return $fontDir;
    }

    /* ── mPDF factory ─────────────────────────────────────────── */

    public function createMpdf(): \Mpdf\Mpdf
    {
        $storageDir = $_ENV['APP_STORAGE_DIR'] ?? (getenv('APP_STORAGE_DIR') ?: null) ?? (__DIR__ . '/../storage');
        $tmpDir = $storageDir . '/mpdf_tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $arabicFontDir = $this->prepareArabicFontDir($storageDir);
        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDirs = array_values(array_unique(array_merge(
            [$arabicFontDir],
            $defaultConfig['fontDir'] ?? [],
        )));

        return new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'default_font'  => 'xbriyaz',
            'fontDir'       => $fontDirs,
            'directionality'=> 'rtl',
            'tempDir'       => $tmpDir,
            'margin_top'    => 15,
            'margin_bottom' => 15,
            'margin_left'   => 12,
            'margin_right'  => 12,
        ]);
    }

    /* ── CSS ──────────────────────────────────────────────────── */

    public function getCss(): string
    {
        return '
        @page { margin: 15mm 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: xbriyaz, Tahoma, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.45;
            color: #1e293b;
            background: #ffffff;
            direction: rtl;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        table { width: 100%; border-collapse: collapse; }
        .header-table { border-bottom: 2px solid #1f4e79; margin-bottom: 16px; padding-bottom: 10px; }
        .header-title { font-size: 23px; line-height: 1.25; font-weight: bold; color: #0f172a; margin: 0; padding: 0; }
        .header-subtitle { font-size: 13px; color: #475569; margin-top: 6px; }
        .header-meta { font-size: 11px; color: #475569; line-height: 1.65; text-align: left; }
        .header-meta strong { color: #1e293b; }
        .summary-table { margin-bottom: 18px; }
        .summary-table td { width: 25%; padding: 9px 7px; border: 1px solid #cbd5e1; text-align: center; background-color: #f8fafc; }
        .summary-label { font-size: 10px; color: #64748b; }
        .summary-val { margin-top: 4px; font: 700 14px/1.3 Arial, Tahoma, sans-serif; color: #0f172a; direction: ltr; unicode-bidi: isolate; }
        .currency-amount { display: inline-block; direction: ltr; unicode-bidi: isolate; white-space: nowrap; font-family: Arial, Tahoma, sans-serif; }
        .currency-code { display: inline-block; direction: rtl; unicode-bidi: isolate; white-space: nowrap; font-family: xbriyaz, Tahoma, Arial, sans-serif; }
        .balance-word { display: inline-block; direction: rtl; unicode-bidi: isolate; white-space: nowrap; font: 400 11px/1.3 xbriyaz, Tahoma, Arial, sans-serif; color: #555; }
        .ledger-table { margin-bottom: 14px; page-break-inside: auto; }
        .ledger-table thead { display: table-header-group; }
        .ledger-table thead th { background-color: #e8f0f7; color: #1e3a5f; font-weight: bold; padding: 8px 7px; border: 1px solid #b8c8d8; text-align: right; font-size: 11px; }
        .ledger-table thead th.center { text-align: center; }
        .ledger-table tbody tr { page-break-inside: avoid; }
        .ledger-table tbody tr:nth-child(even) td { background-color: #f8fafc; }
        .ledger-table tbody td { padding: 7px; border: 1px solid #e2e8f0; font-size: 11px; color: #334155; }
        .ledger-table tfoot td { background-color: #e2e8f0; color: #0f172a; font-weight: bold; padding: 8px 7px; border: 1px solid #b8c8d8; font-size: 11px; }
        .col-num { width: 5%; text-align: center; direction: ltr; }
        .col-date { width: 17%; text-align: left; direction: ltr; unicode-bidi: bidi-override; white-space: nowrap; }
        .date-value { display: inline-block; direction: ltr; unicode-bidi: bidi-override; white-space: nowrap; }
        .col-desc { width: 33%; text-align: right; }
        .col-debit { width: 15%; text-align: left; direction: ltr; unicode-bidi: isolate; color: #b42318; white-space: nowrap; }
        .col-credit { width: 15%; text-align: left; direction: ltr; unicode-bidi: isolate; color: #047857; white-space: nowrap; }
        .col-bal { width: 15%; text-align: left; direction: ltr; unicode-bidi: isolate; font-weight: bold; white-space: nowrap; }
        .numeric { direction: ltr; unicode-bidi: isolate; white-space: nowrap; }
        .footer-table { border-top: 1px solid #cbd5e1; padding-top: 8px; font-size: 9px; color: #64748b; margin-top: 18px; }
        ';
    }

    /* ── Shared HTML builder ──────────────────────────────────── */

    public function buildLedgerHtml(array $p): string
    {
        $entity      = $p['entity'];
        $entries     = $p['entries'];
        $balance     = $p['balance'];
        $totalDebit  = $p['totalDebit'];
        $totalCredit = $p['totalCredit'];
        $storeName   = $p['storeName'];
        $now         = '<span class="date-value" dir="ltr">' . $this->fmtDate(date('Y-m-d H:i:s')) . '</span>';
        $balAbs      = $this->fmtCurrency(abs($balance));
        $balWord     = trim((string)($balance > 0 ? ($p['balDebitWord'] ?? '') : ($p['balCreditWord'] ?? '')));
        $balWordHtml = $balWord === ''
            ? ''
            : ' <br><span class="balance-word" dir="rtl">('
                . htmlspecialchars($balWord, ENT_QUOTES, 'UTF-8')
                . ')</span>';
        $title       = htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8');

        $metaRows = '<strong>' . $p['entityLabel'] . ':</strong> ' . htmlspecialchars($entity['name'] ?? '') . '<br>';
        if (!empty($entity['phone']))   $metaRows .= '<strong>رقم الهاتف:</strong> ' . htmlspecialchars($entity['phone']) . '<br>';
        if (!empty($entity['address'])) $metaRows .= '<strong>العنوان:</strong> ' . htmlspecialchars($entity['address']) . '<br>';
        if (!empty($entity['email']))   $metaRows .= '<strong>البريد الإلكتروني:</strong> ' . htmlspecialchars($entity['email']) . '<br>';
        $metaRows .= '<strong>تاريخ الإصدار:</strong> ' . $now;

        $html = '
        <table class="header-table">
          <tr>
            <td style="vertical-align: top; width: 60%;">
              <div class="header-title">' . $title . '</div>
              <div class="header-subtitle">' . htmlspecialchars($storeName) . '</div>
            </td>
            <td class="header-meta" style="vertical-align: top; width: 40%;">
              ' . $metaRows . '
            </td>
          </tr>
        </table>';

        $html .= '
        <table class="summary-table">
          <tr>
            <td><div class="summary-label">عدد الحركات</div><div class="summary-val">' . count($entries) . '</div></td>
            <td><div class="summary-label">إجمالي المدين</div><div class="summary-val">' . $this->fmtCurrency($totalDebit) . '</div></td>
            <td><div class="summary-label">إجمالي الدائن</div><div class="summary-val">' . $this->fmtCurrency($totalCredit) . '</div></td>
            <td><div class="summary-label">الرصيد الحالي</div><div class="summary-val" style="color: #000;">' . $balAbs . $balWordHtml . '</div></td>
          </tr>
        </table>';

        $html .= '
        <table class="ledger-table">
          <thead><tr>
            <th class="col-num center">#</th><th class="col-date">التاريخ</th><th class="col-desc">البيان</th>
            <th class="col-debit">مدين</th><th class="col-credit">دائن</th><th class="col-bal">الرصيد</th>
          </tr></thead><tbody>';

        foreach ($entries as $i => $row) {
            $isDebit   = ((float)($row['debit'] ?? 0)) > 0;
            $isCredit  = ((float)($row['credit'] ?? 0)) > 0;
            $rowBal    = (float)($row['balance'] ?? 0);
            $balLabel  = $rowBal > 0 ? $p['balDebitWord'] : ($rowBal < 0 ? $p['balCreditWord'] : '');
            $desc = htmlspecialchars($row['description'] ?? '—');
            if (($row['type'] ?? '') === 'initial') {
                $desc .= ' <span style="font-size:10px; color:#555;">(رصيد مبدئي)</span>';
            }
            $html .= '
            <tr>
              <td class="col-num">' . ($i + 1) . '</td>
              <td class="col-date" dir="ltr"><span class="date-value" dir="ltr">' . $this->fmtDate($row['date'] ?? null) . '</span></td>
              <td class="col-desc">' . $desc . '</td>
              <td class="col-debit">' . ($isDebit ? $this->fmtCurrency((float)$row['debit']) : '—') . '</td>
              <td class="col-credit">' . ($isCredit ? $this->fmtCurrency((float)$row['credit']) : '—') . '</td>
              <td class="col-bal">' . $this->fmtCurrency(abs($rowBal)) . ' <span style="font-size:10px; font-weight:normal; color:#555;">' . $balLabel . '</span></td>
            </tr>';
        }

        $html .= '</tbody><tfoot><tr>
              <td colspan="3" style="text-align: right;">الإجمالي الكلي</td>
              <td class="col-debit">' . $this->fmtCurrency($totalDebit) . '</td>
              <td class="col-credit">' . $this->fmtCurrency($totalCredit) . '</td>
              <td class="col-bal">' . $balAbs . ' <span style="font-size:10px; font-weight:normal; color:#555;">' . $balWord . '</span></td>
            </tr></tfoot></table>';

        $html .= '
        <table class="footer-table"><tr>
            <td style="text-align: right; width: 50%;">تم إنشاء هذا التقرير رسمياً بواسطة النظام الخاص بنا — ' . htmlspecialchars($storeName) . '</td>
            <td style="text-align: left; width: 50%;">' . $now . '</td>
        </tr></table>';

        return $html;
    }

    /**
     * إنتاج PDF وإرسالها مباشرة للمتصفح.
     */
    public function outputPdf(string $html, string $filename): void
    {
        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML('<style>' . $this->getCss() . '</style>' . $html);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
        exit;
    }
}
