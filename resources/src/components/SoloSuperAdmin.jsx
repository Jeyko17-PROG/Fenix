import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

/** Restringe una ruta al Super Administrador. Los demás se redirigen al dashboard. */
export default function SoloSuperAdmin({ children }) {
  const { user } = useAuth()
  if (!user?.es_super_admin) return <Navigate to="/dashboard" replace />
  return children
}
