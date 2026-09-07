import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import { aNumero } from '../utils/numero'

const COP = (n) => '$' + Number(n ?? 0).toLocaleString('es-CO')

const ESTADO_MESA = {
  LIBRE: { label: 'Libre', clase: 'border-slate-800 bg-slate-800/40 hover:bg-slate-800/70' },
  OCUPADA: { label: 'Ocupada', clase: 'border-amber-600/60 bg-amber-500/10 hover:bg-amber-500/20' },
  RESERVADA: { label: 'Reservada', clase: 'border-sky-600/60 bg-sky-500/10 hover:bg-sky-500/20' },
}

const ESTADO_COCINA = {
  PENDIENTE: { label: 'Pendiente', clase: 'bg-slate-700 text-slate-200' },
  PREPARANDO: { label: 'Preparando', clase: 'bg-amber-600 text-white' },
  LISTO: { label: 'Listo', clase: 'bg-emerald-600 text-white' },
  ENTREGADO: { label: 'Entregado', clase: 'bg-slate-800 text-slate-500' },
}
const SIGUIENTE_ESTADO = { PENDIENTE: 'PREPARANDO', PREPARANDO: 'LISTO', LISTO: 'ENTREGADO' }

export default function Restaurante() {
  const [tab, setTab] = useState('mesas')

  return (
    <div>
      <h1 className="text-2xl font-bold mb-1">Restaurante</h1>
      <p className="text-slate-400 text-sm mb-5">Plano de mesas, comandas y pantalla de cocina.</p>

      <div className="flex gap-2 mb-6">
        {[['mesas', '🍽️ Mesas'], ['cocina', '👨‍🍳 Cocina (KDS)']].map(([id, label]) => (
          <button key={id} onClick={() => setTab(id)}
            className={`rounded-lg px-4 py-2 text-sm font-semibold transition ${tab === id ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>
            {label}
          </button>
        ))}
      </div>

      {tab === 'mesas' && <PlanoMesas />}
      {tab === 'cocina' && <Cocina />}
    </div>
  )
}

/* ============ Plano de mesas ============ */
function PlanoMesas() {
  const [mesas, setMesas] = useState([])
  const [cargando, setCargando] = useState(true)
  const [creando, setCreando] = useState(false)
  const [comandaAbierta, setComandaAbierta] = useState(null) // {mesaId, comandaId}

  const cargar = useCallback(() => {
    setCargando(true)
    return api('/mesas').then(setMesas).catch(() => {}).finally(() => setCargando(false))
  }, [])
  useEffect(() => { cargar() }, [cargar])

  async function abrirMesa(mesa) {
    try {
      const c = await api(`/mesas/${mesa.id}/comanda`, { method: 'POST', body: {} })
      setComandaAbierta({ mesaId: mesa.id, comandaId: c.id })
    } catch (err) { alert(err.message || 'No se pudo abrir la mesa.') }
  }

  return (
    <div>
      <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 mb-6">
        {mesas.map((m) => {
          const est = ESTADO_MESA[m.estado] ?? ESTADO_MESA.LIBRE
          return (
            <button key={m.id} onClick={() => abrirMesa(m)}
              className={`rounded-2xl border p-5 text-left transition ${est.clase}`}>
              <div className="flex items-center justify-between">
                <span className="text-2xl">🍽️</span>
                <span className="text-xs rounded-full bg-slate-900/60 px-2 py-0.5">{est.label}</span>
              </div>
              <p className="font-bold mt-2">{m.nombre}</p>
              <p className="text-xs text-slate-400">{m.capacidad} puestos</p>
              {m.comanda_abierta && (
                <p className="text-sm font-semibold text-emerald-400 mt-2">{COP(m.comanda_abierta.total)} · {m.comanda_abierta.items_count} ítem(s)</p>
              )}
            </button>
          )
        })}
        {!cargando && mesas.length === 0 && (
          <p className="col-span-full text-slate-500 text-center py-6">Aún no has creado mesas.</p>
        )}
        <button onClick={() => setCreando(true)}
          className="rounded-2xl border-2 border-dashed border-slate-700 hover:border-emerald-600 text-slate-500 hover:text-emerald-400 p-5 flex flex-col items-center justify-center gap-1 min-h-[132px]">
          <span className="text-2xl">+</span>
          <span className="text-sm">Nueva mesa</span>
        </button>
      </div>

      {creando && <ModalMesa onClose={() => setCreando(false)} onGuardada={() => { setCreando(false); cargar() }} />}
      {comandaAbierta && (
        <ModalComanda comandaId={comandaAbierta.comandaId} onClose={() => { setComandaAbierta(null); cargar() }} />
      )}
    </div>
  )
}

function ModalMesa({ onClose, onGuardada }) {
  const [nombre, setNombre] = useState('')
  const [capacidad, setCapacidad] = useState(4)
  const [guardando, setGuardando] = useState(false)

  async function guardar(e) {
    e.preventDefault()
    setGuardando(true)
    try {
      await api('/mesas', { method: 'POST', body: { nombre, capacidad: Number(capacidad) || 4 } })
      onGuardada()
    } catch (err) { alert(err.message || 'No se pudo crear la mesa.') } finally { setGuardando(false) }
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-sm p-6" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold mb-4">Nueva mesa</h2>
        <form onSubmit={guardar} className="space-y-3">
          <label className="block text-sm text-slate-300">Nombre *
            <input value={nombre} onChange={(e) => setNombre(e.target.value)} className="input mt-1" required placeholder="Ej: Mesa 5, Barra, Terraza 1" />
          </label>
          <label className="block text-sm text-slate-300">Capacidad
            <input type="number" min="1" max="100" value={capacidad} onChange={(e) => setCapacidad(e.target.value)} className="input mt-1" />
          </label>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button type="submit" disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
              {guardando ? 'Creando…' : 'Crear mesa'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

/* ============ Comanda de una mesa ============ */
function ModalComanda({ comandaId, onClose }) {
  const [comanda, setComanda] = useState(null)
  const [productos, setProductos] = useState([])
  const [descripcion, setDescripcion] = useState('')
  const [productoId, setProductoId] = useState('')
  const [cantidad, setCantidad] = useState(1)
  const [precio, setPrecio] = useState('')
  const [notas, setNotas] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [cobrando, setCobrando] = useState(false)
  const [metodoPago, setMetodoPago] = useState('EFECTIVO')
  const [propina, setPropina] = useState('')

  const cargar = useCallback(() => api(`/comandas/${comandaId}`).then(setComanda).catch(() => {}), [comandaId])
  useEffect(() => { cargar() }, [cargar])
  useEffect(() => { api('/productos').then((r) => setProductos(r.data ?? [])).catch(() => {}) }, [])

  function alElegirProducto(pid) {
    const p = productos.find((x) => String(x.id) === String(pid))
    setProductoId(pid)
    if (p) { setDescripcion(p.nombre); setPrecio(p.precio_venta) }
  }

  async function agregar(e) {
    e.preventDefault()
    if (!descripcion.trim()) return
    setGuardando(true)
    try {
      await api(`/comandas/${comandaId}/items`, { method: 'POST', body: {
        producto_id: productoId ? Number(productoId) : null,
        descripcion: descripcion.trim(),
        cantidad: aNumero(cantidad) || 1,
        precio_unitario: aNumero(precio) || 0,
        notas: notas || null,
      } })
      setDescripcion(''); setProductoId(''); setCantidad(1); setPrecio(''); setNotas('')
      cargar()
    } catch (err) { alert(err.message || 'No se pudo agregar el ítem.') } finally { setGuardando(false) }
  }

  async function quitar(itemId) {
    try { await api(`/comandas/${comandaId}/items/${itemId}`, { method: 'DELETE' }); cargar() }
    catch (err) { alert(err.message) }
  }

  async function cobrar() {
    setCobrando(true)
    try {
      await api(`/comandas/${comandaId}/cobrar`, { method: 'POST', body: {
        metodo_pago: metodoPago,
        propina: propina ? aNumero(propina) : null,
      } })
      onClose()
    } catch (err) { alert(err.message || 'No se pudo cobrar la comanda.') } finally { setCobrando(false) }
  }

  async function cancelar() {
    if (!confirm('¿Cancelar la comanda? La mesa quedará libre sin generar factura.')) return
    try { await api(`/comandas/${comandaId}/cancelar`, { method: 'POST' }); onClose() }
    catch (err) { alert(err.message) }
  }

  if (!comanda) return null
  const total = (comanda.items ?? []).reduce((s, i) => s + Number(i.subtotal), 0)

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold">🍽️ {comanda.mesa?.nombre}</h2>
          <button onClick={onClose} className="text-slate-400 hover:text-white text-xl">✕</button>
        </div>

        {/* Ítems de la comanda con su estado en cocina */}
        <div className="rounded-xl border border-slate-800 overflow-hidden mb-4">
          <table className="w-full text-sm">
            <thead className="bg-slate-800/60 text-slate-400 text-xs">
              <tr>
                <th className="text-left px-3 py-2">Ítem</th>
                <th className="text-center px-2 py-2">Cant.</th>
                <th className="text-right px-2 py-2">Subtotal</th>
                <th className="text-left px-2 py-2">Cocina</th>
                <th className="px-2 py-2"></th>
              </tr>
            </thead>
            <tbody>
              {(comanda.items ?? []).length === 0 && (
                <tr><td colSpan="5" className="px-3 py-4 text-center text-slate-500">Aún no hay ítems.</td></tr>
              )}
              {(comanda.items ?? []).map((it) => (
                <tr key={it.id} className="border-t border-slate-800">
                  <td className="px-3 py-2">{it.descripcion}{it.notas ? <span className="text-slate-500"> ({it.notas})</span> : null}</td>
                  <td className="text-center px-2 py-2">{it.cantidad}</td>
                  <td className="text-right px-2 py-2 font-semibold">{COP(it.subtotal)}</td>
                  <td className="px-2 py-2">
                    <span className={`text-xs rounded-full px-2 py-0.5 ${ESTADO_COCINA[it.estado_cocina]?.clase ?? ''}`}>
                      {ESTADO_COCINA[it.estado_cocina]?.label ?? it.estado_cocina}
                    </span>
                  </td>
                  <td className="px-2 py-2 text-right">
                    <button onClick={() => quitar(it.id)} className="text-red-400 hover:text-red-300">🗑️</button>
                  </td>
                </tr>
              ))}
            </tbody>
            {(comanda.items ?? []).length > 0 && (
              <tfoot>
                <tr className="border-t border-slate-700 bg-slate-800/40">
                  <td colSpan="2" className="px-3 py-2 text-right text-slate-400">Total</td>
                  <td className="text-right px-2 py-2 font-bold">{COP(total)}</td>
                  <td colSpan="2"></td>
                </tr>
              </tfoot>
            )}
          </table>
        </div>

        {/* Agregar ítem */}
        <form onSubmit={agregar} className="grid grid-cols-2 md:grid-cols-6 gap-2 items-end rounded-xl border border-slate-800 bg-slate-800/30 p-3 mb-5">
          <label className="block text-xs text-slate-400 col-span-2">Producto (opcional)
            <select value={productoId} onChange={(e) => alElegirProducto(e.target.value)} className="input !mt-1">
              <option value="">— Texto libre —</option>
              {productos.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
            </select>
          </label>
          <label className="block text-xs text-slate-400 col-span-2">Descripción *
            <input value={descripcion} onChange={(e) => setDescripcion(e.target.value)} className="input !mt-1" required placeholder="Ej: Bandeja Paisa" />
          </label>
          <label className="block text-xs text-slate-400">Cant.
            <input type="text" inputMode="decimal" value={cantidad} onChange={(e) => setCantidad(e.target.value)} className="input !mt-1" />
          </label>
          <label className="block text-xs text-slate-400">Precio
            <input type="text" inputMode="decimal" value={precio} onChange={(e) => setPrecio(e.target.value)} className="input !mt-1" placeholder="Ej: 25.000" />
          </label>
          <label className="block text-xs text-slate-400 col-span-2 md:col-span-4">Notas (opcional)
            <input value={notas} onChange={(e) => setNotas(e.target.value)} className="input !mt-1" placeholder="Ej: sin cebolla, término medio…" />
          </label>
          <button disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-3 py-2 text-sm font-semibold col-span-2">
            {guardando ? 'Agregando…' : '+ Agregar a la comanda'}
          </button>
        </form>

        {/* Cobro */}
        <div className="rounded-xl border border-emerald-600/40 bg-emerald-500/5 p-4">
          <div className="flex flex-wrap gap-2 mb-3">
            {[['EFECTIVO', '💵 Efectivo'], ['TARJETA', '💳 Tarjeta'], ['NEQUI', '📱 Nequi'], ['DAVIPLATA', '📱 Daviplata'], ['TRANSFERENCIA', '🏦 Transferencia']].map(([v, label]) => (
              <button key={v} type="button" onClick={() => setMetodoPago(v)}
                className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${metodoPago === v ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>
                {label}
              </button>
            ))}
          </div>
          <label className="block text-sm text-slate-300 mb-3">Propina (opcional)
            <input type="text" inputMode="decimal" value={propina} onChange={(e) => setPropina(e.target.value)} className="input mt-1" placeholder="Ej: 10% sugerido → 6.600" />
          </label>
          <div className="flex gap-2">
            <button onClick={cancelar} className="rounded-lg bg-red-900/60 hover:bg-red-800 px-4 py-2.5 text-sm font-semibold">Cancelar mesa</button>
            <button onClick={cobrar} disabled={cobrando || total === 0}
              className="flex-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 py-2.5 text-sm font-semibold">
              {cobrando ? 'Cobrando…' : `Cobrar ${COP(total)} y enviar recibo`}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

/* ============ Pantalla de cocina (KDS) ============ */
function Cocina() {
  const [comandas, setComandas] = useState([])
  const [cargando, setCargando] = useState(true)

  const cargar = useCallback(() => {
    return api('/cocina/comandas').then(setComandas).catch(() => {}).finally(() => setCargando(false))
  }, [])

  useEffect(() => {
    cargar()
    const t = setInterval(cargar, 10000) // refresca cada 10s (nuevos pedidos de meseros)
    return () => clearInterval(t)
  }, [cargar])

  async function avanzar(item) {
    const siguiente = SIGUIENTE_ESTADO[item.estado_cocina]
    if (!siguiente) return
    try {
      await api(`/cocina/items/${item.id}/estado`, { method: 'PUT', body: { estado_cocina: siguiente } })
      cargar()
    } catch (err) { alert(err.message) }
  }

  if (cargando) return <p className="text-slate-500">Cargando cocina…</p>

  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      {comandas.length === 0 && <p className="col-span-full text-slate-500 text-center py-8">No hay pedidos pendientes en cocina. 🎉</p>}
      {comandas.map((c) => (
        <div key={c.id} className="rounded-2xl border border-slate-800 bg-slate-800/40 p-4">
          <div className="flex items-center justify-between mb-3">
            <h3 className="font-bold">🍽️ {c.mesa?.nombre}</h3>
            <span className="text-xs text-slate-500">{c.mesero?.name}</span>
          </div>
          <div className="space-y-2">
            {(c.items ?? []).map((it) => (
              <button key={it.id} onClick={() => avanzar(it)} disabled={it.estado_cocina === 'LISTO'}
                className={`w-full text-left rounded-lg px-3 py-2 text-sm transition ${ESTADO_COCINA[it.estado_cocina]?.clase ?? 'bg-slate-700'} ${it.estado_cocina !== 'LISTO' ? 'hover:opacity-80 cursor-pointer' : 'cursor-default'}`}>
                <div className="flex items-center justify-between">
                  <span className="font-semibold">{it.cantidad}× {it.descripcion}</span>
                  <span className="text-xs">{ESTADO_COCINA[it.estado_cocina]?.label}</span>
                </div>
                {it.notas && <p className="text-xs opacity-80 mt-0.5">📝 {it.notas}</p>}
              </button>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}
