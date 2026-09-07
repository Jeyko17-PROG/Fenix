import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

export default function Restablecer() {
  const { resetPassword } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''

  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState('')
  const [ok, setOk] = useState('')
  const [enviando, setEnviando] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setError(''); setOk('')
    if (password !== confirm) { setError('Las contraseñas no coinciden.'); return }
    setEnviando(true)
    try {
      await resetPassword({ token, email, password, password_confirmation: confirm })
      setOk('¡Contraseña actualizada! Te llevamos al inicio de sesión…')
      setTimeout(() => navigate('/login'), 2000)
    } catch (err) {
      setError(err.message || 'No se pudo restablecer la contraseña.')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-950 via-[#2a1206] to-slate-950 px-4 py-8">
      <div className="w-full max-w-sm">
        <div className="flex flex-col items-center mb-6">
          <img src="/logo-fenix.png" alt="Fénix" className="h-16 w-16 object-contain drop-shadow-lg" />
        </div>

        <div className="bg-white rounded-2xl shadow-2xl overflow-hidden">
          <div className="bg-gradient-to-r from-red-600 via-orange-600 to-amber-500 px-6 py-5">
            <h1 className="text-white text-xl font-bold text-center">Nueva contraseña</h1>
          </div>

          <form onSubmit={submit} className="px-6 py-6 space-y-4">
            {error && <div className="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">{error}</div>}
            {ok && <div className="rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-700">{ok}</div>}

            {!token || !email ? (
              <p className="text-sm text-slate-600">El enlace no es válido. Solicita uno nuevo desde «¿Olvidaste tu contraseña?».</p>
            ) : (
              <>
                <p className="text-xs text-slate-500">Cuenta: <span className="font-medium text-slate-700">{email}</span></p>
                <input type="password" placeholder="Nueva contraseña" value={password} onChange={(e) => setPassword(e.target.value)} required
                  className="w-full border-b border-slate-200 focus:border-orange-500 py-2 text-slate-800 focus:outline-none" />
                <input type="password" placeholder="Repite la contraseña" value={confirm} onChange={(e) => setConfirm(e.target.value)} required
                  className="w-full border-b border-slate-200 focus:border-orange-500 py-2 text-slate-800 focus:outline-none" />
                <button type="submit" disabled={enviando}
                  className="w-full rounded-xl bg-gradient-to-r from-red-600 via-orange-600 to-amber-500 hover:opacity-95 disabled:opacity-50 text-white font-semibold py-2.5 shadow-lg transition">
                  {enviando ? 'Procesando…' : 'Guardar contraseña'}
                </button>
              </>
            )}
            <div className="text-center">
              <button type="button" onClick={() => navigate('/login')} className="text-xs text-orange-600 hover:underline">← Ir a iniciar sesión</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
