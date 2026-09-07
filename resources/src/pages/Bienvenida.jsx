import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

const CARACTERISTICAS = [
  { icon: '👥', label: 'Clientes' },
  { icon: '📦', label: 'Inventario' },
  { icon: '🧾', label: 'Facturación' },
  { icon: '📅', label: 'Reservas' },
]

// Pantalla de bienvenida (entrada pública de la app). Permite elegir entre
// Iniciar Sesión y Registrarse, sin reemplazar los formularios existentes.
export default function Bienvenida() {
  const navigate = useNavigate()
  const { user, cargando } = useAuth()

  // Si ya hay sesión activa, ir directo al sistema.
  useEffect(() => {
    if (!cargando && user) navigate('/dashboard', { replace: true })
  }, [user, cargando, navigate])

  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-slate-950 via-[#2a1206] to-slate-950 px-4 py-10">
      <div className="w-full max-w-md text-center">
        {/* Logo */}
        <img
          src="/logo-fenix.png"
          alt="Fénix"
          className="h-28 w-28 mx-auto object-contain drop-shadow-2xl"
          onError={(e) => { e.currentTarget.style.display = 'none' }}
        />

        {/* Nombre y descripción */}
        <h1 className="mt-5 text-4xl font-extrabold tracking-wide text-white">FÉNIX</h1>
        <p className="mt-2 text-orange-400 text-xs font-semibold uppercase tracking-[0.2em]">
          Velocidad y eficiencia en tu punto de venta
        </p>
        <p className="mt-3 text-slate-300 text-base leading-relaxed">
          Sistema de Gestión de Clientes, Inventario, Facturación y Reservas.
        </p>

        {/* Mini-características */}
        <div className="mt-7 grid grid-cols-4 gap-2">
          {CARACTERISTICAS.map((c) => (
            <div key={c.label} className="rounded-xl bg-white/5 border border-white/10 py-3">
              <div className="text-2xl">{c.icon}</div>
              <div className="text-[11px] text-slate-400 mt-1">{c.label}</div>
            </div>
          ))}
        </div>

        {/* Botones principales */}
        <div className="mt-9 space-y-3">
          <button
            onClick={() => navigate('/login')}
            className="w-full rounded-xl bg-gradient-to-r from-red-600 via-orange-600 to-amber-500 hover:opacity-95 text-white font-semibold py-3 shadow-lg shadow-orange-900/40 transition"
          >
            Iniciar Sesión
          </button>
          <button
            onClick={() => navigate('/login?modo=registro')}
            className="w-full rounded-xl bg-white/10 hover:bg-white/15 border border-white/20 text-white font-semibold py-3 transition"
          >
            Registrarse
          </button>
        </div>

        <p className="mt-8 text-slate-500 text-xs">Fénix · Velocidad y eficiencia en tu punto de venta</p>
      </div>
    </div>
  )
}
