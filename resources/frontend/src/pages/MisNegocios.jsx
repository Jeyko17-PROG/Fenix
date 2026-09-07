import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../api/client'

const NEGOCIO_VACIO = { name: '', nombre_empresa: '', tipo_negocio_id: '', telefono: '' }

const ESTADO_COLOR = {
  ACTIVO: 'bg-emerald-500/15 text-emerald-400',
  SUSPENDIDO: 'bg-amber-500/15 text-amber-400',
  DESACTIVADO: 'bg-red-500/15 text-red-400',
  PENDIENTE_ACTIVACION: 'bg-sky-500/15 text-sky-400',
}

export default function MisNegocios() {
  const { misNegocios, entrarNegocio, vincularNegocio, crearNegocio, desvincularNegocio, logout, user } = useAuth()
  const navigate = useNavigate()
  const [negocios, setNegocios] = useState([])
  const [cargando, setCargando] = useState(true)
  const [error, setError] = useState('')
  const [tab, setTab] = useState('negocios') // 'negocios' | 'historial'
  const [entrando, setEntrando] = useState(null)
  const [vincular, setVincular] = useState({ email: '', password: '' })
  const [vinculando, setVinculando] = useState(false)
  const [tiposNegocio, setTiposNegocio] = useState([])
  const [mostrarCrear, setMostrarCrear] = useState(false)
  const [nuevoNegocio, setNuevoNegocio] = useState(NEGOCIO_VACIO)
  const [creando, setCreando] = useState(false)
  const [creado, setCreado] = useState(null) // { email, password } tras crear, para mostrarlo una sola vez

  useEffect(() => { api('/tipos-negocio').then(setTiposNegocio).catch(() => {}) }, [])

  async function cargar() {
    setCargando(true); setError('')
    try { setNegocios(await misNegocios()) }
    catch (err) { setError(err.message || 'No se pudieron cargar tus negocios.') }
    finally { setCargando(false) }
  }
  useEffect(() => { cargar() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function elegir(n) {
    if (n.es_actual) { navigate('/dashboard'); return }
    setEntrando(n.id); setError('')
    try {
      await entrarNegocio(n.id)
      navigate('/dashboard')
    } catch (err) {
      setError(err.message || 'No se pudo entrar a ese negocio.')
    } finally {
      setEntrando(null)
    }
  }

  async function vincularOtro(e) {
    e.preventDefault(); setError(''); setVinculando(true)
    try {
      await vincularNegocio(vincular.email, vincular.password)
      setVincular({ email: '', password: '' })
      cargar()
    } catch (err) {
      setError(err.message || 'No se pudo vincular ese negocio.')
    } finally {
      setVinculando(false)
    }
  }

  function abrirCrear() {
    setNuevoNegocio({ ...NEGOCIO_VACIO, name: user?.name ?? '', telefono: user?.telefono ?? '' })
    setCreado(null); setError(''); setMostrarCrear(true)
  }

  async function crear(e) {
    e.preventDefault(); setError(''); setCreando(true)
    try {
      await crearNegocio(nuevoNegocio)
      setCreado({ nombre: nuevoNegocio.nombre_empresa })
      setMostrarCrear(false); setNuevoNegocio(NEGOCIO_VACIO)
      cargar()
    } catch (err) {
      setError(err.message || 'No se pudo crear el negocio.')
    } finally {
      setCreando(false)
    }
  }

  async function quitar(n) {
    if (!confirm(`¿Dejar de ver "${n.negocio}" en Mis negocios?`)) return
    try { await desvincularNegocio(n.id); cargar() }
    catch (err) { alert(err.message || 'No se pudo desvincular.') }
  }

  async function salir() {
    await logout()
    navigate('/login')
  }

  const historial = [...negocios]
    .filter((n) => n.ultimo_acceso)
    .sort((a, b) => new Date(b.ultimo_acceso) - new Date(a.ultimo_acceso))

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center px-4 py-10">
      <div className="w-full max-w-2xl">
        <div className="flex items-center gap-3 mb-6">
          <img src="/logo-fenix.png" alt="" className="h-10 w-10 object-contain" onError={(e) => { e.currentTarget.style.display = 'none' }} />
          <div>
            <h1 className="text-2xl font-bold">Mis negocios</h1>
            <p className="text-sm text-slate-400">Hola {user?.name}, elige con qué negocio quieres trabajar.</p>
          </div>
        </div>

        <div className="flex gap-2 mb-5">
          <button onClick={() => setTab('negocios')} className={`rounded-lg px-4 py-2 text-sm font-semibold ${tab === 'negocios' ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>Mis negocios</button>
          <button onClick={() => setTab('historial')} className={`rounded-lg px-4 py-2 text-sm font-semibold ${tab === 'historial' ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>Historial</button>
          <button onClick={salir} className="ml-auto rounded-lg bg-red-900 hover:bg-red-800 px-4 py-2 text-sm font-semibold">Salir</button>
        </div>

        {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

        {creado && (
          <div className="mb-4 rounded-lg border border-emerald-600/40 bg-emerald-500/10 px-4 py-3 text-sm">
            <p className="font-semibold text-emerald-300">Negocio creado: {creado.nombre}</p>
            <p className="text-slate-400 text-xs mt-1">Queda "Pendiente" hasta que el super-admin de Fénix lo active. No necesitas otro correo ni otra contraseña: entra a este negocio con el botón "Entrar" de aquí mismo.</p>
            <button onClick={() => setCreado(null)} className="mt-1 text-xs text-slate-400 hover:underline">Cerrar</button>
          </div>
        )}

        {cargando ? (
          <p className="text-slate-500">Cargando…</p>
        ) : tab === 'negocios' ? (
          <>
            <div className="grid sm:grid-cols-2 gap-3 mb-6">
              {negocios.map((n) => (
                <div key={n.id} className={`rounded-2xl border p-4 ${n.es_actual ? 'border-emerald-600/60 bg-emerald-500/5' : 'border-slate-800 bg-slate-900/60'}`}>
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="font-semibold flex items-center gap-1">{n.logo_emoji || '🏪'} {n.negocio}</p>
                      <p className="text-xs text-slate-500">{n.email}</p>
                      {n.tipo_negocio && <p className="text-xs text-slate-400 mt-0.5">{n.tipo_negocio}</p>}
                    </div>
                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${ESTADO_COLOR[n.estado] ?? ''}`}>
                      {n.estado === 'PENDIENTE_ACTIVACION' ? 'Pendiente' : n.estado}
                    </span>
                  </div>
                  <div className="mt-3 flex items-center justify-between">
                    <button onClick={() => elegir(n)} disabled={entrando === n.id || n.estado !== 'ACTIVO'}
                      className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed px-3 py-1.5 text-xs font-semibold">
                      {n.es_actual ? 'Continuar aquí' : entrando === n.id ? 'Entrando…' : 'Entrar'}
                    </button>
                    {!n.es_actual && (
                      <button onClick={() => quitar(n)} className="text-xs text-slate-500 hover:text-red-400">Quitar</button>
                    )}
                  </div>
                </div>
              ))}
            </div>

            <div className="grid sm:grid-cols-2 gap-3">
              <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4">
                <h2 className="text-sm font-semibold text-slate-300 mb-2">Crear otro negocio</h2>
                <p className="text-xs text-slate-500 mb-3">Registra un negocio nuevo con tu mismo correo y contraseña — solo pide el nombre y el tipo de negocio.</p>
                {!mostrarCrear ? (
                  <button onClick={abrirCrear} className="rounded-lg bg-emerald-700 hover:bg-emerald-600 px-4 py-2 text-sm font-semibold">+ Crear otro negocio</button>
                ) : (
                  <form onSubmit={crear} className="space-y-2">
                    <input required placeholder="Tu nombre (responsable de este negocio)" value={nuevoNegocio.name}
                      onChange={(e) => setNuevoNegocio({ ...nuevoNegocio, name: e.target.value })} className="input" />
                    <input required placeholder="Nombre del negocio" value={nuevoNegocio.nombre_empresa}
                      onChange={(e) => setNuevoNegocio({ ...nuevoNegocio, nombre_empresa: e.target.value })} className="input" />
                    <select required value={nuevoNegocio.tipo_negocio_id} onChange={(e) => setNuevoNegocio({ ...nuevoNegocio, tipo_negocio_id: e.target.value })} className="input">
                      <option value="">Tipo de negocio…</option>
                      {tiposNegocio.map((t) => <option key={t.id} value={t.id}>{t.nombre}</option>)}
                    </select>
                    <input placeholder="Teléfono (opcional)" value={nuevoNegocio.telefono}
                      onChange={(e) => setNuevoNegocio({ ...nuevoNegocio, telefono: e.target.value })} className="input" />
                    <div className="flex gap-2">
                      <button type="button" onClick={() => setMostrarCrear(false)} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
                      <button disabled={creando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold flex-1">
                        {creando ? 'Creando…' : 'Crear negocio'}
                      </button>
                    </div>
                  </form>
                )}
              </div>

              <form onSubmit={vincularOtro} className="rounded-xl border border-slate-800 bg-slate-900/60 p-4">
                <h2 className="text-sm font-semibold text-slate-300 mb-2">Vincular otro negocio tuyo</h2>
                <p className="text-xs text-slate-500 mb-3">Si ya registraste otro negocio con un correo distinto, confírmalo aquí para verlo junto a este.</p>
                <div className="space-y-2">
                  <input type="email" required autoComplete="off" placeholder="Correo del otro negocio (no el de esta sesión)" value={vincular.email}
                    onChange={(e) => setVincular({ ...vincular, email: e.target.value })} className="input" />
                  <input type="password" required autoComplete="off" placeholder="Contraseña de esa cuenta" value={vincular.password}
                    onChange={(e) => setVincular({ ...vincular, password: e.target.value })} className="input" />
                  <button disabled={vinculando} className="w-full rounded-lg bg-slate-700 hover:bg-slate-600 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
                    {vinculando ? 'Vinculando…' : 'Vincular'}
                  </button>
                </div>
              </form>
            </div>
          </>
        ) : (
          <div className="rounded-xl border border-slate-800 divide-y divide-slate-800">
            {historial.length === 0 && <p className="p-4 text-sm text-slate-500">Aún no hay accesos registrados.</p>}
            {historial.map((n) => (
              <div key={n.id} className="p-3 flex items-center justify-between text-sm">
                <span>{n.logo_emoji || '🏪'} {n.negocio}</span>
                <span className="text-slate-500 text-xs">{new Date(n.ultimo_acceso).toLocaleString('es')}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
