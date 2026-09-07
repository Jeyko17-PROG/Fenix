import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { aNumero } from '../utils/numero'

export default function Compras() {
  const [ordenes, setOrdenes] = useState([])
  const [proveedores, setProveedores] = useState([])
  const [productos, setProductos] = useState([])
  const [bodegas, setBodegas] = useState([])
  const [abierto, setAbierto] = useState(false)
  const [error, setError] = useState('')

  const [cab, setCab] = useState({ proveedor_id: '', bodega_id: '', fecha: new Date().toISOString().slice(0, 10) })
  const [lineas, setLineas] = useState([{ producto_id: '', cantidad: '', precio_unitario: '' }])

  async function cargar() {
    const data = await api('/ordenes-compra')
    setOrdenes(data.data ?? data)
  }
  useEffect(() => {
    cargar()
    api('/proveedores').then((d) => setProveedores(d.data ?? d))
    api('/productos').then((d) => setProductos(d.data ?? d))
    api('/bodegas').then(setBodegas)
  }, [])

  function setLinea(i, k, v) {
    const copia = [...lineas]
    copia[i] = { ...copia[i], [k]: v }
    setLineas(copia)
  }
  const addLinea = () => setLineas([...lineas, { producto_id: '', cantidad: '', precio_unitario: '' }])
  const total = lineas.reduce((s, l) => s + aNumero(l.cantidad) * aNumero(l.precio_unitario), 0)

  async function crear(e) {
    e.preventDefault(); setError('')
    try {
      await api('/ordenes-compra', { method: 'POST', body: {
        ...cab,
        lineas: lineas.map((l) => ({ producto_id: Number(l.producto_id), cantidad: aNumero(l.cantidad), precio_unitario: aNumero(l.precio_unitario) })),
      } })
      setAbierto(false)
      setLineas([{ producto_id: '', cantidad: '', precio_unitario: '' }])
      cargar()
    } catch (err) { setError(err.message) }
  }

  async function recibir(id) {
    if (!confirm('¿Recibir la orden? Esto sumará las cantidades al inventario.')) return
    await api(`/ordenes-compra/${id}/recibir`, { method: 'POST' })
    cargar()
  }

  async function generarPdf(id) {
    const doc = await api(`/ordenes-compra/${id}/pdf`, { method: 'POST' })
    window.open(doc.archivo_url, '_blank')
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold">Órdenes de Compra</h1>
        <button onClick={() => setAbierto(!abierto)} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nueva orden</button>
      </div>

      {abierto && (
        <form onSubmit={crear} className="mb-6 rounded-xl border border-slate-800 bg-slate-800/50 p-5 space-y-3">
          {error && <div className="text-red-300 text-sm">{error}</div>}
          <div className="grid sm:grid-cols-3 gap-3">
            <select required value={cab.proveedor_id} onChange={(e) => setCab({ ...cab, proveedor_id: e.target.value })} className="input">
              <option value="">Proveedor…</option>
              {proveedores.map((p) => <option key={p.id} value={p.id}>{p.razon_social}</option>)}
            </select>
            <select required value={cab.bodega_id} onChange={(e) => setCab({ ...cab, bodega_id: e.target.value })} className="input">
              <option value="">Bodega destino…</option>
              {bodegas.map((b) => <option key={b.id} value={b.id}>{b.nombre}</option>)}
            </select>
            <input type="date" value={cab.fecha} onChange={(e) => setCab({ ...cab, fecha: e.target.value })} className="input" />
          </div>

          {lineas.map((l, i) => (
            <div key={i} className="grid grid-cols-3 gap-3">
              <select required value={l.producto_id} onChange={(e) => setLinea(i, 'producto_id', e.target.value)} className="input">
                <option value="">Producto…</option>
                {productos.map((p) => <option key={p.id} value={p.id}>{p.sku} · {p.nombre}</option>)}
              </select>
              <input type="text" inputMode="decimal" required placeholder="Cantidad" value={l.cantidad} onChange={(e) => setLinea(i, 'cantidad', e.target.value)} className="input" />
              <input type="text" inputMode="decimal" required placeholder="Precio unit. (ej: 120.000)" value={l.precio_unitario} onChange={(e) => setLinea(i, 'precio_unitario', e.target.value)} className="input" />
            </div>
          ))}
          <button type="button" onClick={addLinea} className="text-emerald-400 text-sm hover:underline">+ Agregar línea</button>

          <div className="flex items-center justify-between pt-2">
            <span className="text-lg font-semibold">Total: ${total.toLocaleString()}</span>
            <div className="flex gap-2">
              <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Crear orden</button>
              <button type="button" onClick={() => setAbierto(false)} className="rounded-lg bg-slate-700 px-4 py-2 text-sm">Cancelar</button>
            </div>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-800">
        <table className="w-full text-sm">
          <thead className="bg-slate-800 text-slate-300">
            <tr><th className="text-left p-3">#</th><th className="text-left p-3">Proveedor</th><th className="text-left p-3">Fecha</th><th className="text-right p-3">Total</th><th className="text-left p-3">Estado</th><th className="p-3"></th></tr>
          </thead>
          <tbody>
            {ordenes.map((o) => (
              <tr key={o.id} className="border-t border-slate-800">
                <td className="p-3">{o.id}</td>
                <td className="p-3">{o.proveedor?.razon_social}</td>
                <td className="p-3 text-slate-400">{o.fecha}</td>
                <td className="p-3 text-right">${Number(o.total).toLocaleString()}</td>
                <td className="p-3">
                  <span className={`text-xs rounded-full px-2 py-0.5 ${o.estado === 'RECIBIDA' ? 'bg-emerald-600' : 'bg-slate-600'}`}>{o.estado}</span>
                </td>
                <td className="p-3 text-right whitespace-nowrap">
                  {o.estado !== 'RECIBIDA' && <button onClick={() => recibir(o.id)} className="text-emerald-400 hover:underline mr-3">Recibir</button>}
                  <button onClick={() => generarPdf(o.id)} className="text-sky-400 hover:underline">PDF</button>
                </td>
              </tr>
            ))}
            {ordenes.length === 0 && <tr><td colSpan="6" className="p-6 text-center text-slate-500">Sin órdenes aún.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
