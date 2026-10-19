import { useMemo } from 'react'
import {
  BarcodeFormat,
  EncodeHintType,
  QRCodeWriter,
  type BitMatrix,
} from '@zxing/library'

interface LocalQrCodeProps {
  value: string
  size?: number
  title?: string
}

function buildQrPath(matrix: BitMatrix): string {
  const modules: string[] = []

  for (let y = 0; y < matrix.getHeight(); y += 1) {
    for (let x = 0; x < matrix.getWidth(); x += 1) {
      if (matrix.get(x, y)) {
        modules.push(`M${x} ${y}h1v1H${x}z`)
      }
    }
  }

  return modules.join(' ')
}

export default function LocalQrCode({ value, size = 140, title = 'QR Code' }: LocalQrCodeProps) {
  const qr = useMemo(() => {
    if (!value.trim()) return null

    try {
      const hints = new Map<EncodeHintType, string>([
        [EncodeHintType.ERROR_CORRECTION, 'M'],
        [EncodeHintType.MARGIN, '4'],
      ])
      const matrix = new QRCodeWriter().encode(value, BarcodeFormat.QR_CODE, 0, 0, hints)
      return {
        width: matrix.getWidth(),
        height: matrix.getHeight(),
        path: buildQrPath(matrix),
      }
    } catch (error) {
      console.error('[QR] Local QR generation failed:', error)
      return null
    }
  }, [value])

  if (!qr) {
    return (
      <span role="img" aria-label={title} style={{ color: 'var(--text-muted)', fontSize: '0.75rem' }}>
        QR غير متاح
      </span>
    )
  }

  return (
    <svg
      role="img"
      aria-label={title}
      viewBox={`0 0 ${qr.width} ${qr.height}`}
      width={size}
      height={size}
      shapeRendering="crispEdges"
      focusable="false"
    >
      <title>{title}</title>
      <rect width={qr.width} height={qr.height} fill="#ffffff" />
      <path d={qr.path} fill="#000000" />
    </svg>
  )
}

