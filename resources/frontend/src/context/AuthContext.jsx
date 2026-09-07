import { createContext, useContext, useEffect, useState } from 'react'
import { api, getToken, setToken } from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [cargando, setCargando] = useState(true)

  // Al montar, si hay token guardado, recupera el usuario.
  useEffect(() => {
    async function init() {
      if (!getToken()) {
        setCargando(false)
        return
      }
      try {
        const me = await api('/me')
        setUser(me)
      } catch {
        setToken(null)
      } finally {
        setCargando(false)
      }
    }
    init()
  }, [])

  async function login(email, password) {
    const data = await api('/login', { method: 'POST', body: { email, password } })
    setToken(data.token)
    setUser(data.user)
    return data.user
  }

  async function register(payload) {
    const data = await api('/register', { method: 'POST', body: payload })
    // Cuenta bloqueada desde el registro: no llega token hasta activarla con
    // el código de 6 dígitos que entrega el super-admin (ver activar()).
    if (data.token) {
      setToken(data.token)
      setUser(data.user)
    }
    return data
  }

  async function activar(email, codigo) {
    const data = await api('/activar', { method: 'POST', body: { email, codigo } })
    setToken(data.token)
    setUser(data.user)
    return data.user
  }

  async function forgotPassword(email) {
    return api('/forgot-password', { method: 'POST', body: { email } })
  }

  async function resetPassword(payload) {
    return api('/reset-password', { method: 'POST', body: payload })
  }

  // "Mis negocios": la misma persona con varias cuentas/negocios vinculados.
  async function misNegocios() {
    return api('/cuenta/mis-negocios')
  }

  async function vincularNegocio(email, password) {
    return api('/cuenta/vincular-negocio', { method: 'POST', body: { email, password } })
  }

  // Crea un negocio adicional para el mismo dueño (reutiliza sus datos personales).
  async function crearNegocio(payload) {
    return api('/cuenta/nuevo-negocio', { method: 'POST', body: payload })
  }

  async function desvincularNegocio(negocioId) {
    return api(`/cuenta/negocios/${negocioId}`, { method: 'DELETE' })
  }

  // Cambia la sesión activa a otro negocio vinculado (sin pedir contraseña de nuevo).
  async function entrarNegocio(negocioId) {
    const data = await api(`/cuenta/negocios/${negocioId}/entrar`, { method: 'POST', body: {} })
    setToken(data.token)
    setUser(data.user)
    return data.user
  }

  async function logout() {
    try {
      await api('/logout', { method: 'POST' })
    } catch {
      // ignorar errores de red al cerrar sesión
    }
    setToken(null)
    setUser(null)
  }

  return (
    <AuthContext.Provider value={{ user, setUser, cargando, login, register, activar, forgotPassword, resetPassword, logout, misNegocios, vincularNegocio, crearNegocio, desvincularNegocio, entrarNegocio }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth debe usarse dentro de <AuthProvider>')
  return ctx
}
