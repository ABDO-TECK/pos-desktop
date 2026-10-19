// Keep Arabic labels while forcing Latin/English numerals (1234567890).
const AR_LATIN = 'ar-EG-u-nu-latn'
const EMPTY_VALUE = '—'
const RTL_EMBED = '\u202B'
const DIRECTIONAL_POP = '\u202C'
const STATEMENT_DATE_FORMATTER = new Intl.DateTimeFormat(AR_LATIN, {
  month: 'short',
  day: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  hour12: true,
})

type DateInput = string | number | Date | null | undefined

const toNumber = (value: string | number | null | undefined): number => {
  const parsed = typeof value === 'number' ? value : Number.parseFloat(value ?? '')
  return Number.isFinite(parsed) ? parsed : 0
}

const toDate = (value: DateInput): Date | null => {
  if (value === null || value === undefined || value === '') return null
  const parsed = value instanceof Date ? new Date(value.getTime()) : new Date(value)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

export const formatCurrency = (amount: string | number | null | undefined): string =>
  new Intl.NumberFormat(AR_LATIN, { style: 'currency', currency: 'EGP' }).format(toNumber(amount))

export const formatNumber = (num: string | number | null | undefined): string =>
  new Intl.NumberFormat(AR_LATIN).format(toNumber(num))

export const formatPercent = (num: string | number | null | undefined): string =>
  `${new Intl.NumberFormat(AR_LATIN).format(toNumber(num))}%`

export const roundCurrency = (value: string | number | null | undefined): number =>
  Math.round(toNumber(value) * 100) / 100

export const formatDate = (dateValue: DateInput): string => {
  const date = toDate(dateValue)
  if (!date) return EMPTY_VALUE

  return new Intl.DateTimeFormat(AR_LATIN, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  }).format(date)
}

/**
 * A deterministic LTR date for ledger columns. Arabic month names are kept in
 * an RTL embedding so the outer LTR order cannot reverse their letters.
 */
export const formatStatementDate = (dateValue: DateInput): string => {
  const date = toDate(dateValue)
  if (!date) return EMPTY_VALUE

  const parts = STATEMENT_DATE_FORMATTER.formatToParts(date)
  const valueOf = (type: Intl.DateTimeFormatPartTypes): string =>
    parts.find((part) => part.type === type)?.value ?? ''

  const month = `${RTL_EMBED}${valueOf('month')}${DIRECTIONAL_POP}`
  const dayPeriod = `${RTL_EMBED}${valueOf('dayPeriod')}${DIRECTIONAL_POP}`

  return `${valueOf('day')}/${month}/${valueOf('year')} - ${valueOf('hour')}:${valueOf('minute')} ${dayPeriod}`
}

export const formatShortDate = (dateValue: DateInput): string => {
  const date = toDate(dateValue)
  if (!date) return EMPTY_VALUE

  return new Intl.DateTimeFormat(AR_LATIN, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  }).format(date)
}

export const formatTime = (dateValue: DateInput): string => {
  const date = toDate(dateValue)
  if (!date) return EMPTY_VALUE

  return new Intl.DateTimeFormat(AR_LATIN, {
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  }).format(date)
}
