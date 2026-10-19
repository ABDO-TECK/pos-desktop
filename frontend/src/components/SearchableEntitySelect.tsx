import { useEffect, useMemo, useState } from 'react'

export interface SearchableEntityOption {
  id: number
  name: string
  phone?: string | null
}

interface SearchableEntitySelectProps<TEntity extends SearchableEntityOption> {
  value: string
  onChange: (value: string, option: TEntity | null) => void
  searchOptions: (search: string, signal?: AbortSignal) => Promise<TEntity[]>
  loadOption?: (id: number, signal?: AbortSignal) => Promise<TEntity | null>
  onOptionResolved?: (option: TEntity | null) => void
  searchPlaceholder: string
  emptyLabel: string
  loadingLabel: string
  getOptionLabel?: (option: TEntity) => string
  disabled?: boolean
}

export default function SearchableEntitySelect<TEntity extends SearchableEntityOption>({
  value,
  onChange,
  searchOptions,
  loadOption,
  onOptionResolved,
  searchPlaceholder,
  emptyLabel,
  loadingLabel,
  getOptionLabel,
  disabled = false,
}: SearchableEntitySelectProps<TEntity>) {
  const [search, setSearch] = useState('')
  const [options, setOptions] = useState<TEntity[]>([])
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(async () => {
      setLoading(true)
      try {
        const nextOptions = await searchOptions(search.trim(), controller.signal)
        setOptions((previous) => {
          const selected = previous.find((option) => String(option.id) === value)
          if (selected && !nextOptions.some((option) => option.id === selected.id)) {
            return [selected, ...nextOptions]
          }
          return nextOptions
        })
      } catch (error) {
        if (!controller.signal.aborted) {
          console.error('Entity search failed', error)
        }
      } finally {
        if (!controller.signal.aborted) setLoading(false)
      }
    }, 250)

    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [search, searchOptions, value])

  useEffect(() => {
    if (!value || !loadOption || options.some((option) => String(option.id) === value)) return

    const controller = new AbortController()
    void loadOption(Number(value), controller.signal).then((selected) => {
      if (!selected || controller.signal.aborted) return
      setOptions((previous) => previous.some((option) => option.id === selected.id)
        ? previous
        : [selected, ...previous])
    }).catch((error) => {
      if (!controller.signal.aborted) console.error('Selected entity lookup failed', error)
    })

    return () => controller.abort()
  }, [loadOption, options, value])

  const selectedOption = useMemo(
    () => options.find((option) => String(option.id) === value) ?? null,
    [options, value],
  )

  useEffect(() => {
    onOptionResolved?.(selectedOption)
  }, [onOptionResolved, selectedOption])

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
      <input
        type="search"
        className="input"
        value={search}
        onChange={(event) => setSearch(event.target.value)}
        placeholder={searchPlaceholder}
        disabled={disabled}
      />
      <select
        className="input"
        value={value}
        onChange={(event) => {
          const nextValue = event.target.value
          const option = options.find((candidate) => String(candidate.id) === nextValue) ?? null
          onChange(nextValue, option)
        }}
        disabled={disabled}
      >
        <option value="">{loading && options.length === 0 ? loadingLabel : emptyLabel}</option>
        {options.map((option) => (
          <option key={option.id} value={option.id}>
            {getOptionLabel
              ? getOptionLabel(option)
              : `${option.name}${option.phone ? ` — ${option.phone}` : ''}`}
          </option>
        ))}
        {value && !selectedOption && <option value={value}>{loadingLabel}</option>}
      </select>
    </div>
  )
}
