import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

/**
 * يبحث عن زوج mkcert في ../certs (مثال: 192.168.1.22+3.pem و 192.168.1.22+3-key.pem).
 * عند وجودهما يُفعَّل HTTPS على Vite (مثلاً https://192.168.x.x:5173 من الهاتف).
 * بدون ملفات → يبقى الخادم HTTP كالسابق.
 */
function findLanTlsCerts() {
  const certDir = path.resolve(__dirname, '../certs')
  if (!fs.existsSync(certDir)) return null
  const names = fs.readdirSync(certDir)
  const keyFile = names.find((n) => n.endsWith('-key.pem'))
  if (!keyFile) return null
  const certName = keyFile.replace(/-key\.pem$/i, '.pem')
  if (!names.includes(certName)) return null
  const keyPath = path.join(certDir, keyFile)
  const certPath = path.join(certDir, certName)
  try {
    return {
      key: fs.readFileSync(keyPath),
      cert: fs.readFileSync(certPath),
    }
  } catch {
    return null
  }
}

const httpsOptions = findLanTlsCerts()

export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: null,
      manifest: {
        name: 'POS System',
        short_name: 'POS',
        description: 'Point of Sale System',
        theme_color: '#ffffff',
        icons: [
          {
            src: 'pwa-192x192.png',
            sizes: '192x192',
            type: 'image/png'
          },
          {
            src: 'pwa-512x512.png',
            sizes: '512x512',
            type: 'image/png'
          }
        ]
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg}'],
        cleanupOutdatedCaches: true,
        // Don't cache navigation requests — let them always go to network
        // This prevents the SW from serving stale HTML that causes refresh loops
        navigateFallback: null,
        // Exclude API calls and PHP endpoints from SW interception
        navigateFallbackDenylist: [/^\/api\//, /\.php$/],
        runtimeCaching: [
          {
            // API calls: always network-first
            urlPattern: /^.*\/api\/.*/,
            handler: 'NetworkOnly',
          },
          {
            // PHP endpoints (sign-message, adminer, etc.)
            urlPattern: /\.php/,
            handler: 'NetworkOnly',
          },
        ],
      }
    })
  ],
  server: {
    port: 5173,
    host: '0.0.0.0',
    ...(httpsOptions ? { https: httpsOptions } : {}),
    proxy: {
      '/api': {
        target: 'http://localhost/pos/backend',
        changeOrigin: true,
      },
      '/pos/backend/sign-message.php': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
  build: {
    chunkSizeWarningLimit: 2000,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('recharts')) return 'charts'
          if (id.includes('react-router')) return 'router'
          if (id.includes('@zxing')) return 'barcode'
          if (id.includes('react-hot-toast')) return 'toast'
          if (id.includes('axios')) return 'http'
          if (id.includes('zustand')) return 'store'
          if (id.includes('node_modules')) return 'vendor'
        },
      },
    },
  },
})
