import { useState, type ChangeEventHandler, type FocusEventHandler, type InputHTMLAttributes } from 'react'

type NumericValue = string | number | null | undefined

export interface NumericInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'value'> {
  value?: NumericValue
  onChange?: ChangeEventHandler<HTMLInputElement>
  onFocus?: FocusEventHandler<HTMLInputElement>
  onBlur?: FocusEventHandler<HTMLInputElement>
}

function stringify(value: NumericValue): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'number' && Number.isNaN(value)) return ''
  return String(value)
}

/**
 * A controlled number field that keeps the user's draft while it is focused.
 * This prevents a parent normalizer such as `parseFloat(value) || 0` from
 * replacing an empty/transient value and moving the caret while the user types.
 */
export default function NumericInput({ value, onChange, onFocus, onBlur, ...props }: NumericInputProps) {
  const [draft, setDraft] = useState(() => stringify(value))
  const [isFocused, setIsFocused] = useState(false)

  const handleFocus: FocusEventHandler<HTMLInputElement> = event => {
    setIsFocused(true)
    setDraft(event.currentTarget.value)
    onFocus?.(event)
  }

  const handleChange: ChangeEventHandler<HTMLInputElement> = event => {
    setDraft(event.target.value)
    onChange?.(event)
  }

  const handleBlur: FocusEventHandler<HTMLInputElement> = event => {
    setIsFocused(false)
    // Reconcile the draft with the normalized value once editing is complete.
    // While focused, the raw value is intentionally preserved for caret safety.
    setDraft(stringify(value))
    onBlur?.(event)
  }

  return (
    <input
      {...props}
      type="number"
      value={isFocused ? draft : stringify(value)}
      onFocus={handleFocus}
      onChange={handleChange}
      onBlur={handleBlur}
    />
  )
}
