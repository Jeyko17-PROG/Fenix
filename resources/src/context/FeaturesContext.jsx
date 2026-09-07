import { createContext, useContext, useEffect, useState, useCallback } from 'react'
import { api, getToken } from '../api/client'
import { useAuth } from './AuthContext'

const FeaturesContext = createContext(null)

export function FeaturesProvider({ children }) {
  const { user } = useAuth()
  const [funcs, setFuncs] = useState({})   // clave => ACTIVADA | RESTRINGIDA | DESACTIVADA
  const [catalogo, setCatalogo] = useState({})
  const [cargando, setCargando] = useState(true)

  const cargar = useCallback(async () => {
    if (!getToken()) { setCargando(false); return }
    try {
      const d = await api('/mis-funcionalidades')
      setFuncs(d.funcionalidades || {})
      setCatalogo(d.catalogo || {})
    } catch { /* ignore */ } finally { setCargando(false) }
  }, [])

  useEffect(() => { cargar() }, [user, cargar])

  // Estado de una funcionalidad (el super-admin siempre la tiene activa).
  const estado = useCallback((clave) => {
    if (user?.es_super_admin) return 'ACTIVADA'
    return funcs[clave] ?? 'ACTIVADA'
  }, [funcs, user])

  const activa = useCallback((clave) => estado(clave) === 'ACTIVADA', [estado])
  const visible = useCallback((clave) => estado(clave) !== 'DESACTIVADA', [estado])

  return (
    <FeaturesContext.Provider value={{ funcs, catalogo, cargando, estado, activa, visible, recargar: cargar }}>
      {children}
    </FeaturesContext.Provider>
  )
}

export function useFeatures() {
  const ctx = useContext(FeaturesContext)
  if (!ctx) throw new Error('useFeatures debe usarse dentro de <FeaturesProvider>')
  return ctx
}
