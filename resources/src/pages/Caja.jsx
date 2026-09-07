import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { aNumero } from '../utils/numero'

const COP = (n) => '$' + Number(n ?? 0).toLocaleString('es-CO')
const CATEGORIAS_ICONO = { arriendo: '🏠', servicios: '💡', papeleria: '📎', nomina: '👥', insumos: '🧰', transporte: '🚚', otros: '📌' }

export default function Caja() {
  const { user } = useAuth()
  const esPropietario = user?.es_super_admin || ['Administrador', 'Usuario'].includes(user?.rol?.nombre)

  const [estado, setEstado] = useState(null) // {sesion, ventas, gastos, esperado} | {sesion:null}
  const [utilidad, setUtilidad] = useState(null)
  const [historial, setHistorial] = useState([])
  const [cierre, setCierre] = useState(null) // resultado del cierre (para mostrar el descuadre)
  const [cargando, setCargando] = useState(true)

  const cargar = useCallback(async () => {
    setCargando(true)
    try {
      const [actual, util, hist] = await Promise.all([
        api('/caja/actual'),
        api('/reportes/utilidad-dia').catch(() => null),
        api('/caja/sesiones').catch(() => ({ data: [] })),
      ])
      setEstado(actual)
      setUtilidad(util)
      setHistorial(hist.data ?? [])
    } finally { setCargando(false) }
  }, [])

  useEffect(() => { cargar() }, [cargar])

  if (cargando) return <p className="text-slate-500">Cargando caja…</p>

  return (
    <div>
      <h1 className="text-2xl font-bold mb-1">Caja</h1>
      <p className="text-slate-400 text-sm mb-6">Apertura y cierre de turno con arqueo, y registro de gastos del día.</p>

      {/* Resultado del último cierre (descuadre) */}
      {cierre && (
        <div className={`mb-6 rounded-2xl border p-5 ${Math.abs(cierre.descuadre) < 0.01 ? 'border-emerald-500/40 bg-emerald-500/10' : 'border-amber-500/40 bg-amber-500/10'}`}>
          <h2 className="font-bold">{Math.abs(cierre.descuadre) < 0.01 ? '✅ Caja cuadrada' : cierre.descuadre < 0 ? '⚠️ Faltó dinero en caja' : '⚠️ Sobró dinero en caja'}</h2>
          <p className="text-sm mt-1">
            Esperado: <b>{COP(cierre.esperado)}</b> · Contado: <b>{COP(cierre.sesion.monto_cierre)}</b>
            {Math.abs(cierre.descuadre) >= 0.01 && <> · Descuadre: <b>{COP(cierre.descuadre)}</b></>}
          </p>
          <button onClick={() => setCierre(null)} className="text-xs text-slate-400 hover:text-white mt-2">Cerrar aviso</button>
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Turno actual */}
        {estado?.sesion
          ? <TurnoAbierto estado={estado} onCerrado={(r) => { setCierre(r); cargar() }} />
          : <AbrirTurno onAbierto={cargar} />}

        {/* Utilidad del día */}
        <div className="rounded-2xl border border-slate-800 bg-slate-800/40 p-5">
          <h2 className="font-bold mb-3">📈 Utilidad neta de hoy</h2>
          <div className="grid grid-cols-3 gap-3 text-center">
            <div className="rounded-xl bg-slate-800/70 p-3">
              <p className="text-lg font-extrabold text-emerald-400">{COP(utilidad?.ventas)}</p>
              <p className="text-xs text-slate-400">Ventas</p>
            </div>
            <div className="rounded-xl bg-slate-800/70 p-3">
              <p className="text-lg font-extrabold text-red-400">-{COP(utilidad?.gastos)}</p>
              <p className="text-xs text-slate-400">Gastos</p>
            </div>
            <div className="rounded-xl bg-slate-800/70 p-3">
              <p className={`text-lg font-extrabold ${(utilidad?.utilidad_neta ?? 0) >= 0 ? 'text-white' : 'text-red-400'}`}>{COP(utilidad?.utilidad_neta)}</p>
              <p className="text-xs text-slate-400">Utilidad neta</p>
            </div>
          </div>
        </div>
      </div>

      <Ingresos onCambio={cargar} />

      {/* Cobro de órdenes de servicio por placa (lavadero, taller, barbería) */}
      <CobrarOrden onCobrada={cargar} />

      {/* Gastos del día */}
      <Gastos esPropietario={esPropietario} onCambio={cargar} />

      {/* Historial de turnos */}
      <h2 className="font-bold mt-8 mb-3">Historial de turnos</h2>
      <div className="rounded-xl border border-slate-800 overflow-x-auto">
        <table className="w-full text-sm min-w-[640px]">
          <thead className="bg-slate-800/60 text-slate-400 text-xs">
            <tr>
              <th className="text-left px-3 py-2">Cajero</th>
              <th className="text-left px-3 py-2">Apertura</th>
              <th className="text-left px-3 py-2">Cierre</th>
              <th className="text-right px-3 py-2">Base</th>
              <th className="text-right px-3 py-2">Esperado</th>
              <th className="text-right px-3 py-2">Contado</th>
              <th className="text-right px-3 py-2">Descuadre</th>
            </tr>
          </thead>
          <tbody>
            {historial.length === 0 && <tr><td colSpan="7" className="px-3 py-4 text-center text-slate-500">Sin turnos registrados.</td></tr>}
            {historial.map((s) => (
              <tr key={s.id} className="border-t border-slate-800">
                <td className="px-3 py-2">{s.cajero?.name ?? '—'}{s.bodega ? ` · ${s.bodega.nombre}` : ''}</td>
                <td className="px-3 py-2 text-slate-400">{new Date(s.abierta_at).toLocaleString('es-CO')}</td>
                <td className="px-3 py-2 text-slate-400">{s.cerrada_at ? new Date(s.cerrada_at).toLocaleString('es-CO') : <span className="text-emerald-400">Abierta</span>}</td>
                <td className="text-right px-3 py-2">{COP(s.monto_apertura)}</td>
                <td className="text-right px-3 py-2">{s.monto_esperado != null ? COP(s.monto_esperado) : '—'}</td>
                <td className="text-right px-3 py-2">{s.monto_cierre != null ? COP(s.monto_cierre) : '—'}</td>
                <td className={`text-right px-3 py-2 font-semibold ${s.descuadre == null ? '' : Math.abs(s.descuadre) < 0.01 ? 'text-emerald-400' : 'text-amber-400'}`}>
                  {s.descuadre != null ? COP(s.descuadre) : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

const METODOS = [['EFECTIVO', '💵 Efectivo'], ['TARJETA', '💳 Tarjeta'], ['NEQUI', '📱 Nequi'], ['DAVIPLATA', '📱 Daviplata'], ['TRANSFERENCIA', '🏦 Transferencia']]

/**
 * Caja rápida: busca la placa o el número de orden, precarga el total de la
 * orden de servicio y la cobra (genera factura + recibo al correo).
 */
function CobrarOrden({ onCobrada }) {
  const [q, setQ] = useState('')
  const [ordenes, setOrdenes] = useState([])
  const [sel, setSel] = useState(null)
  const [metodo, setMetodo] = useState('EFECTIVO')
  const [cobrando, setCobrando] = useState(false)
  const [ok, setOk] = useState('')
  const [error, setError] = useState('')

  async function buscar(e) {
    e.preventDefault()
    if (!q.trim()) return
    setError(''); setOk(''); setSel(null)
    try {
      const r = await api(`/ordenes-servicio/buscar?q=${encodeURIComponent(q.trim())}`)
      const lista = r.ordenes ?? []
      setOrdenes(lista)
      if (lista.length === 0) setError('No hay órdenes pendientes con esa placa u orden.')
      if (lista.length === 1) setSel(lista[0])
    } catch (err) { setError(err.message) }
  }

  async function cobrar() {
    if (!sel) return
    setCobrando(true); setError(''); setOk('')
    try {
      const f = await api(`/ordenes-servicio/${sel.id}/facturar`, { method: 'POST', body: { metodo_pago: metodo } })
      setOk(`✅ Orden ${sel.numero_orden} cobrada — Factura ${f.numero} por ${COP(f.total)} (${metodo}). Recibo enviado al correo del cliente.`)
      setOrdenes([]); setSel(null); setQ('')
      onCobrada()
    } catch (err) { setError(err.message) } finally { setCobrando(false) }
  }

  return (
    <div className="mt-8 rounded-2xl border border-slate-800 bg-slate-800/40 p-5">
      <h2 className="font-bold mb-1">🏍️ Cobrar orden de servicio</h2>
      <p className="text-sm text-slate-400 mb-3">Busca por placa o número de orden: el sistema precarga el total y cobra en un paso.</p>

      <form onSubmit={buscar} className="flex gap-2 mb-3">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Placa (ABC123) o número de orden (SO-...)" className="input !mt-0" />
        <button className="rounded-lg bg-sky-600 hover:bg-sky-500 px-4 py-2 text-sm font-semibold whitespace-nowrap">Buscar</button>
      </form>

      {error && <p className="text-sm text-red-300 mb-2">{error}</p>}
      {ok && <p className="text-sm rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-emerald-300 mb-2">{ok}</p>}

      {ordenes.length > 0 && (
        <div className="grid gap-2 mb-3">
          {ordenes.map((o) => (
            <button key={o.id} onClick={() => setSel(o)}
              className={`text-left rounded-lg border px-3 py-2 text-sm transition ${sel?.id === o.id ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-700 hover:bg-slate-800'}`}>
              <b>{o.numero_orden}</b>
              {o.asset_vehicle && <> · {o.asset_vehicle.marca} {o.asset_vehicle.modelo} ({o.asset_vehicle.placa_identificador})</>}
              <span className="float-right font-bold">{COP(o.total)}</span>
            </button>
          ))}
        </div>
      )}

      {sel && (
        <div className="rounded-xl border border-emerald-600/40 bg-emerald-500/5 p-4">
          <p className="mb-3">Total a cobrar: <b className="text-xl">{COP(sel.total)}</b> <span className="text-slate-400 text-sm">({sel.numero_orden})</span></p>
          <div className="flex flex-wrap gap-2 mb-3">
            {METODOS.map(([v, label]) => (
              <button key={v} onClick={() => setMetodo(v)}
                className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${metodo === v ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>
                {label}
              </button>
            ))}
          </div>
          <button onClick={cobrar} disabled={cobrando}
            className="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 py-2.5 text-sm font-semibold">
            {cobrando ? 'Cobrando…' : `Cobrar ${COP(sel.total)} y enviar recibo`}
          </button>
        </div>
      )}
    </div>
  )
}

function AbrirTurno({ onAbierto }) {
  const [monto, setMonto] = useState('')
  const [notas, setNotas] = useState('')
  const [guardando, setGuardando] = useState(false)

  async function abrir(e) {
    e.preventDefault()
    setGuardando(true)
    try {
      await api('/caja/abrir', { method: 'POST', body: { monto_apertura: aNumero(monto), notas_apertura: notas || null } })
      onAbierto()
    } catch (err) { alert(err.message || 'No se pudo abrir la caja.') } finally { setGuardando(false) }
  }

  return (
    <div className="rounded-2xl border border-slate-800 bg-slate-800/40 p-5">
      <h2 className="font-bold mb-1">🔓 Abrir turno de caja</h2>
      <p className="text-sm text-slate-400 mb-4">Registra la base en efectivo con la que inicias el turno.</p>
      <form onSubmit={abrir} className="space-y-3">
        <label className="block text-sm text-slate-300">Base de apertura (COP) *
          <input type="text" inputMode="decimal" value={monto} onChange={(e) => setMonto(e.target.value)} className="input mt-1" required placeholder="Ej: 100000" />
        </label>
        <label className="block text-sm text-slate-300">Notas
          <input value={notas} onChange={(e) => setNotas(e.target.value)} className="input mt-1" placeholder="Opcional" />
        </label>
        <button disabled={guardando} className="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 py-2.5 text-sm font-semibold">
          {guardando ? 'Abriendo…' : 'Abrir caja'}
        </button>
      </form>
    </div>
  )
}

function TurnoAbierto({ estado, onCerrado }) {
  const { sesion, ventas, gastos, esperado, por_metodo } = estado
  const [contado, setContado] = useState('')
  const [notas, setNotas] = useState('')
  const [cerrando, setCerrando] = useState(false)

  async function cerrar(e) {
    e.preventDefault()
    if (!confirm('¿Cerrar el turno de caja? Se calculará el arqueo y el descuadre.')) return
    setCerrando(true)
    try {
      const r = await api(`/caja/${sesion.id}/cerrar`, { method: 'POST', body: { monto_cierre: aNumero(contado), notas_cierre: notas || null } })
      onCerrado(r)
    } catch (err) { alert(err.message || 'No se pudo cerrar la caja.') } finally { setCerrando(false) }
  }

  return (
    <div className="rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-5">
      <div className="flex items-center justify-between mb-3">
        <h2 className="font-bold">🟢 Turno abierto</h2>
        <span className="text-xs text-slate-400">Desde {new Date(sesion.abierta_at).toLocaleString('es-CO')}</span>
      </div>
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center mb-4">
        <div className="rounded-xl bg-slate-800/70 p-3"><p className="font-extrabold">{COP(sesion.monto_apertura)}</p><p className="text-xs text-slate-400">Base</p></div>
        <div className="rounded-xl bg-slate-800/70 p-3"><p className="font-extrabold text-emerald-400">{COP(ventas)}</p><p className="text-xs text-slate-400">Ventas</p></div>
        <div className="rounded-xl bg-slate-800/70 p-3"><p className="font-extrabold text-red-400">-{COP(gastos)}</p><p className="text-xs text-slate-400">Gastos</p></div>
        <div className="rounded-xl bg-slate-800/70 p-3"><p className="font-extrabold text-sky-300">{COP(esperado)}</p><p className="text-xs text-slate-400">Esperado</p></div>
      </div>

      {/* Desglose de ventas por medio de pago */}
      {(por_metodo ?? []).length > 0 && (
        <div className="flex flex-wrap gap-2 mb-4">
          {por_metodo.map((m) => (
            <span key={m.metodo} className="rounded-full bg-slate-800 px-3 py-1 text-xs text-slate-300">
              {m.metodo}: <b className="text-white">{COP(m.total)}</b> <span className="text-slate-500">({m.facturas})</span>
            </span>
          ))}
        </div>
      )}

      <form onSubmit={cerrar} className="space-y-3">
        <label className="block text-sm text-slate-300">Efectivo contado al cierre (COP) *
          <input type="text" inputMode="decimal" value={contado} onChange={(e) => setContado(e.target.value)} className="input mt-1" required placeholder="Cuenta el dinero de la caja" />
        </label>
        <label className="block text-sm text-slate-300">Notas de cierre
          <input value={notas} onChange={(e) => setNotas(e.target.value)} className="input mt-1" placeholder="Opcional" />
        </label>
        <button disabled={cerrando} className="w-full rounded-lg bg-red-600 hover:bg-red-500 disabled:opacity-50 py-2.5 text-sm font-semibold">
          {cerrando ? 'Cerrando…' : '🔒 Cerrar turno (arqueo)'}
        </button>
      </form>
    </div>
  )
}

function Ingresos({ onCambio }) {
  const [form, setForm] = useState({ descripcion: '', monto: '', nombreCliente: '', cedula: '' })
  const [guardando, setGuardando] = useState(false)
  const [lista, setLista] = useState([])
  const [total, setTotal] = useState(0)

  async function agregar(e) {
    e.preventDefault()
    setGuardando(true)
    try {
      const r = await api('/caja/ingresos', { method: 'POST', body: { descripcion: form.descripcion, monto: aNumero(form.monto), nombre_cliente: form.nombreCliente, cedula: form.cedula } })
      const clienteNombre = r.cliente?.nombre_completo ?? form.nombreCliente
      const clienteCedula = r.cliente?.numero_documento ?? form.cedula
      setLista((prev) => [{ id: r.id, descripcion: r.detalles?.[0]?.descripcion ?? form.descripcion, monto: Number(r.total || 0), cliente_nombre: clienteNombre, cliente_cedula: clienteCedula }, ...prev])
      setTotal((prev) => prev + Number(r.total || 0))
      setForm({ descripcion: '', monto: '', nombreCliente: '', cedula: '' })
      onCambio()
    } catch (err) { alert(err.message || 'No se pudo registrar el ingreso.') } finally { setGuardando(false) }
  }

  return (
    <div className="mt-8">
      <div className="flex items-center justify-between mb-3">
        <h2 className="font-bold">📥 Ingresos de hoy</h2>
        <span className="text-sm text-slate-400">Total: <b className="text-emerald-400">{COP(total)}</b></span>
      </div>

      <form onSubmit={agregar} className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-2 items-end rounded-xl border border-slate-800 bg-slate-800/30 p-3 mb-4">
        <label className="block text-xs text-slate-400">Nombre del Cliente *
          <input value={form.nombreCliente} onChange={(e) => setForm({ ...form, nombreCliente: e.target.value })} className="input !mt-1" required placeholder="Ej: Carlos Pérez" />
        </label>
        <label className="block text-xs text-slate-400">Cédula / CC *
          <input value={form.cedula} onChange={(e) => setForm({ ...form, cedula: e.target.value })} className="input !mt-1" required placeholder="Ej: 123456789" />
        </label>
        <label className="block text-xs text-slate-400">Descripción *
          <input value={form.descripcion} onChange={(e) => setForm({ ...form, descripcion: e.target.value })} className="input !mt-1" required placeholder="Ej: Ingreso por venta extra" />
        </label>
        <label className="block text-xs text-slate-400">Monto (COP) *
          <input type="text" inputMode="decimal" value={form.monto} onChange={(e) => setForm({ ...form, monto: e.target.value })} className="input !mt-1" required />
        </label>
        <button disabled={guardando} className="xl:col-span-4 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-3 py-2 text-sm font-semibold">
          {guardando ? 'Guardando…' : '+ Registrar ingreso'}
        </button>
      </form>

      <div className="grid gap-2">
        {lista.length === 0 && <p className="text-slate-500 text-sm text-center py-3">Sin ingresos registrados hoy.</p>}
        {lista.map((g) => (
          <div key={g.id} className="flex items-center gap-3 rounded-lg border border-slate-800 bg-slate-800/40 px-4 py-2.5 text-sm">
            <span>💵</span>
            <div className="flex flex-col">
              <span className="font-medium">{g.descripcion}</span>
              <span className="text-xs text-slate-400">{g.cliente_nombre ?? ''}{g.cliente_cedula ? ` · ${g.cliente_cedula}` : ''}</span>
            </div>
            <span className="ml-auto font-semibold text-emerald-400">+{COP(g.monto)}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

function Gastos({ esPropietario, onCambio }) {
  const hoy = new Date().toISOString().slice(0, 10)
  const [datos, setDatos] = useState({ gastos: { data: [] }, total: 0, categorias: [] })
  const [form, setForm] = useState({ categoria: 'otros', descripcion: '', monto: '' })
  const [guardando, setGuardando] = useState(false)

  const cargar = useCallback(() => {
    api(`/gastos?desde=${hoy}&hasta=${hoy}`).then(setDatos).catch(() => {})
  }, [hoy])
  useEffect(() => { cargar() }, [cargar])

  async function agregar(e) {
    e.preventDefault()
    setGuardando(true)
    try {
      await api('/gastos', { method: 'POST', body: { ...form, monto: aNumero(form.monto) } })
      setForm({ categoria: 'otros', descripcion: '', monto: '' })
      cargar(); onCambio()
    } catch (err) { alert(err.message || 'No se pudo registrar el gasto.') } finally { setGuardando(false) }
  }

  async function eliminar(id) {
    if (!confirm('¿Eliminar este gasto?')) return
    try { await api(`/gastos/${id}`, { method: 'DELETE' }); cargar(); onCambio() }
    catch (err) { alert(err.message) }
  }

  const lista = datos.gastos?.data ?? []

  return (
    <div className="mt-8">
      <div className="flex items-center justify-between mb-3">
        <h2 className="font-bold">💸 Gastos de hoy</h2>
        <span className="text-sm text-slate-400">Total: <b className="text-red-400">{COP(datos.total)}</b></span>
      </div>

      <form onSubmit={agregar} className="grid grid-cols-2 md:grid-cols-4 gap-2 items-end rounded-xl border border-slate-800 bg-slate-800/30 p-3 mb-4">
        <label className="block text-xs text-slate-400">Categoría
          <select value={form.categoria} onChange={(e) => setForm({ ...form, categoria: e.target.value })} className="input !mt-1">
            {(datos.categorias.length ? datos.categorias : Object.keys(CATEGORIAS_ICONO)).map((c) => (
              <option key={c} value={c}>{CATEGORIAS_ICONO[c] ?? ''} {c}</option>
            ))}
          </select>
        </label>
        <label className="block text-xs text-slate-400">Descripción *
          <input value={form.descripcion} onChange={(e) => setForm({ ...form, descripcion: e.target.value })} className="input !mt-1" required placeholder="Ej: recibo de la luz" />
        </label>
        <label className="block text-xs text-slate-400">Monto (COP) *
          <input type="text" inputMode="decimal" value={form.monto} onChange={(e) => setForm({ ...form, monto: e.target.value })} className="input !mt-1" required />
        </label>
        <button disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-3 py-2 text-sm font-semibold">
          {guardando ? 'Guardando…' : '+ Registrar gasto'}
        </button>
      </form>

      <div className="grid gap-2">
        {lista.length === 0 && <p className="text-slate-500 text-sm text-center py-3">Sin gastos registrados hoy.</p>}
        {lista.map((g) => (
          <div key={g.id} className="flex items-center gap-3 rounded-lg border border-slate-800 bg-slate-800/40 px-4 py-2.5 text-sm">
            <span>{CATEGORIAS_ICONO[g.categoria] ?? '📌'}</span>
            <span className="font-medium">{g.descripcion}</span>
            <span className="text-xs text-slate-500">{g.categoria} · {g.registrado_por?.name ?? ''}</span>
            <span className="ml-auto font-semibold text-red-400">-{COP(g.monto)}</span>
            {esPropietario && <button onClick={() => eliminar(g.id)} className="text-slate-500 hover:text-red-400">🗑️</button>}
          </div>
        ))}
      </div>
    </div>
  )
}
