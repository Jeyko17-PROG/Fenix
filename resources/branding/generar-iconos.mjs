import sharp from 'sharp'
import { fileURLToPath } from 'url'
import path from 'path'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const src = path.join(__dirname, '..', 'public', 'logo-fenix.png')
const pub = path.join(__dirname, '..', 'public')

// Color de fondo de la marca (mismo background_color del manifest / slate-950).
const BG_OSCURO = { r: 15, g: 23, b: 42, alpha: 1 }

async function iconoTransparente(size, out) {
  await sharp(src)
    .resize(size, size, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png()
    .toFile(path.join(pub, out))
  console.log('OK', out)
}

async function iconoConFondo(size, out, { padding = 0.14 } = {}) {
  const logoSize = Math.round(size * (1 - padding * 2))
  const logo = await sharp(src)
    .resize(logoSize, logoSize, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .toBuffer()

  await sharp({ create: { width: size, height: size, channels: 4, background: BG_OSCURO } })
    .composite([{ input: logo, gravity: 'center' }])
    .png()
    .toFile(path.join(pub, out))
  console.log('OK', out)
}

await iconoTransparente(512, 'pwa-512x512.png')
await iconoTransparente(192, 'pwa-192x192.png')
// Maskable: el SO recorta a un circulo/redondeado, necesita zona de seguridad (~padding 20%).
await iconoConFondo(512, 'pwa-maskable-512x512.png', { padding: 0.2 })
// iOS agrega su propia mascara redondeada; fondo solido evita bordes transparentes raros.
await iconoConFondo(180, 'apple-touch-icon.png', { padding: 0.12 })
// Favicon: PNG chico, transparente, funciona en todos los navegadores modernos.
await iconoTransparente(64, 'favicon.png')
await iconoTransparente(32, 'favicon-32.png')
