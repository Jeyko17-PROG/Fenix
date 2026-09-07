import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { aNumero, formatearEnVivo } from '../utils/numero'

const MOV_VACIO = { tipo: 'ENTRADA', producto_id: '', cantidad: '', costo_unitario: '', bodega_origen_id: '', bodega_destino_id: '', motivo: '' }
const UNIDADES_MOV = ['UND', 'KG', 'LT', 'MT', 'CAJA', 'PAR', 'DOCENA', 'PAQUETE']

export default function Inventario() {
  const [stock, setStock] = useState([])
  const [alertas, setAlertas] = useState([])
  const [movimientos, setMovimientos] = useState([])
  const [productos, setProductos] = useState([])
  const [bodegas, setBodegas] = useState([])
  const [mov, setMov] = useState(MOV_VACIO)
  const [unidadMov, setUnidadMov] = useState('') // unidad en la que se está cargando la cantidad (puede ser distinta a la unidad base del producto)
  const [unidadPersonalizada, setUnidadPersonalizada] = useState('')
  const [factorManual, setFactorManual] = useState('') // cuántas unidades base equivalen a 1 de unidadMov, cuando no coincide con la unidad base ni con la presentación de compra configurada
  const [error, setError] = useState('')
  const [ok, setOk] = useState('')
  const [buscar, setBuscar] = useState('')
  const [editando, setEditando] = useState(null) // { id, cantidad, costo_unitario }
  const [filtroTipo, setFiltroTipo] = useState('')
  const [filtroBodega, setFiltroBodega] = useState('')

  async function cargar(termino = buscar, tipo = filtroTipo, bodegaId = filtroBodega) {
    const qs = termino ? `?buscar=${encodeURIComponent(termino)}` : ''
    const paramsMov = new URLSearchParams()
    if (tipo) paramsMov.set('tipo', tipo)
    if (bodegaId) paramsMov.set('bodega_id', bodegaId)
    const qsMov = paramsMov.toString() ? `?${paramsMov.toString()}` : ''
    const [s, a, m] = await Promise.all([
      api(`/inventario/stock${qs}`),
      api('/inventario/alertas'),
      api(`/inventario/movimientos${qsMov}`),
    ])
    setStock(s.data ?? s)
    setAlertas(a)
    setMovimientos(m.data ?? m)
  }

  async function eliminarStock(s) {
    const nombre = s.producto?.nombre ?? 'este producto (ya eliminado)'
    const aviso = Number(s.cantidad) > 0
      ? ` Tiene ${Number(s.cantidad).toLocaleString('es-CO')} unidades — se registrará un ajuste en el Kardex antes de borrarlo.`
      : ''
    if (!confirm(`¿Eliminar el registro de stock de "${nombre}" en ${s.bodega?.nombre}?${aviso} No se puede deshacer.`)) return
    try {
      await api(`/inventario/stock/${s.id}`, { method: 'DELETE' })
      cargar()
    } catch (err) {
      alert(err.message || 'No se pudo eliminar el registro de stock.')
    }
  }

  function iniciarEdicion(s) {
    setEditando({ id: s.id, cantidad: String(s.cantidad), costo_unitario: '' })
  }

  async function guardarEdicion() {
    if (!editando) return
    try {
      await api(`/inventario/stock/${editando.id}`, { method: 'PUT', body: {
        cantidad: aNumero(editando.cantidad),
        costo_unitario: editando.costo_unitario ? aNumero(editando.costo_unitario) : undefined,
      } })
      setEditando(null)
      cargar()
    } catch (err) {
      alert(err.message || 'No se pudo editar la cantidad.')
    }
  }

  async function eliminarMovimiento(m) {
    if (!confirm(`¿Eliminar este movimiento (${m.tipo} de ${Number(m.cantidad).toLocaleString('es-CO')} × ${m.producto?.nombre})? Esto ajusta el stock de vuelta y no se puede deshacer.`)) return
    try {
      await api(`/inventario/movimientos/${m.id}`, { method: 'DELETE' })
      cargar()
    } catch (err) {
      alert(err.message || 'No se pudo eliminar el movimiento.')
    }
  }
  useEffect(() => {
    cargar()
    api('/productos').then((d) => setProductos(d.data ?? d))
    api('/bodegas').then(setBodegas)
  }, [])

  // Vuelve a consultar cada vez que cambia el texto de búsqueda o los filtros
  // de movimientos (con un pequeño debounce para no disparar una petición por tecla).
  useEffect(() => {
    const t = setTimeout(() => cargar(buscar, filtroTipo, filtroBodega), 300)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [buscar, filtroTipo, filtroBodega])

  const productoSeleccionado = productos.find((p) => String(p.id) === String(mov.producto_id))
  const tienePresentacion = !!(productoSeleccionado?.unidad_compra && Number(productoSeleccionado?.unidades_por_compra) > 0)
  const unidadBase = productoSeleccionado?.unidad_medida ?? 'UND'
  // Opciones del selector: la lista fija de siempre + la unidad base del producto
  // y su presentación de compra (por si no estaban en la lista fija), sin repetidos.
  const opcionesUnidad = Array.from(new Set([unidadBase, ...UNIDADES_MOV, ...(tienePresentacion ? [productoSeleccionado.unidad_compra] : [])].filter(Boolean)))
  const unidadElegida = unidadMov === 'PERSONALIZADO' ? unidadPersonalizada.trim().toUpperCase() : unidadMov
  const esUnidadBase = !!unidadElegida && unidadElegida === unidadBase
  const coincideConCompra = tienePresentacion && unidadElegida === productoSeleccionado.unidad_compra
  const necesitaFactorManual = !!unidadElegida && !esUnidadBase && !coincideConCompra
  const factorEfectivo = esUnidadBase ? 1 : coincideConCompra ? Number(productoSeleccionado.unidades_por_compra) : (aNumero(factorManual) || null)

  async function registrar(e) {
    e.preventDefault()
    setError(''); setOk('')
    if (!unidadElegida) { setError('Selecciona la unidad en la que estás cargando la cantidad.'); return }
    if (necesitaFactorManual && !factorEfectivo) {
      setError(`Indica cuántas ${unidadBase} equivalen a 1 ${unidadElegida}.`)
      return
    }
    try {
      // El Kardex siempre guarda en unidad_medida (unidad base). Si el usuario
      // cargó la cantidad en otra unidad (ej. "3 CAJA"), se convierte a la
      // unidad base ANTES de mandarla — el stock real nunca se entera de que
      // existió una "caja", solo ve unidades. El costo unitario que se
      // escribe es "cuánto costó 1 {unidadElegida}" (ej. 1 CAJA), pero el
      // Kardex necesita el costo POR UNIDAD BASE para el promedio ponderado
      // — se divide por el mismo factor, si no se hiciera, "costó 120.000 la
      // caja de 12" se registraría como si costara 120.000 CADA unidad.
      const cantidadBase = aNumero(mov.cantidad) * factorEfectivo
      const costoBase = mov.costo_unitario ? aNumero(mov.costo_unitario) / factorEfectivo : undefined
      await api('/inventario/movimientos', { method: 'POST', body: {
        ...mov,
        cantidad: cantidadBase,
        costo_unitario: costoBase,
        bodega_origen_id: mov.bodega_origen_id || undefined,
        bodega_destino_id: mov.bodega_destino_id || undefined,
      } })
      setOk('Movimiento registrado.')
      setMov(MOV_VACIO)
      setUnidadMov(''); setUnidadPersonalizada(''); setFactorManual('')
      cargar()
    } catch (err) {
      setError(err.message)
    }
  }

  function elegirProducto(e) {
    const id = e.target.value
    const p = productos.find((x) => String(x.id) === String(id))
    setMov({ ...mov, producto_id: id })
    setUnidadMov(p?.unidad_medida ?? '')
    setUnidadPersonalizada(''); setFactorManual('')
  }

  function elegirUnidad(e) {
    setUnidadMov(e.target.value)
    setUnidadPersonalizada(''); setFactorManual('')
  }

  const set = (k) => (e) => setMov({ ...mov, [k]: e.target.value })
  const esTraslado = mov.tipo === 'TRASLADO'
  const usaOrigen = mov.tipo === 'SALIDA' || esTraslado
  const usaDestino = mov.tipo === 'ENTRADA' || esTraslado

  // Estado visual de cada registro de stock.
  function nivel(s) {
    const cant = Number(s.cantidad)
    const min = Number(s.stock_minimo)
    if (cant <= 0) return { label: 'Agotado', tono: 'red', pct: 0 }
    if (min > 0 && cant <= min) return { label: 'Bajo', tono: 'amber', pct: Math.max(8, (cant / (min * 2)) * 100) }
    return { label: 'OK', tono: 'emerald', pct: min > 0 ? Math.min(100, (cant / (min * 2)) * 100) : 100 }
  }
  const BARRA = { red: 'bg-red-500', amber: 'bg-amber-500', emerald: 'bg-emerald-500' }
  const BADGE = { red: 'bg-red-500/15 text-red-300', amber: 'bg-amber-500/15 text-amber-300', emerald: 'bg-emerald-500/15 text-emerald-300' }
  const FILA = { red: 'bg-red-500/5', amber: 'bg-amber-500/5', emerald: '' }

  const agotados = stock.filter((s) => Number(s.cantidad) <= 0).length
  const bajos = stock.filter((s) => { const c = Number(s.cantidad), m = Number(s.stock_minimo); return c > 0 && m > 0 && c <= m }).length
  const valorTotal = stock.reduce((t, s) => t + Number(s.cantidad) * Number(s.costo_promedio), 0)

  return (
    <div className="space-y-8">
      <h1 className="text-2xl font-bold">Inventario</h1>

      {/* Indicadores rápidos */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div className="rounded-2xl border border-slate-800 bg-slate-900/60 p-4">
          <p className="text-[11px] uppercase tracking-wide text-slate-400">Registros de stock</p>
          <p className="text-2xl font-bold">{stock.length}</p>
        </div>
        <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4">
          <p className="text-[11px] uppercase tracking-wide text-amber-300/80">Stock bajo</p>
          <p className="text-2xl font-bold text-amber-300">{bajos}</p>
        </div>
        <div className="rounded-2xl border border-red-500/30 bg-red-500/5 p-4">
          <p className="text-[11px] uppercase tracking-wide text-red-300/80">Agotados</p>
          <p className="text-2xl font-bold text-red-300">{agotados}</p>
        </div>
        <div className="rounded-2xl border border-slate-800 bg-slate-900/60 p-4">
          <p className="text-[11px] uppercase tracking-wide text-slate-400">Valor del inventario</p>
          <p className="text-2xl font-bold text-emerald-400">${valorTotal.toLocaleString('es-CO', { maximumFractionDigits: 0 })}</p>
        </div>
      </div>

      {/* Alertas de reabastecimiento */}
      {alertas.length > 0 && (
        <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
          <h2 className="font-semibold text-amber-300 mb-2">⚠️ Reabastecimiento ({alertas.length})</h2>
          <ul className="text-sm text-amber-200 space-y-1">
            {alertas.map((a) => (
              <li key={a.id}>{a.producto?.nombre} en {a.bodega?.nombre}: {Number(a.cantidad).toLocaleString('es-CO')} (mín. {Number(a.stock_minimo).toLocaleString('es-CO')})</li>
            ))}
          </ul>
        </div>
      )}

      {/* Registrar movimiento */}
      <form onSubmit={registrar} className="rounded-xl border border-slate-800 bg-slate-800/50 p-5 grid sm:grid-cols-3 gap-3">
        <h2 className="sm:col-span-3 font-semibold">Registrar movimiento (Kardex)</h2>
        {error && <div className="sm:col-span-3 text-red-300 text-sm">{error}</div>}
        {ok && <div className="sm:col-span-3 text-emerald-300 text-sm">{ok}</div>}
        {/* Fila 1: tipo, producto, cantidad */}
        <select value={mov.tipo} onChange={set('tipo')} className="input">
          <option value="ENTRADA">Entrada</option>
          <option value="SALIDA">Salida</option>
          <option value="TRASLADO">Traslado</option>
        </select>
        <select required value={mov.producto_id} onChange={elegirProducto} className="input">
          <option value="">Producto…</option>
          {productos.map((p) => <option key={p.id} value={p.id}>{p.sku} · {p.nombre}</option>)}
        </select>
        <input
          type="text"
          inputMode="decimal"
          required
          placeholder="Cantidad"
          value={mov.cantidad}
          onChange={(e) => setMov({ ...mov, cantidad: formatearEnVivo(e.target.value) })}
          className="input"
        />

        {/* Fila 2: unidad, bodega(s), costo unitario */}
        <select required value={unidadMov} onChange={elegirUnidad} className="input" disabled={!mov.producto_id}>
          <option value="">{mov.producto_id ? 'Unidad…' : 'Elige un producto primero'}</option>
          {opcionesUnidad.map((u) => <option key={u} value={u}>{u}</option>)}
          <option value="PERSONALIZADO">Personalizado…</option>
        </select>
        {usaOrigen && (
          <select required value={mov.bodega_origen_id} onChange={set('bodega_origen_id')} className="input">
            <option value="">Bodega origen…</option>
            {bodegas.map((b) => <option key={b.id} value={b.id}>{b.nombre}</option>)}
          </select>
        )}
        {usaDestino && (
          <select required value={mov.bodega_destino_id} onChange={set('bodega_destino_id')} className="input">
            <option value="">Bodega destino…</option>
            {bodegas.map((b) => <option key={b.id} value={b.id}>{b.nombre}</option>)}
          </select>
        )}
        {mov.tipo === 'ENTRADA' && (
          <input
            type="text"
            inputMode="decimal"
            placeholder={unidadElegida ? `Costo por ${unidadElegida} (ej: 120.000)` : 'Costo unitario (ej: 120.000)'}
            title="Cuánto costó UNA de la unidad elegida arriba (ej. una CAJA completa, no una unidad suelta)."
            value={mov.costo_unitario}
            onChange={(e) => setMov({ ...mov, costo_unitario: formatearEnVivo(e.target.value) })}
            className="input"
          />
        )}
        {mov.tipo === 'ENTRADA' && !esUnidadBase && factorEfectivo && mov.costo_unitario && (
          <p className="sm:col-span-3 -mt-2 text-xs text-slate-400">
            = {(aNumero(mov.costo_unitario) / factorEfectivo).toLocaleString('es-CO')} por {unidadBase}
          </p>
        )}

        {unidadMov === 'PERSONALIZADO' && (
          <input
            required
            placeholder="Nombre de la unidad (ej: ROLLO, BULTO…)"
            value={unidadPersonalizada}
            onChange={(e) => setUnidadPersonalizada(e.target.value.toUpperCase().slice(0, 20))}
            maxLength={20}
            className="input"
          />
        )}
        {necesitaFactorManual && (
          <input
            type="text"
            inputMode="decimal"
            required
            placeholder={`¿Cuántas ${unidadBase} trae 1 ${unidadElegida}?`}
            value={factorManual}
            onChange={(e) => setFactorManual(formatearEnVivo(e.target.value))}
            className="input"
          />
        )}
        {factorEfectivo && factorEfectivo !== 1 && mov.cantidad && (
          <p className="sm:col-span-3 -mt-2 text-xs text-slate-400">
            = {(aNumero(mov.cantidad) * factorEfectivo).toLocaleString('es-CO')} {unidadBase}
            {' '}(1 {unidadElegida} = {factorEfectivo.toLocaleString('es-CO')} {unidadBase})
          </p>
        )}

        <div className="sm:col-span-3">
          <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Registrar</button>
        </div>
      </form>

      {/* Stock por bodega */}
      <div>
        <div className="flex items-center justify-between gap-3 mb-3">
          <h2 className="font-semibold">Stock por bodega</h2>
          <input
            type="text"
            placeholder="Buscar por producto, SKU o código de barras…"
            value={buscar}
            onChange={(e) => setBuscar(e.target.value)}
            className="input w-64 max-w-full"
          />
        </div>
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-slate-800 text-slate-300">
              <tr><th className="text-left p-3">Producto</th><th className="text-left p-3">Bodega</th><th className="p-3 w-40">Nivel</th><th className="text-right p-3">Cantidad</th><th className="text-right p-3">Mínimo</th><th className="text-right p-3">Costo prom.</th><th className="p-3"></th></tr>
            </thead>
            <tbody>
              {stock.map((s) => {
                const n = nivel(s)
                const enEdicion = editando?.id === s.id
                return (
                  <tr key={s.id} className={`border-t border-slate-800 ${FILA[n.tono]}`}>
                    <td className="p-3 font-medium">
                      {s.producto?.nombre ?? <span className="italic text-slate-500">(producto eliminado)</span>}
                    </td>
                    <td className="p-3 text-slate-400">{s.bodega?.nombre}</td>
                    <td className="p-3">
                      <div className="flex items-center gap-2">
                        <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-800">
                          <div className={`h-full rounded-full ${BARRA[n.tono]}`} style={{ width: `${n.pct}%` }} />
                        </div>
                        <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${BADGE[n.tono]}`}>{n.label}</span>
                      </div>
                    </td>
                    <td className={`p-3 text-right font-semibold ${n.tono === 'red' ? 'text-red-300' : n.tono === 'amber' ? 'text-amber-300' : ''}`}>
                      {enEdicion ? (
                        <div className="flex items-center justify-end gap-1">
                          <input
                            type="text"
                            inputMode="decimal"
                            autoFocus
                            value={editando.cantidad}
                            onChange={(e) => setEditando({ ...editando, cantidad: formatearEnVivo(e.target.value) })}
                            className="input w-24 text-right py-1"
                          />
                          <input
                            type="text"
                            inputMode="decimal"
                            placeholder="Costo (si sube)"
                            title="Costo unitario, solo si la cantidad sube (para recalcular el costo promedio). Si la bajas, no hace falta."
                            value={editando.costo_unitario}
                            onChange={(e) => setEditando({ ...editando, costo_unitario: formatearEnVivo(e.target.value) })}
                            className="input w-28 text-right py-1"
                          />
                        </div>
                      ) : (
                        Number(s.cantidad).toLocaleString('es-CO')
                      )}
                    </td>
                    <td className="p-3 text-right text-slate-400">{Number(s.stock_minimo).toLocaleString('es-CO')}</td>
                    <td className="p-3 text-right text-slate-400">${Number(s.costo_promedio).toLocaleString()}</td>
                    <td className="p-3 text-right whitespace-nowrap">
                      {enEdicion ? (
                        <>
                          <button onClick={guardarEdicion} className="text-emerald-400 hover:underline text-xs mr-3">Guardar</button>
                          <button onClick={() => setEditando(null)} className="text-slate-400 hover:underline text-xs">Cancelar</button>
                        </>
                      ) : (
                        <>
                          <button onClick={() => iniciarEdicion(s)} className="text-emerald-400 hover:underline text-xs mr-3">Editar</button>
                          <button onClick={() => eliminarStock(s)} className="text-red-400 hover:underline text-xs">Eliminar</button>
                        </>
                      )}
                    </td>
                  </tr>
                )
              })}
              {stock.length === 0 && <tr><td colSpan="7" className="p-6 text-center text-slate-500">Sin movimientos de stock aún.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      {/* Movimientos recientes (Kardex) */}
      <div>
        <div className="flex items-center justify-between gap-3 mb-3 flex-wrap">
          <h2 className="font-semibold">Movimientos recientes</h2>
          <div className="flex gap-2">
            <select value={filtroTipo} onChange={(e) => setFiltroTipo(e.target.value)} className="input w-40">
              <option value="">Todos los tipos</option>
              <option value="ENTRADA">Entrada</option>
              <option value="SALIDA">Salida</option>
              <option value="TRASLADO">Traslado</option>
            </select>
            <select value={filtroBodega} onChange={(e) => setFiltroBodega(e.target.value)} className="input w-44">
              <option value="">Todas las bodegas</option>
              {bodegas.map((b) => <option key={b.id} value={b.id}>{b.nombre}</option>)}
            </select>
          </div>
        </div>
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-slate-800 text-slate-300">
              <tr>
                <th className="text-left p-3">Fecha</th>
                <th className="text-left p-3">Tipo</th>
                <th className="text-left p-3">Producto</th>
                <th className="text-right p-3">Cantidad</th>
                <th className="text-left p-3">Bodega</th>
                <th className="text-left p-3">Usuario</th>
                <th className="p-3"></th>
              </tr>
            </thead>
            <tbody>
              {movimientos.map((m) => (
                <tr key={m.id} className="border-t border-slate-800">
                  <td className="p-3 text-slate-400">{new Date(m.created_at).toLocaleString('es-CO')}</td>
                  <td className="p-3">{m.tipo}{m.motivo ? <span className="text-slate-500"> · {m.motivo}</span> : null}</td>
                  <td className="p-3 font-medium">{m.producto?.nombre}</td>
                  <td className="p-3 text-right">{Number(m.cantidad).toLocaleString('es-CO')}</td>
                  <td className="p-3 text-slate-400">
                    {m.bodega_origen && m.bodega_destino
                      ? `${m.bodega_origen.nombre} → ${m.bodega_destino.nombre}`
                      : (m.bodega_origen ?? m.bodega_destino)?.nombre}
                  </td>
                  <td className="p-3 text-slate-400">{m.usuario?.name ?? '—'}</td>
                  <td className="p-3 text-right">
                    {m.referencia_tipo ? (
                      <span className="text-xs text-slate-600" title={`Generado automáticamente por ${m.referencia_tipo}`}>Automático</span>
                    ) : (
                      <button onClick={() => eliminarMovimiento(m)} className="text-red-400 hover:underline text-xs">Eliminar</button>
                    )}
                  </td>
                </tr>
              ))}
              {movimientos.length === 0 && <tr><td colSpan="7" className="p-6 text-center text-slate-500">Sin movimientos registrados aún.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
