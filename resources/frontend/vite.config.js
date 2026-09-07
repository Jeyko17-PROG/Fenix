import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import laravel from 'laravel-vite-plugin'

// https://vite.dev/config/
export default defineConfig({
  // Los íconos/PWA assets ya no viven en una carpeta public/ propia de este
  // proyecto: se movieron directo a backend/public/ (la raíz real del sitio
  // servida por Apache), así que no hay nada que Vite deba copiar aquí.
  publicDir: false,
  server: {
    port: 5173,
    host: true, // expone el servidor en la red local (LAN) para abrir desde el celular vía IP
  },
  plugins: [
    // Integra este Vite con Laravel: compila a backend/public/build y expone
    // el helper @vite() para resources/views/app.blade.php. En dev, Laravel
    // sirve la página y este plugin inyecta el cliente HMR del server de Vite
    // (ya no hace falta el proxy manual de /api ni /storage: al correr todo
    // bajo el mismo dominio de Laravel, esas rutas ya son del propio backend).
    laravel({
      input: ['src/main.jsx'],
      publicDirectory: '../../public',
      buildDirectory: 'build',
      refresh: true,
    }),
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      // Registro manual (ver main.jsx con virtual:pwa-register): la vista es
      // un Blade template, no el index.html de Vite, así que la inyección
      // automática de <script>/<link> de este plugin no tiene efecto.
      injectRegister: false,
      // El service worker debe quedar en la RAÍZ del sitio (backend/public/),
      // no dentro de public/build/, para que su alcance ("scope") cubra toda
      // la app y no solo la carpeta de assets.
      outDir: '../../public',
      // Ya no hace falta includeAssets: los íconos viven permanentemente en
      // backend/public/ (ver publicDir arriba), no dependen del build de Vite.
      manifest: {
        name: 'Fénix · Velocidad y eficiencia en tu punto de venta',
        short_name: 'Fénix',
        description: 'Sistema de Gestión de Clientes, Inventario, Facturación y Reservas.',
        lang: 'es',
        theme_color: '#e04a0a',
        background_color: '#0f172a',
        display: 'standalone',
        orientation: 'portrait',
        scope: '/',
        start_url: '/',
        icons: [
          { src: 'pwa-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: 'pwa-512x512.png', sizes: '512x512', type: 'image/png' },
          { src: 'pwa-maskable-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
    }),
  ],
})
