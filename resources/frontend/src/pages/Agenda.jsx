import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'

const iconoVehiculo = (tipo) => (tipo === 'moto' ? '🏍️' : tipo === 'carro' ? '🚗' : '')

// Emoji propio del servicio/plan elegido (ej. 💅 para "Uñas"), definido por el negocio en Servicios/Planes de Lavado.
const iconoServicio = (c) => c.servicio?.icono || c.plan_lavado?.icono || ''

const ESTADO_COLOR = {
  PENDIENTE: 'bg-amber-600', CONFIRMADA: 'bg-emerald-600', CANCELADA: 'bg-red-600',
  REPROGRAMADA: 'bg-sky-600', COMPLETADA: 'bg-slate-500', NO_ASISTIO: 'bg-rose-800',
}
const ESTADO_LABEL = {
  PENDIENTE: 'Pendiente', CONFIRMADA: 'Confirmada', CANCELADA: 'Cancelada',
  REPROGRAMADA: 'Reprogramada', COMPLETADA: 'Completada', NO_ASISTIO: 'No asistió',
}
const DIAS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']

function Leyenda() {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mb-4 text-xs text-slate-400">
      {Object.entries(ESTADO_LABEL).map(([k, label]) => (
        <span key={k} className="flex items-center gap-1.5">
          <span className={`h-2.5 w-2.5 rounded-full ${ESTADO_COLOR[k]}`} />{label}
        </span>
      ))}
    </div>
  )
}

const ymd = (d) => d.toISOString().slice(0, 10)
const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x }
const startOfWeek = (d) => addDays(d, -d.getDay())
const fmtHora = (iso) => new Date(iso).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })

