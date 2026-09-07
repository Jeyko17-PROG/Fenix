// Resuelve la URL pública del portal de reservas.
//
// El QR y los enlaces NO deben apuntar a localhost/127.0.0.1, porque ningún
// dispositivo externo podría abrirlos. Prioridad:
//   1) URL pública guardada por el usuario (panel de reservas).
//   2) Variable de entorno VITE_PUBLIC_URL (definida al desplegar/tunelizar).
//   3) El origen actual (solo sirve si abriste la app por IP de red o dominio).

const KEY = 'logix_public_url'

const limpiar = (u) => (u || '').trim().replace(/\/+$/, '')

export function getPublicBaseUrl() {
  const guardada = limpiar(localStorage.getItem(KEY))
  const env = limpiar(import.meta.env.VITE_PUBLIC_URL)
  return guardada || env || limpiar(window.location.origin)
}

export function setPublicBaseUrl(url) {
  const v = limpiar(url)
  if (v) localStorage.setItem(KEY, v)
  else localStorage.removeItem(KEY)
}

export function getReservasUrl(slug = null) {
  const base = getPublicBaseUrl() + '/reservar'
  return slug ? `${base}/${slug}` : base
}

// ¿La URL resuelta sigue siendo local (no servirá fuera del computador)?
export function esUrlLocal(url = getPublicBaseUrl()) {
  return /localhost|127\.0\.0\.1|0\.0\.0\.0/i.test(url)
}
