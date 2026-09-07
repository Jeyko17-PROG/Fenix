import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { useFeatures } from '../context/FeaturesContext'

const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado']
const METODO_PAGO_VACIO = { tipo: 'Nequi', nombre: '', numero_cuenta: '', enlace: '' }
const TIPOS_PAGO = ['Nequi', 'Daviplata', 'Tarjeta', 'Transferencia', 'Bancolombia', 'Bold', 'Link de pago', 'Otro']

export default function Configuracion() {
  const { user, setUser } = useAuth()
  const { activa } = useFeatures()
  const [perfilPublico, setPerfilPublico] = useState({
    politicas: '', instagram_url: '', tiktok_url: '', facebook_url: '', whatsapp_url: '',
  })
  const [guardandoPerfil, setGuardandoPerfil] = useState(false)
  const [sucursales, setSucursales] = useState([])
  const [bodegaId, setBodegaId] = useState('') // '' = horario/bloqueos generales de la empresa
  const [horarios, setHorarios] = useState([])
  const [ajustes, setAjustes] = useState({ duracion_cita_min: 30, buffer_min: 0 })
  const [bloqueos, setBloqueos] = useState([])
  const [bloqueo, setBloqueo] = useState({ inicio: '', fin: '', motivo: '' })
  const [msg, setMsg] = useState('')

  // --- Métodos de pago manuales (Nequi, Daviplata, Bancolombia, link de pago...) ---
  const [metodosPago, setMetodosPago] = useState([])
  const [formMetodo, setFormMetodo] = useState({ ...METODO_PAGO_VACIO })
  const [tipoOtro, setTipoOtro] = useState(false) // "Otro" elegido en el desplegable: muestra el campo de texto libre
  const [qrImagen, setQrImagen] = useState(null)
  const [editandoMetodo, setEditandoMetodo] = useState(null) // id del método en edición, o null = nuevo
  const [guardandoMetodo, setGuardandoMetodo] = useState(false)
  const [errorMetodo, setErrorMetodo] = useState('')

  useEffect(() => {
    api('/bodegas').then(setSucursales).catch(() => {}) // multisucursal; si no aplica, queda vacío
    cargarMetodosPago()
  }, [])

  function cargarMetodosPago() {
    api('/metodos-pago').then(setMetodosPago).catch(() => {})
  }

  async function guardarMetodoPago(e) {
    e.preventDefault(); setErrorMetodo(''); setGuardandoMetodo(true)
    try {
      const fd = new FormData()
      Object.entries(formMetodo).forEach(([k, v]) => { if (v) fd.append(k, v) })
      if (qrImagen) fd.append('qr_imagen', qrImagen)

      if (editandoMetodo) await api(`/metodos-pago/${editandoMetodo}/update`, { method: 'POST', body: fd, isForm: true })
      else await api('/metodos-pago', { method: 'POST', body: fd, isForm: true })

      cancelarMetodoPago()
      cargarMetodosPago()
    } catch (err) {
      setErrorMetodo(err.message || 'No se pudo guardar.')
    } finally {
      setGuardandoMetodo(false)
    }
  }

  function editarMetodoPago(m) {
    setEditandoMetodo(m.id)
    setFormMetodo({ tipo: m.tipo, nombre: m.nombre ?? '', numero_cuenta: m.numero_cuenta ?? '', enlace: m.enlace ?? '' })
    setTipoOtro(!TIPOS_PAGO.includes(m.tipo)) // tipo guardado que no está en la lista (ej. viejo/manual) -> muestra el texto libre
    setQrImagen(null); setErrorMetodo('')
  }

  function cancelarMetodoPago() {
    setEditandoMetodo(null)
    setFormMetodo({ ...METODO_PAGO_VACIO })
    setTipoOtro(false)
    setQrImagen(null); setErrorMetodo('')
  }

  async function eliminarMetodoPago(id) {
    if (!confirm('¿Eliminar este método de pago?')) return
    await api(`/metodos-pago/${id}`, { method: 'DELETE' })
    if (editandoMetodo === id) cancelarMetodoPago()
    cargarMetodosPago()
  }

  async function toggleMetodoPagoActivo(m) {
    const fd = new FormData()
    fd.append('tipo', m.tipo)
    if (m.nombre) fd.append('nombre', m.nombre)
    if (m.numero_cuenta) fd.append('numero_cuenta', m.numero_cuenta)
    if (m.enlace) fd.append('enlace', m.enlace)
    fd.append('activo', m.activo ? '0' : '1')
    await api(`/metodos-pago/${m.id}/update`, { method: 'POST', body: fd, isForm: true })
    cargarMetodosPago()
  }

  // Precarga las políticas/redes sociales del negocio desde /me.
  useEffect(() => {
    const info = user?.empresa_info
    if (!info) return
    setPerfilPublico({
      politicas: info.politicas ?? '',
      instagram_url: info.instagram_url ?? '',
      tiktok_url: info.tiktok_url ?? '',
      facebook_url: info.facebook_url ?? '',
      whatsapp_url: info.whatsapp_url ?? '',
    })
  }, [user?.empresa_info])

  async function guardarPerfilPublico() {
    setGuardandoPerfil(true)
    try {
      await api('/perfil/publico', { method: 'PUT', body: perfilPublico })
      const me = await api('/me')
      setUser(me)
      flash('Perfil público guardado.')
    } catch (err) {
      alert(err.message || 'No se pudo guardar.')
    } finally {
      setGuardandoPerfil(false)
    }
  }

  async function cargar() {
    const q = bodegaId ? `?bodega_id=${bodegaId}` : ''
    const cfg = await api(`/agenda/configuracion${q}`)
    setAjustes({ duracion_cita_min: cfg.ajustes.duracion_cita_min, buffer_min: cfg.ajustes.buffer_min })
    setBloqueos(cfg.bloqueos)
    // Mapa de horarios por día (un rango por día para la UI simple)
    const porDia = {}
    cfg.horarios.forEach((h) => { porDia[h.dia_semana] = h })
    setHorarios(DIAS.map((_, d) => ({
      dia_semana: d,
      activo: !!porDia[d],
      hora_inicio: porDia[d]?.hora_inicio?.slice(0, 5) ?? '08:00',
      hora_fin: porDia[d]?.hora_fin?.slice(0, 5) ?? '18:00',
    })))
  }
  useEffect(() => { cargar() }, [bodegaId]) // eslint-disable-line react-hooks/exhaustive-deps

  function flash(t) { setMsg(t); setTimeout(() => setMsg(''), 2500) }

  async function guardarAjustes() {
    await api('/agenda/ajustes', { method: 'PUT', body: ajustes })
    flash('Ajustes guardados.')
  }
  async function guardarHorarios() {
    const payload = horarios.filter((h) => h.activo).map((h) => ({ dia_semana: h.dia_semana, hora_inicio: h.hora_inicio, hora_fin: h.hora_fin }))
    await api('/agenda/horarios', { method: 'PUT', body: { bodega_id: bodegaId || null, horarios: payload } })
    flash('Horario laboral guardado.')
  }
  async function crearBloqueo(e) {
    e.preventDefault()
    await api('/agenda/bloqueos', { method: 'POST', body: { ...bloqueo, bodega_id: bodegaId || null } })
    setBloqueo({ inicio: '', fin: '', motivo: '' }); cargar()
  }
  async function eliminarBloqueo(id) {
    await api(`/agenda/bloqueos/${id}`, { method: 'DELETE' }); cargar()
  }

  const setHorario = (i, k, v) => setHorarios(horarios.map((h, j) => j === i ? { ...h, [k]: v } : h))

  return (
    <div className="space-y-10">
      <h1 className="text-2xl font-bold">Configuración</h1>
      {msg && <div className="rounded-lg bg-emerald-500/10 border border-emerald-500/40 px-3 py-2 text-sm text-emerald-300">{msg}</div>}

      {/* Ajustes de citas */}
      <section>
        <h2 className="font-semibold mb-3">Duración y tiempos</h2>
        <div className="flex flex-wrap gap-3 items-end">
          <label className="text-sm">Duración cita (min)
            <input type="number" value={ajustes.duracion_cita_min} onChange={(e) => setAjustes({ ...ajustes, duracion_cita_min: Number(e.target.value) })} className="input mt-1" />
          </label>
          <label className="text-sm">Buffer entre citas (min)
            <input type="number" value={ajustes.buffer_min} onChange={(e) => setAjustes({ ...ajustes, buffer_min: Number(e.target.value) })} className="input mt-1" />
          </label>
          <button onClick={guardarAjustes} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar</button>
        </div>
      </section>

      {/* Selector de sucursal: gobierna el horario laboral y los bloqueos de abajo */}
      {sucursales.length > 1 && (
        <section>
          <h2 className="font-semibold mb-2">Sucursal</h2>
          <select value={bodegaId} onChange={(e) => setBodegaId(e.target.value)} className="input !w-auto">
            <option value="">General (toda la empresa)</option>
            {sucursales.map((s) => <option key={s.id} value={s.id}>{s.nombre}</option>)}
          </select>
          <p className="text-xs text-slate-500 mt-1.5">
            {bodegaId
              ? 'Editando el horario y los bloqueos propios de esta sucursal. Si no defines nada aquí, usa el horario general.'
              : 'Editando el horario general de la empresa. Cada sucursal puede definir el suyo propio para reemplazarlo.'}
          </p>
        </section>
      )}

      {/* Horario laboral */}
      <section>
        <h2 className="font-semibold mb-3">Horario laboral (días y horas){bodegaId && sucursales.length > 1 && ` — ${sucursales.find((s) => String(s.id) === bodegaId)?.nombre}`}</h2>
        <div className="space-y-2">
          {horarios.map((h, i) => (
            <div key={i} className="flex items-center gap-3">
              <label className="flex items-center gap-2 w-28">
                <input type="checkbox" checked={h.activo} onChange={(e) => setHorario(i, 'activo', e.target.checked)} />
                {DIAS[h.dia_semana]}
              </label>
              <input type="time" value={h.hora_inicio} disabled={!h.activo} onChange={(e) => setHorario(i, 'hora_inicio', e.target.value)} className="input !w-auto disabled:opacity-40" />
              <span className="text-slate-500">a</span>
              <input type="time" value={h.hora_fin} disabled={!h.activo} onChange={(e) => setHorario(i, 'hora_fin', e.target.value)} className="input !w-auto disabled:opacity-40" />
            </div>
          ))}
        </div>
        <button onClick={guardarHorarios} className="mt-3 rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar horario</button>
      </section>

      {/* Servicios: se gestionan en su propia página (nombre, precio, categoría, imagen y emoji) */}
      <section>
        <h2 className="font-semibold mb-2">Servicios</h2>
        <p className="text-sm text-slate-400">
          Crea y edita tus servicios (con categoría, precio, imagen y emoji) desde{' '}
          <Link to="/servicios" className="text-emerald-400 hover:underline">Servicios</Link> en el menú.
        </p>
      </section>

      {/* Métodos de pago manuales: se muestran al cobrar (QR/cuenta) para que
          el cliente transfiera sin salir del POS. Solo aplica si el negocio
          tiene facturación (ahí vive el modal de cobro que los usa). */}
      {activa('facturacion') && (
      <section>
        <h2 className="font-semibold mb-2">Métodos de pago</h2>
        <p className="text-sm text-slate-400 mb-3">
          Configura tus cuentas Nequi, Daviplata, Bancolombia, links de pago, etc. Aparecen en el momento de cobrar
          para que el cliente escanee el QR o vea a dónde transferir.
        </p>

        <ul className="rounded-xl border border-slate-800 divide-y divide-slate-800 mb-4">
          {metodosPago.map((m) => (
            <li key={m.id} className="p-3 flex items-center gap-3">
              {m.qr_url
                ? <img src={m.qr_url} alt={`QR ${m.tipo}`} className="h-12 w-12 rounded-lg object-contain bg-white shrink-0" />
                : <span className="h-12 w-12 rounded-lg bg-slate-800 flex items-center justify-center text-lg shrink-0">💳</span>}
              <div className="min-w-0 flex-1">
                <p className="font-medium truncate">{m.nombre || m.tipo} <span className="text-xs text-slate-500">· {m.tipo}</span></p>
                <p className="text-xs text-slate-400 truncate">{m.numero_cuenta}{m.enlace ? (m.numero_cuenta ? ' · ' : '') + m.enlace : ''}</p>
              </div>
              <button onClick={() => toggleMetodoPagoActivo(m)}
                className={`text-xs rounded-full px-2 py-0.5 shrink-0 ${m.activo ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-700 text-slate-400'}`}>
                {m.activo ? 'Activo' : 'Inactivo'}
              </button>
              <button onClick={() => editarMetodoPago(m)} className="text-xs text-sky-400 hover:underline shrink-0">Editar</button>
              <button onClick={() => eliminarMetodoPago(m.id)} className="text-xs text-red-400 hover:underline shrink-0">Eliminar</button>
            </li>
          ))}
          {metodosPago.length === 0 && <li className="p-4 text-slate-500 text-sm">Sin métodos de pago configurados.</li>}
        </ul>

        <form onSubmit={guardarMetodoPago} className="rounded-xl border border-slate-800 bg-slate-800/30 p-4 space-y-3">
          {errorMetodo && <p className="text-sm text-red-300">{errorMetodo}</p>}
          <div className="grid sm:grid-cols-2 gap-3">
            <label className="text-sm">Tipo *
              <select required value={tipoOtro ? 'Otro' : formMetodo.tipo} onChange={(e) => {
                if (e.target.value === 'Otro') { setTipoOtro(true); setFormMetodo({ ...formMetodo, tipo: '' }) }
                else { setTipoOtro(false); setFormMetodo({ ...formMetodo, tipo: e.target.value }) }
              }} className="input mt-1">
                {TIPOS_PAGO.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
              {tipoOtro && (
                <input required value={formMetodo.tipo} onChange={(e) => setFormMetodo({ ...formMetodo, tipo: e.target.value })}
                  className="input mt-2" placeholder="Escribe el tipo de pago" />
              )}
            </label>
            <label className="text-sm">Etiqueta (opcional)
              <input value={formMetodo.nombre} onChange={(e) => setFormMetodo({ ...formMetodo, nombre: e.target.value })}
                className="input mt-1" placeholder="Ej: Nequi del negocio" />
            </label>
            <label className="text-sm">Número de cuenta / teléfono
              <input value={formMetodo.numero_cuenta} onChange={(e) => setFormMetodo({ ...formMetodo, numero_cuenta: e.target.value })}
                className="input mt-1" placeholder="3001234567" />
            </label>
            <label className="text-sm">Enlace de pago (opcional)
              <input value={formMetodo.enlace} onChange={(e) => setFormMetodo({ ...formMetodo, enlace: e.target.value })}
                className="input mt-1" placeholder="https://…" />
            </label>
          </div>
          <label className="text-sm block">Código QR (opcional)
            <input type="file" accept="image/*" onChange={(e) => setQrImagen(e.target.files?.[0] ?? null)} className="input mt-1" />
            {editandoMetodo && !qrImagen && <p className="text-xs text-slate-500 mt-1">Deja esto vacío para conservar el QR actual.</p>}
          </label>
          <div className="flex gap-2">
            <button disabled={guardandoMetodo} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
              {guardandoMetodo ? 'Guardando…' : editandoMetodo ? 'Guardar cambios' : 'Agregar método de pago'}
            </button>
            {editandoMetodo && <button type="button" onClick={cancelarMetodoPago} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>}
          </div>
        </form>
      </section>
      )}

      {/* Portal público: políticas y redes sociales (aparecen en el enlace del QR) */}
      <section>
        <h2 className="font-semibold mb-2">Portal público y redes sociales</h2>
        <p className="text-sm text-slate-400 mb-3">
          Se muestran a tus clientes cuando escanean tu código QR o entran a tu enlace de reservas.
        </p>
        <div className="grid sm:grid-cols-2 gap-3 mb-3">
          <label className="text-sm">Instagram
            <input placeholder="https://instagram.com/tunegocio" value={perfilPublico.instagram_url}
              onChange={(e) => setPerfilPublico({ ...perfilPublico, instagram_url: e.target.value })} className="input mt-1" />
          </label>
          <label className="text-sm">TikTok
            <input placeholder="https://tiktok.com/@tunegocio" value={perfilPublico.tiktok_url}
              onChange={(e) => setPerfilPublico({ ...perfilPublico, tiktok_url: e.target.value })} className="input mt-1" />
          </label>
          <label className="text-sm">Facebook
            <input placeholder="https://facebook.com/tunegocio" value={perfilPublico.facebook_url}
              onChange={(e) => setPerfilPublico({ ...perfilPublico, facebook_url: e.target.value })} className="input mt-1" />
          </label>
          <label className="text-sm">WhatsApp
            <input placeholder="https://wa.me/573001234567" value={perfilPublico.whatsapp_url}
              onChange={(e) => setPerfilPublico({ ...perfilPublico, whatsapp_url: e.target.value })} className="input mt-1" />
          </label>
        </div>
        <label className="text-sm block mb-3">Políticas del negocio (requisitos, abono, cancelación…)
          <textarea rows="4" placeholder="Ej. Mayor de 18 años. No consumir alcohol 24h antes. Se requiere abono del 30% para confirmar la cita."
            value={perfilPublico.politicas} onChange={(e) => setPerfilPublico({ ...perfilPublico, politicas: e.target.value })} className="input mt-1" />
        </label>
        <button onClick={guardarPerfilPublico} disabled={guardandoPerfil} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
          {guardandoPerfil ? 'Guardando…' : 'Guardar'}
        </button>
      </section>

      {/* Bloqueos */}
      <section>
        <h2 className="font-semibold mb-3">Fechas bloqueadas (festivos, descanso){bodegaId && sucursales.length > 1 && ` — ${sucursales.find((s) => String(s.id) === bodegaId)?.nombre}`}</h2>
        <form onSubmit={crearBloqueo} className="flex flex-wrap gap-2 mb-3 items-end">
          <label className="text-sm">Desde<input type="datetime-local" required value={bloqueo.inicio} onChange={(e) => setBloqueo({ ...bloqueo, inicio: e.target.value })} className="input mt-1" /></label>
          <label className="text-sm">Hasta<input type="datetime-local" required value={bloqueo.fin} onChange={(e) => setBloqueo({ ...bloqueo, fin: e.target.value })} className="input mt-1" /></label>
          <input placeholder="Motivo" value={bloqueo.motivo} onChange={(e) => setBloqueo({ ...bloqueo, motivo: e.target.value })} className="input !w-auto" />
          <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Bloquear</button>
        </form>
        <ul className="rounded-xl border border-slate-800 divide-y divide-slate-800">
          {bloqueos.map((b) => (
            <li key={b.id} className="p-3 flex justify-between items-center text-sm">
              <span>{new Date(b.inicio).toLocaleString('es')} → {new Date(b.fin).toLocaleString('es')} {b.motivo && `· ${b.motivo}`}</span>
              <button onClick={() => eliminarBloqueo(b.id)} className="text-red-400 hover:underline">Quitar</button>
            </li>
          ))}
          {bloqueos.length === 0 && <li className="p-4 text-slate-500 text-sm">Sin bloqueos.</li>}
        </ul>
      </section>
    </div>
  )
}