export default function Agenda() {
  const { user } = useAuth()
  const esLavadero = user?.empresa_info?.tipo_negocio?.clave === 'lavadero'
  const [vista, setVista] = useState('dia')
  const [fecha, setFecha] = useState(new Date())
  const [citas, setCitas] = useState([])
  const [clientes, setClientes] = useState([])
  const [servicios, setServicios] = useState([])
  const [planes, setPlanes] = useState([])
  const [sucursales, setSucursales] = useState([])
  const [nueva, setNueva] = useState(null) // null | fecha (Date) con la que abrir el formulario

  // Rango visible según la vista
  const [desde, hasta] = useMemo(() => {
    if (vista === 'dia') return [fecha, fecha]
    if (vista === 'semana') { const s = startOfWeek(fecha); return [s, addDays(s, 6)] }
    const first = new Date(fecha.getFullYear(), fecha.getMonth(), 1)
    const last = new Date(fecha.getFullYear(), fecha.getMonth() + 1, 0)
    return [first, last]
  }, [vista, fecha])

  async function cargar() {
    try {
      const data = await api(`/citas?desde=${ymd(desde)}&hasta=${ymd(hasta)}`)
      setCitas(data)
    } catch { /* sesión expirada: client.js redirige al login */ }
  }
  useEffect(() => { cargar() }, [desde, hasta]) // eslint-disable-line
  useEffect(() => {
    api('/clientes').then((d) => setClientes(d.data ?? d)).catch(() => {})
    api('/bodegas').then(setSucursales).catch(() => {}) // multisucursal; si el negocio no tiene el módulo de inventario, queda vacío
    if (esLavadero) api('/planes-lavado').then(setPlanes).catch(() => {})
    else api('/servicios').then(setServicios).catch(() => {})
  }, [esLavadero])

  const citasDe = (d) => citas.filter((c) => c.inicio.slice(0, 10) === ymd(d))

  async function accion(id, acc) {
    await api(`/citas/${id}/${acc}`, { method: 'POST' })
    cargar()
  }

  function navegar(dir) {
    if (vista === 'dia') setFecha(addDays(fecha, dir))
    else if (vista === 'semana') setFecha(addDays(fecha, dir * 7))
    else setFecha(new Date(fecha.getFullYear(), fecha.getMonth() + dir, 1))
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div className="flex items-center gap-3">
          {(user?.empresa_info?.logo_url || user?.empresa_info?.logo_emoji) && (
            <div className="h-10 w-10 shrink-0 rounded-lg bg-slate-800 overflow-hidden flex items-center justify-center text-xl">
              {user.empresa_info.logo_url
                ? <img src={user.empresa_info.logo_url} alt="Logo del negocio" className="h-full w-full object-cover" />
                : user.empresa_info.logo_emoji}
            </div>
          )}
          <h1 className="text-2xl font-bold">Agenda y Citas</h1>
        </div>
        <div className="flex gap-2">
          {['dia', 'semana', 'mes'].map((v) => (
            <button key={v} onClick={() => setVista(v)}
              className={`px-3 py-1.5 rounded-lg text-sm capitalize ${vista === v ? 'bg-emerald-600' : 'bg-slate-700'}`}>{v}</button>
          ))}
          <button onClick={() => setNueva(fecha)} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-1.5 text-sm font-semibold">+ Cita</button>
        </div>
      </div>

      <div className="flex items-center gap-3 mb-4">
        <button onClick={() => navegar(-1)} className="px-3 py-1 rounded bg-slate-700">‹</button>
        <button onClick={() => setFecha(new Date())} className="px-3 py-1 rounded bg-slate-700 text-sm">Hoy</button>
        <button onClick={() => navegar(1)} className="px-3 py-1 rounded bg-slate-700">›</button>
        <span className="text-slate-300 font-medium">
          {vista === 'mes'
            ? fecha.toLocaleDateString('es', { month: 'long', year: 'numeric' })
            : `${desde.toLocaleDateString('es', { day: 'numeric', month: 'short' })}${vista === 'semana' ? ' – ' + hasta.toLocaleDateString('es', { day: 'numeric', month: 'short' }) : ''}`}
        </span>
      </div>

      <Leyenda />

      {/* Vista DÍA */}
      {vista === 'dia' && (
        <div className="space-y-2">
          {citasDe(fecha).length === 0 && <p className="text-slate-500">Sin citas este día.</p>}
          {citasDe(fecha).map((c) => (
            <div key={c.id} className="flex items-stretch overflow-hidden rounded-lg border border-slate-800 bg-slate-800/50">
              <div className={`w-1.5 shrink-0 ${ESTADO_COLOR[c.estado]}`} />
              {/* Bloque de hora destacado */}
              <div className="flex flex-col items-center justify-center px-3 py-2 min-w-[76px] border-r border-slate-800/80 bg-slate-800/40">
                <span className="font-mono text-base font-bold text-emerald-400 leading-tight">{fmtHora(c.inicio)}</span>
                <span className="font-mono text-xs text-slate-500 leading-tight">{fmtHora(c.fin)}</span>
              </div>
              <div className="flex flex-1 items-center justify-between p-3 gap-3">
                <div className="min-w-0 flex items-center gap-2">
                  {c.imagen_referencia_url && (
                    <img src={c.imagen_referencia_url} alt="Referencia elegida por el cliente" title="Referencia elegida por el cliente"
                      className="h-10 w-10 rounded-lg object-cover border border-slate-700 shrink-0" />
                  )}
                  <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      {iconoServicio(c) && <span className="text-lg leading-none">{iconoServicio(c)}</span>}
                      <span className="font-semibold text-white text-base truncate">{c.cliente?.nombre_completo}</span>
                    </div>
                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-slate-400">
                      {(c.plan_lavado?.nombre ?? c.servicio?.nombre) && <span>{c.plan_lavado?.nombre ?? c.servicio?.nombre}</span>}
                      {c.tipo_vehiculo && <span>{iconoVehiculo(c.tipo_vehiculo)} {c.placa}</span>}
                      {c.bodega && <span className="text-slate-500 text-xs">📍 {c.bodega.nombre}</span>}
                    </div>
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <span className={`text-xs rounded-full px-2 py-0.5 ${ESTADO_COLOR[c.estado]}`}>{ESTADO_LABEL[c.estado] ?? c.estado}</span>
                  {!['CANCELADA', 'COMPLETADA'].includes(c.estado) && <>
                    <button onClick={() => accion(c.id, 'confirmar')} className="text-emerald-400 text-sm hover:underline">Confirmar</button>
                    <button onClick={() => accion(c.id, 'cancelar')} className="text-red-400 text-sm hover:underline">Cancelar</button>
                  </>}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Vista SEMANA: clic en un día abre el formulario de cita con esa fecha */}
      {vista === 'semana' && (
        <div className="grid grid-cols-2 md:grid-cols-7 gap-2">
          {Array.from({ length: 7 }, (_, i) => addDays(startOfWeek(fecha), i)).map((d) => (
            <div key={d} onClick={() => setNueva(d)} title="Agendar cita este día"
              className="rounded-lg border border-slate-800 bg-slate-800/30 p-2 min-h-28 cursor-pointer hover:border-emerald-600/60 hover:bg-slate-800/60 transition">
              <div className="text-xs text-slate-400 mb-1">{DIAS[d.getDay()]} {d.getDate()}</div>
              {citasDe(d).map((c) => (
                <div key={c.id} className={`text-xs rounded px-1.5 py-1 mb-1 ${ESTADO_COLOR[c.estado]}`}>
                  {iconoServicio(c) && `${iconoServicio(c)} `}{fmtHora(c.inicio)} {c.cliente?.nombre_completo}
                </div>
              ))}
            </div>
          ))}
        </div>
      )}

      {/* Vista MES: clic en un día abre el formulario de cita con esa fecha */}
      {vista === 'mes' && <VistaMes fecha={fecha} citas={citas} onDia={(d) => { setFecha(d); setNueva(d) }} />}

      {nueva && (
        <NuevaCita clientes={clientes} servicios={servicios} planes={planes} sucursales={sucursales} esLavadero={esLavadero} fechaInicial={nueva}
          onClose={() => setNueva(null)} onCreada={() => { setNueva(null); cargar() }} />
      )}
    </div>
  )
}

function VistaMes({ fecha, citas, onDia }) {
  const first = new Date(fecha.getFullYear(), fecha.getMonth(), 1)
  const startPad = first.getDay()
  const diasMes = new Date(fecha.getFullYear(), fecha.getMonth() + 1, 0).getDate()
  const celdas = [...Array(startPad).fill(null), ...Array.from({ length: diasMes }, (_, i) => i + 1)]
  const citasDia = (dia) => citas.filter((c) => {
    const d = new Date(c.inicio)
    return d.getDate() === dia && d.getMonth() === fecha.getMonth()
  })
  const hoy = new Date()
  const esHoy = (dia) => hoy.getDate() === dia && hoy.getMonth() === fecha.getMonth() && hoy.getFullYear() === fecha.getFullYear()

  return (
    <div className="grid grid-cols-7 gap-1">
      {DIAS.map((d) => <div key={d} className="text-center text-xs text-slate-500 py-1">{d}</div>)}
      {celdas.map((dia, i) => {
        const lista = dia ? citasDia(dia) : []
        return (
          <div key={i} onClick={() => dia && onDia(new Date(fecha.getFullYear(), fecha.getMonth(), dia))}
            className={`min-h-16 rounded border p-1 text-sm ${dia ? 'cursor-pointer border-slate-800 hover:bg-slate-800' : 'border-transparent opacity-30'} ${esHoy(dia) ? 'ring-1 ring-emerald-500' : ''}`}>
            {dia && <>
              <div className={esHoy(dia) ? 'font-bold text-emerald-400' : 'text-slate-400'}>{dia}</div>
              {lista.length > 0 && (
                <div className="mt-1 flex flex-wrap gap-1">
                  {lista.slice(0, 5).map((c) => (
                    iconoServicio(c) ? (
                      <span key={c.id} title={`${fmtHora(c.inicio)} ${c.cliente?.nombre_completo ?? ''} · ${c.plan_lavado?.nombre ?? c.servicio?.nombre ?? ''}`}
                        className="text-xs leading-none">{iconoServicio(c)}</span>
                    ) : (
                      <span key={c.id} title={`${fmtHora(c.inicio)} ${c.cliente?.nombre_completo ?? ''}`}
                        className={`h-2 w-2 rounded-full ${ESTADO_COLOR[c.estado]}`} />
                    )
                  ))}
                  {lista.length > 5 && <span className="text-[10px] text-slate-500">+{lista.length - 5}</span>}
                </div>
              )}
            </>}
          </div>
        )
      })}
    </div>
  )
}

const LINEA_VACIA = { servicio_id: '', personalizado: false, nombre_personalizado: '', precio_unitario: '', duracion_min: '' }

function NuevaCita({ clientes, servicios, planes, sucursales, esLavadero, fechaInicial, onClose, onCreada }) {
  const [lista, setLista] = useState(clientes)        // clientes (incluye los creados aquí)
  const [cliente_id, setCliente] = useState('')
  const [plan_lavado_id, setPlan] = useState('')
  const [bodega_id, setBodega] = useState('')
  const [empleado_id, setEmpleado] = useState('')
  const [personal, setPersonal] = useState([])
  const [tipo_vehiculo, setTipoVehiculo] = useState('')
  const [placa, setPlaca] = useState('')
  const [fecha, setFecha] = useState(ymd(fechaInicial instanceof Date ? fechaInicial : new Date()))
  const [slots, setSlots] = useState([])
  const [buscando, setBuscando] = useState(false)
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [limiteAlcanzado, setLimiteAlcanzado] = useState(false)

  // Servicios de esta cita (varios posibles, ej. Uñas + Pestañas); no aplica al flujo de lavadero.
  const [serviciosDisponibles, setServiciosDisponibles] = useState(servicios)
  const [lineasServicio, setLineasServicio] = useState([{ ...LINEA_VACIA }])

  const actualizarLinea = (i, cambios) => setLineasServicio((ls) => ls.map((l, idx) => (idx === i ? { ...l, ...cambios } : l)))
  const agregarLinea = () => setLineasServicio((ls) => [...ls, { ...LINEA_VACIA }])
  const quitarLinea = (i) => setLineasServicio((ls) => ls.filter((_, idx) => idx !== i))

  const totalPrecio = lineasServicio.reduce((s, l) => s + (Number(l.precio_unitario) || 0), 0)
  const totalDuracion = lineasServicio.reduce((s, l) => s + (Number(l.duracion_min) || 0), 0)

  // La sucursal elegida filtra el catálogo (servicios sin sucursal asignada se ven en todas).
  useEffect(() => {
    if (esLavadero) return
    api(`/servicios${bodega_id ? `?bodega_id=${bodega_id}` : ''}`).then(setServiciosDisponibles).catch(() => {})
  }, [bodega_id, esLavadero])

  // El profesional disponible también depende de la sucursal (sin sede fija = disponible en todas).
  useEffect(() => {
    api(`/personal${bodega_id ? `?bodega_id=${bodega_id}` : ''}`).then(setPersonal).catch(() => {})
  }, [bodega_id])

  // Crear cliente en línea (para usuarios que aún no tienen clientes).
  const [nuevoCliente, setNuevoCliente] = useState('')
  const [creandoCli, setCreandoCli] = useState(false)

  async function crearCliente() {
    if (!nuevoCliente.trim()) return
    setCreandoCli(true); setError('')
    try {
      const c = await api('/clientes', { method: 'POST', body: { nombre_completo: nuevoCliente.trim() } })
      setLista([c, ...lista])
      setCliente(String(c.id))
      setNuevoCliente('')
    } catch (err) { setError(err.message) }
    finally { setCreandoCli(false) }
  }

  // Los horarios sugeridos se cargan automáticamente al abrir el formulario
  // y cada vez que cambia la fecha o el servicio.
  useEffect(() => {
    let cancelado = false
    setBuscando(true); setError('')
    const filtro = (esLavadero
      ? (plan_lavado_id ? `&plan_lavado_id=${plan_lavado_id}` : '')
      : (totalDuracion ? `&duracion_min=${totalDuracion}` : '')) + (bodega_id ? `&bodega_id=${bodega_id}` : '')
    api(`/citas/disponibilidad?fecha=${fecha}${filtro}`)
      .then((data) => { if (!cancelado) setSlots(data.slots ?? []) })
      .catch((err) => { if (!cancelado) { setSlots([]); setError(err.message || 'No se pudo cargar la disponibilidad.') } })
      .finally(() => { if (!cancelado) setBuscando(false) })
    return () => { cancelado = true }
  }, [fecha, totalDuracion, plan_lavado_id, bodega_id, esLavadero])

  async function reservar(inicio) {
    if (!cliente_id) { setError('Selecciona o crea un cliente primero.'); return }
    if (esLavadero && (!tipo_vehiculo || !placa)) { setError('Indica el tipo de vehículo y la placa.'); return }
    const servicios = esLavadero ? [] : lineasServicio
      .filter((l) => (l.personalizado ? l.nombre_personalizado.trim() : l.servicio_id))
      .map((l) => (l.personalizado
        ? { nombre_personalizado: l.nombre_personalizado.trim(), precio_unitario: Number(l.precio_unitario) || 0, duracion_min: Number(l.duracion_min) || 0 }
        : { servicio_id: l.servicio_id, precio_unitario: l.precio_unitario === '' ? undefined : Number(l.precio_unitario), duracion_min: l.duracion_min === '' ? undefined : Number(l.duracion_min) }))
    setError(''); setLimiteAlcanzado(false); setGuardando(true)
    try {
      await api('/citas', {
        method: 'POST',
        body: {
          cliente_id,
          servicios: servicios.length ? servicios : undefined,
          plan_lavado_id: plan_lavado_id || null,
          bodega_id: bodega_id || null,
          empleado_id: empleado_id || null,
          tipo_vehiculo: tipo_vehiculo || null,
          placa: placa || null,
          inicio,
        },
      })
      onCreada()
    } catch (err) {
      setError(err.message)
      if (err.status === 403) setLimiteAlcanzado(true)
    } finally { setGuardando(false) }
  }

  return (
    <div onClick={onClose} className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
      <div onClick={(e) => e.stopPropagation()} className="bg-slate-800 rounded-2xl p-6 max-w-md w-full max-h-[85vh] overflow-y-auto">
        <h2 className="text-xl font-bold mb-4">Nueva cita</h2>
        {error && (
          <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-red-300 text-sm mb-3">
            {error}
            {limiteAlcanzado && <Link to="/planes" className="ml-2 underline text-blue-300 font-semibold">Actualizar plan →</Link>}
          </div>
        )}
        <div className="space-y-3">
          <select required value={cliente_id} onChange={(e) => setCliente(e.target.value)} className="input">
            <option value="">Cliente…</option>
            {lista.map((c) => <option key={c.id} value={c.id}>{c.nombre_completo}</option>)}
          </select>

          {/* Crear cliente sin salir de la cita */}
          <div className="flex gap-2">
            <input value={nuevoCliente} onChange={(e) => setNuevoCliente(e.target.value)} placeholder="o escribe un cliente nuevo…" className="input" />
            <button type="button" onClick={crearCliente} disabled={creandoCli || !nuevoCliente.trim()}
              className="rounded-lg bg-slate-600 hover:bg-slate-500 disabled:opacity-50 px-3 text-sm whitespace-nowrap">{creandoCli ? '…' : '+ Crear'}</button>
          </div>

          {sucursales.length > 1 && (
            <select value={bodega_id} onChange={(e) => setBodega(e.target.value)} className="input">
              <option value="">📍 Todas las sucursales…</option>
              {sucursales.map((s) => <option key={s.id} value={s.id}>{s.nombre}</option>)}
            </select>
          )}

          {personal.length > 0 && (
            <select value={empleado_id} onChange={(e) => setEmpleado(e.target.value)} className="input">
              <option value="">Profesional (opcional)…</option>
              {personal.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          )}

          {esLavadero ? (
            <>
              <select value={plan_lavado_id} onChange={(e) => setPlan(e.target.value)} className="input">
                <option value="">Plan de lavado (opcional)…</option>
                {planes.map((p) => <option key={p.id} value={p.id}>{p.icono ? `${p.icono} ` : ''}{p.nombre} ({p.duracion_min} min)</option>)}
              </select>
              <div className="flex gap-2">
                <select required value={tipo_vehiculo} onChange={(e) => setTipoVehiculo(e.target.value)} className="input">
                  <option value="">Tipo de vehículo…</option>
                  <option value="moto">🏍️ Moto</option>
                  <option value="carro">🚗 Carro</option>
                </select>
                <input required placeholder="Placa" value={placa} onChange={(e) => setPlaca(e.target.value.toUpperCase())} className="input uppercase" />
              </div>
            </>
          ) : (
            <div className="space-y-2 rounded-lg border border-slate-700 p-3">
              <p className="text-xs text-slate-400">Servicios de esta cita</p>
              {lineasServicio.map((l, i) => (
                <div key={i} className="space-y-1.5 border-b border-slate-700/50 pb-2 last:border-0 last:pb-0">
                  <div className="flex gap-2">
                    <select
                      value={l.personalizado ? '__personalizado__' : l.servicio_id}
                      onChange={(e) => {
                        const v = e.target.value
                        if (v === '__personalizado__') {
                          actualizarLinea(i, { personalizado: true, servicio_id: '', nombre_personalizado: '', precio_unitario: '', duracion_min: '' })
                        } else {
                          const s = serviciosDisponibles.find((sv) => String(sv.id) === v)
                          actualizarLinea(i, { personalizado: false, servicio_id: v, precio_unitario: s?.precio ?? '', duracion_min: s?.duracion_min ?? '' })
                        }
                      }}
                      className="input flex-1">
                      <option value="">Servicio…</option>
                      {serviciosDisponibles.map((s) => <option key={s.id} value={s.id}>{s.icono ? `${s.icono} ` : ''}{s.nombre}</option>)}
                      <option value="__personalizado__">✏️ Personalizado…</option>
                    </select>
                    {lineasServicio.length > 1 && (
                      <button type="button" onClick={() => quitarLinea(i)} className="text-red-400 px-2" title="Quitar">✕</button>
                    )}
                  </div>
                  {l.personalizado && (
                    <input placeholder="Nombre del servicio" value={l.nombre_personalizado}
                      onChange={(e) => actualizarLinea(i, { nombre_personalizado: e.target.value })} className="input" />
                  )}
                  <div className="flex gap-2">
                    <input type="number" min="0" placeholder="Precio" value={l.precio_unitario}
                      onChange={(e) => actualizarLinea(i, { precio_unitario: e.target.value })} className="input" />
                    <input type="number" min="1" placeholder="Duración (min)" value={l.duracion_min}
                      onChange={(e) => actualizarLinea(i, { duracion_min: e.target.value })} className="input" />
                  </div>
                </div>
              ))}
              <button type="button" onClick={agregarLinea} className="text-sm text-emerald-400 hover:underline">+ Añadir otro servicio</button>
              {(totalPrecio > 0 || totalDuracion > 0) && (
                <p className="text-sm text-slate-300 pt-1 border-t border-slate-700">
                  Total: <span className="font-semibold">${totalPrecio.toLocaleString('es-CO')}</span> · {totalDuracion} min
                </p>
              )}
            </div>
          )}

          <div className="grid gap-2">
            <input type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} className="input" />
          </div>
        </div>

        {buscando && <p className="text-sm text-slate-400 mt-4">Buscando horarios disponibles…</p>}
        {!buscando && slots.length === 0 && !error && (
          <p className="text-sm text-slate-500 mt-4">No hay horarios disponibles para esta fecha (revisa el horario laboral en Configuración).</p>
        )}

        {slots.length > 0 && (
          <div className="mt-4">
            <p className="text-sm text-slate-400 mb-2">Toca un horario disponible para reservar:</p>
            <div className="grid grid-cols-3 gap-2">
              {slots.map((s, i) => (
                <button key={i} disabled={!s.disponible || !cliente_id || guardando}
                  onClick={() => reservar(s.inicio)}
                  className={`text-sm rounded-lg py-2 ${s.disponible ? 'bg-emerald-700 hover:bg-emerald-600' : 'bg-slate-700 opacity-40 cursor-not-allowed line-through'}`}>
                  {fmtHora(s.inicio)}
                </button>
              ))}
            </div>
            {!cliente_id && <p className="text-amber-400 text-xs mt-2">Selecciona o crea un cliente primero.</p>}
          </div>
        )}
        <button onClick={onClose} className="mt-4 rounded-lg bg-slate-700 px-4 py-2 text-sm w-full">Cerrar</button>
      </div>
    </div>
  )
}
