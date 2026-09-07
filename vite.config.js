import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import laravel from 'laravel-vite-plugin'

// https://vite.dev/config/
export default defineConfig({
  // public/ es la raíz real del sitio que sirve Laravel/Apache, no una carpeta
  // de assets estáticos que Vite deba copiar al build: se desactiva para que
  // Vite no intente duplicar su contenido dentro de public/build.
  publicDir: false,
  server: {
    port: 5173,
    host: true, // expone el servidor en la red local (LAN) para abrir desde el celular vía IP
  },
  plugins: [
    // Integra Vite con Laravel: compila a public/build y habilita los helpers
    // @vite()/@viteReactRefresh de Blade (ver resources/views/welcome.blade.php).
    // En dev, Laravel sirve la página y este plugin inyecta el cliente HMR del
    // servidor de Vite — todo bajo el mismo dominio, así que /api y /storage
    // son rutas del propio backend y no hace falta ningún proxy.
    laravel({
      input: ['resources/css/app.css', 'resources/src/main.jsx'],
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
      // El service worker y el manifest deben quedar en la RAÍZ del sitio
      // (public/), no dentro de public/build/, para que su alcance ("scope")
      // cubra toda la app y no solo la carpeta de assets.
      outDir: 'public',
      // Sin esto, workbox tomaría outDir (= public/) como directorio a
      // precachear y arrastraría los íconos, imágenes y hasta public/storage
      // (archivos subidos por usuarios). Solo interesan los assets con hash
      // que genera el build.
      workbox: {
        globDirectory: 'public',
        globPatterns: ['build/**/*.{js,css,woff2}'],
      },
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
