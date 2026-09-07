// Cliente HTTP centralizado para la API de Logix.
//
// - Web/dev: VITE_API_URL vacío → se usa ruta relativa `/api/*` (proxy de Vite o mismo dominio).
// - App nativa (Capacitor) o frontend desplegado aparte: define VITE_API_URL con la URL
//   absoluta del backend (ej. https://api.tudominio.com); las apps móviles NO tienen proxy.

const TOKEN_KEY = 'logix_token'

// Base absoluta del backend (sin barra final). Vacío = mismo origen.
export const API_BASE = (import.meta.env.VITE_API_URL || '').replace(/\/+$/, '')

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

/**
 * Realiza una petición a la API.
 * @param {string} path  ej: '/login'
 * @param {object} options  { method, body, isForm }
 */
export async function api(path, { method = 'GET', body, isForm = false } = {}) {
  const headers = { Accept: 'application/json' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`

  let payload
  if (isForm) {
    payload = body // FormData: el navegador pone el Content-Type con boundary
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  const res = await fetch(`${API_BASE}/api${path}`, { method, headers, body: payload })

  // 204 sin contenido
  const data = res.status === 204 ? null : await res.json().catch(() => null)

  if (!res.ok) {
    // Sesión expirada o revocada (401): limpia el token y vuelve al login,
    // en vez de dejar la app "colgada" mostrando errores de No autenticado.
    const rutasPublicas = ['/login', '/bienvenida', '/reservar', '/restablecer']
    if (res.status === 401 && !rutasPublicas.some((r) => window.location.pathname.startsWith(r))) {
      setToken(null)
      window.location.href = '/login'
    }
    // Membresía vencida: el backend bloquea las funciones operativas con 402.
    // Se lleva al usuario a la pantalla de planes/pago para que renueve.
    if (res.status === 402 && data?.codigo === 'MEMBRESIA_VENCIDA' && !window.location.pathname.startsWith('/planes')) {
      window.location.href = '/planes?vencida=1'
    }
    // Límite del plan alcanzado (ej. tope de clientes): en vez de dejar solo
    // un mensaje de error en el formulario, se lleva sí o sí a Planes para
    // que compre/actualice — igual de forzoso que la membresía vencida.
    // OJO: PortalController (reserva pública, sin sesión) también puede
    // devolver limite_alcanzado — ese visitante anónimo NO debe terminar en
    // /planes (pantalla interna del dueño), por eso se excluyen rutas públicas.
    if (res.status === 403 && data?.limite_alcanzado
      && !rutasPublicas.some((r) => window.location.pathname.startsWith(r))
      && !window.location.pathname.startsWith('/planes')) {
      window.location.href = '/planes?limite=1'
    }
    const message = data?.message || 'Ocurrió un error en la solicitud.'
    throw { status: res.status, message, codigo: data?.codigo, errors: data?.errors || {} }
  }

  return data
}

/**
 * Descarga un archivo desde la API (con el token de sesión) y dispara la descarga en el navegador.
 */
export async function descargarArchivo(path, nombreSugerido = 'archivo') {
  const headers = { Accept: '*/*' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`

  const res = await fetch(`${API_BASE}/api${path}`, { headers })
  if (!res.ok) throw { status: res.status, message: 'No se pudo descargar el archivo.' }

  const blob = await res.blob()
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = nombreSugerido
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}
