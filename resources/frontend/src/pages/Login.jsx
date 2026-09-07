import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../api/client'

const VACIO = {
  name: '', tipo_documento: 'CC', numero_documento: '', telefono: '',
  email: '', password: '', password_confirmation: '',
  nombre_empresa: '', tipo_negocio_id: '', codigo: '',
}

export default function Login() {
  const { login, register, activar, forgotPassword, misNegocios } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  // Modo inicial según el botón pulsado en la pantalla de bienvenida (?modo=registro).
  const [modo, setModo] = useState(params.get('modo') === 'registro' ? 'registro' : 'login') // 'login' | 'registro' | 'recuperar' | 'activar'
  const [form, setForm] = useState(VACIO)
  const [error, setError] = useState('')
  const [ok, setOk] = useState('')
  const [enviando, setEnviando] = useState(false)
  const [tiposNegocio, setTiposNegocio] = useState([])
  const [mostrarBienvenida, setMostrarBienvenida] = useState(false)

  // Catálogo de tipos de negocio para el registro (define los módulos del POS).
  useEffect(() => {
    api('/tipos-negocio').then(setTiposNegocio).catch(() => {})
  }, [])

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  function cambiarModo(m) {
    setModo(m); setError(''); setOk('')
  }

  // Si la cuenta tiene 2+ negocios vinculados, deja elegir cuál usar antes
  // de entrar al panel; si solo tiene uno, va directo al Dashboard como siempre.
  async function irSegunNegocios() {
    try {
      const negocios = await misNegocios()
      navigate(negocios.length > 1 ? '/mis-negocios' : '/dashboard')
    } catch {
      navigate('/dashboard')
    }
  }

  async function submit(e) {
    e.preventDefault()
    setError(''); setOk(''); setEnviando(true)
    try {
      if (modo === 'login') {
        await login(form.email, form.password)
        await irSegunNegocios()
      } else if (modo === 'registro') {
        if (form.password !== form.password_confirmation) throw { message: 'Las contraseñas no coinciden.' }
        const data = await register({
          name: form.name,
          tipo_documento: form.tipo_documento,
          numero_documento: form.numero_documento,
          telefono: form.telefono,
          email: form.email,
          password: form.password,
          password_confirmation: form.password_confirmation,
          nombre_empresa: form.nombre_empresa || form.name,
          tipo_negocio_id: form.tipo_negocio_id,
        })
        if (data.pendiente_activacion) {
          // Cuenta bloqueada desde el registro: pasa a la pantalla de
          // activación con el código de 6 dígitos que entrega el super-admin.
          setModo('activar')
          setForm((f) => ({ ...f, email: data.email, codigo: '' }))
          setOk(data.message)
          setMostrarBienvenida(true)
        } else {
          navigate('/dashboard')
        }
      } else if (modo === 'activar') {
        await activar(form.email, form.codigo)
        await irSegunNegocios()
      } else if (modo === 'recuperar') {
        await forgotPassword(form.email)
        setOk('Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.')
      }
    } catch (err) {
      setError(err.message || 'No se pudo completar la acción.')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-950 via-[#2a1206] to-slate-950 px-4 py-8">
      <div className="w-full max-w-sm">
        {/* Logo */}
        <div className="flex flex-col items-center mb-6">
          <img
            src="/logo-fenix.png"
            alt="Fénix"
            className="h-20 w-20 object-contain drop-shadow-lg"
            onError={(e) => {
              e.currentTarget.style.display = 'none'; e.currentTarget.nextSibling.style.display = 'block'
            }}
          />
          <span style={{ display: 'none' }} className="text-3xl font-extrabold text-white tracking-wide">FÉNIX</span>
          <p className="mt-1 text-orange-400 text-[11px] font-semibold uppercase tracking-[0.2em]">
            Velocidad y eficiencia en tu punto de venta
          </p>
        </div>

        {/* Tarjeta */}
        <div className="bg-white rounded-2xl shadow-2xl overflow-hidden">
          {/* Encabezado con los dos botones */}
          <div className="bg-gradient-to-r from-red-600 via-orange-600 to-amber-500 px-6 pt-6 pb-4">
            <h1 className="text-white text-xl font-bold text-center mb-4">
              {modo === 'recuperar' ? 'Recuperar contraseña' : modo === 'activar' ? 'Activar tu cuenta' : 'Mi cuenta'}
            </h1>
            {modo !== 'recuperar' && modo !== 'activar' && (
              <div className="flex bg-white/15 rounded-xl p-1">
                <button onClick={() => cambiarModo('login')}
                  className={`flex-1 py-2 rounded-lg text-sm font-semibold transition ${modo === 'login' ? 'bg-white text-orange-600' : 'text-white'}`}>
                  Iniciar sesión
                </button>
                <button onClick={() => cambiarModo('registro')}
                  className={`flex-1 py-2 rounded-lg text-sm font-semibold transition ${modo === 'registro' ? 'bg-white text-orange-600' : 'text-white'}`}>
                  Crear cuenta
                </button>
              </div>
            )}
          </div>

          {/* Formulario */}
          <form onSubmit={submit} className="px-6 py-6 space-y-4">
            {error && (
              <div className="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">{error}</div>
            )}
            {ok && (
              <div className="rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-700">{ok}</div>
            )}

            {modo === 'registro' && (
              <>
                <Campo icono="👤" placeholder="Nombre completo" value={form.name} onChange={set('name')} required />
                <div className="flex gap-2">
                  <select value={form.tipo_documento} onChange={set('tipo_documento')}
                    className="border-b border-slate-200 focus:border-orange-500 text-slate-700 text-sm bg-transparent py-1.5 focus:outline-none">
                    <option value="CC">CC</option>
                    <option value="CE">CE</option>
                    <option value="NIT">NIT</option>
                    <option value="PAS">Pasaporte</option>
                  </select>
                  <div className="flex-1">
                    <Campo icono="🪪" placeholder="Número de documento" value={form.numero_documento} onChange={set('numero_documento')} />
                  </div>
                </div>
                <Campo icono="📱" placeholder="Celular" value={form.telefono} onChange={set('telefono')} />
                <Campo icono="🏪" placeholder="Nombre de tu negocio" value={form.nombre_empresa} onChange={set('nombre_empresa')} />
                <div className="flex items-center gap-2 border-b border-slate-200 focus-within:border-orange-500 transition pb-1">
                  <span className="text-slate-400 text-sm">🧰</span>
                  <select value={form.tipo_negocio_id} onChange={set('tipo_negocio_id')} required
                    className="w-full py-1.5 text-slate-800 bg-transparent focus:outline-none text-sm">
                    <option value="">Tipo de negocio…</option>
                    {tiposNegocio.map((t) => <option key={t.id} value={t.id}>{t.nombre}</option>)}
                  </select>
                </div>
              </>
            )}

            <Campo icono="✉️" type="email" placeholder="Correo electrónico" value={form.email} onChange={set('email')} required />

            {(modo === 'login' || modo === 'registro') && (
              <Campo icono="🔒" type="password" placeholder="Contraseña" value={form.password} onChange={set('password')} required />
            )}
            {modo === 'registro' && (
              <Campo icono="🔒" type="password" placeholder="Repite la contraseña" value={form.password_confirmation} onChange={set('password_confirmation')} required />
            )}
            {modo === 'activar' && (
              <>
                <Campo icono="🔑" placeholder="Código de 6 dígitos" value={form.codigo}
                  onChange={(e) => setForm({ ...form, codigo: e.target.value.replace(/\D/g, '').slice(0, 6) })}
                  inputMode="numeric" maxLength={6} required />
                <p className="text-xs text-slate-500">Ese código te lo entrega el administrador de Fénix por WhatsApp o correo.</p>
              </>
            )}

            {modo === 'login' && (
              <div className="flex items-center justify-between text-xs">
                <button type="button" onClick={() => cambiarModo('activar')}
                  className="text-orange-600 hover:underline">¿Tienes un código de activación?</button>
                <button type="button" onClick={() => cambiarModo('recuperar')}
                  className="text-orange-600 hover:underline">¿Olvidaste tu contraseña?</button>
              </div>
            )}

            <button type="submit" disabled={enviando}
              className="w-full rounded-xl bg-gradient-to-r from-red-600 via-orange-600 to-amber-500 hover:opacity-95 disabled:opacity-50 text-white font-semibold py-2.5 shadow-lg transition">
              {enviando ? 'Procesando…' : (modo === 'login' ? 'Entrar' : modo === 'registro' ? 'Registrarme' : modo === 'activar' ? 'Activar cuenta' : 'Enviar enlace')}
            </button>

            {(modo === 'recuperar' || modo === 'activar') && (
              <div className="text-center">
                <button type="button" onClick={() => cambiarModo('login')}
                  className="text-xs text-orange-600 hover:underline">← Volver a iniciar sesión</button>
              </div>
            )}
          </form>
        </div>

        <div className="text-center mt-5">
          <button type="button" onClick={() => navigate('/bienvenida')} className="text-slate-400 hover:text-white text-xs">← Volver al inicio</button>
        </div>
        <p className="text-center text-slate-500 text-xs mt-3">Fénix · Velocidad y eficiencia en tu punto de venta</p>
      </div>

      {mostrarBienvenida && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4" onClick={() => setMostrarBienvenida(false)}>
          <div onClick={(e) => e.stopPropagation()}
            className="w-full max-w-sm rounded-2xl bg-gradient-to-b from-slate-900 to-slate-950 border border-orange-500/30 shadow-2xl p-8 text-center">
            <img src="/logo-fenix.png" alt="Fénix"
              className="h-24 w-24 object-contain mx-auto mb-4 drop-shadow-lg"
              onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <h2 className="text-2xl font-extrabold text-white">¡Gracias por elegir a Fénix! 🔥</h2>
            <p className="mt-3 text-sm text-slate-300">
              Tu cuenta ya está creada. Solo falta un paso: un asesor de Fénix te va a compartir tu
              código de activación de 6 dígitos para que puedas entrar.
            </p>
            <button onClick={() => setMostrarBienvenida(false)}
              className="mt-6 w-full rounded-xl bg-gradient-to-r from-red-600 via-orange-600 to-amber-500 hover:opacity-95 text-white font-semibold py-2.5 shadow-lg transition">
              Continuar
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

function Campo({ icono, ...props }) {
  return (
    <div className="flex items-center gap-2 border-b border-slate-200 focus-within:border-orange-500 transition pb-1">
      <span className="text-slate-400 text-sm">{icono}</span>
      <input {...props}
        className="w-full py-1.5 text-slate-800 placeholder-slate-400 focus:outline-none bg-transparent" />
    </div>
  )
}
