import { Component, type ErrorInfo, type ReactNode } from 'react'
import { logError } from '../../utils/clientLogger'

interface Props {
  children: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

/**
 * ErrorBoundary — يلتقط أخطاء React runtime ويعرض واجهة بديلة
 * بدلاً من تعطّل التطبيق بالكامل.
 */
export default class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
    console.error('[ErrorBoundary]', error, errorInfo)

    // إرسال الخطأ إلى الباك إند عبر clientLogger
    logError(error?.message || 'React component error', {
      stack: error?.stack,
      component: errorInfo?.componentStack,
    })
  }

  handleReset = () => {
    this.setState({ hasError: false, error: null })
  }

  render() {
    if (this.state.hasError) {
      return (
        <div style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          minHeight: '100vh',
          padding: '2rem',
          background: 'var(--bg, #f8f9fa)',
          direction: 'rtl',
          fontFamily: 'inherit',
        }}>
          <div style={{
            maxWidth: '480px',
            width: '100%',
            background: 'var(--surface, #fff)',
            borderRadius: '1rem',
            padding: '2rem',
            boxShadow: '0 8px 32px rgba(0,0,0,0.08)',
            border: '1px solid var(--border, #e5e7eb)',
            textAlign: 'center',
          }}>
            <div style={{ fontSize: '3rem', marginBottom: '1rem' }}>⚠️</div>
            <h2 style={{
              fontWeight: 700,
              fontSize: '1.25rem',
              marginBottom: '0.75rem',
              color: 'var(--text, #1f2937)',
            }}>
              حدث خطأ غير متوقع
            </h2>
            <p style={{
              fontSize: '0.9rem',
              color: 'var(--text-muted, #6b7280)',
              lineHeight: 1.6,
              marginBottom: '1.5rem',
            }}>
              نعتذر عن هذا الخلل. يمكنك المحاولة مرة أخرى أو تحديث الصفحة.
            </p>

            {this.state.error && (
              <details style={{
                textAlign: 'left',
                marginBottom: '1.5rem',
                background: 'rgba(239,68,68,0.05)',
                border: '1px solid rgba(239,68,68,0.2)',
                borderRadius: '0.5rem',
                padding: '0.75rem',
              }}>
                <summary style={{
                  cursor: 'pointer',
                  fontSize: '0.82rem',
                  fontWeight: 600,
                  color: 'var(--danger, #dc2626)',
                  marginBottom: '0.5rem',
                  direction: 'rtl',
                  textAlign: 'right',
                }}>
                  تفاصيل الخطأ
                </summary>
                <pre style={{
                  fontSize: '0.75rem',
                  color: '#991b1b',
                  whiteSpace: 'pre-wrap',
                  wordBreak: 'break-word',
                  margin: 0,
                  direction: 'ltr',
                }}>
                  {this.state.error.toString()}
                </pre>
              </details>
            )}

            <div style={{ display: 'flex', gap: '0.75rem', justifyContent: 'center' }}>
              <button
                onClick={this.handleReset}
                style={{
                  padding: '0.6rem 1.5rem',
                  borderRadius: '0.5rem',
                  border: 'none',
                  background: 'var(--primary, #22c55e)',
                  color: '#fff',
                  fontWeight: 600,
                  fontSize: '0.9rem',
                  cursor: 'pointer',
                  fontFamily: 'inherit',
                  transition: 'opacity .15s',
                }}
              >
                إعادة المحاولة
              </button>
              <button
                onClick={() => window.location.reload()}
                style={{
                  padding: '0.6rem 1.5rem',
                  borderRadius: '0.5rem',
                  border: '1px solid var(--border, #e5e7eb)',
                  background: 'var(--surface, #fff)',
                  color: 'var(--text, #1f2937)',
                  fontWeight: 600,
                  fontSize: '0.9rem',
                  cursor: 'pointer',
                  fontFamily: 'inherit',
                  transition: 'opacity .15s',
                }}
              >
                تحديث الصفحة
              </button>
            </div>
          </div>
        </div>
      )
    }

    return this.props.children
  }
}
