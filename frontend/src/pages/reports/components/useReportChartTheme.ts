import useThemeStore from '../../../store/themeStore'

export interface ReportChartTheme {
  axis: string
  grid: string
  tooltipBackground: string
  tooltipBorder: string
  tooltipText: string
  cursor: string
  legend: string
}

const lightTheme: ReportChartTheme = {
  axis: '#4b5563',
  grid: '#e5e7eb',
  tooltipBackground: '#ffffff',
  tooltipBorder: '#d1d5db',
  tooltipText: '#111827',
  cursor: '#f3f4f6',
  legend: '#374151',
}

const darkTheme: ReportChartTheme = {
  axis: '#cbd5e1',
  grid: '#374151',
  tooltipBackground: '#1f2937',
  tooltipBorder: '#4b5563',
  tooltipText: '#f3f4f6',
  cursor: '#374151',
  legend: '#e5e7eb',
}

export default function useReportChartTheme(): ReportChartTheme {
  const mode = useThemeStore(state => state.mode)
  return mode === 'dark' ? darkTheme : lightTheme
}
