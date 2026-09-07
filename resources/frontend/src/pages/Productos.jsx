import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { aNumero, formatearEnVivo } from '../utils/numero'

const VACIO = {
  sku: '', codigo_barras: '', nombre: '', descripcion: '', unidad_medida: 'UND',
  unidad_compra: '', unidades_por_compra: '',
  precio_costo: '', precio_venta: '', categoria_id: '', activo: true, disponible: true,
}

const UNIDADES_MEDIDA = ['UND', 'KG', 'LT', 'MT', 'CAJA', 'PAR', 'DOCENA', 'PAQUETE']

export default function Productos() {
  const [lista, setLista] = useState([])
  const [categorias, setCategorias] = useState([])
  const [form, setForm] = useState(VACIO)
  const [imagen, setImagen] = useState(null)
  const [editId, setEditId] = useState(null)
  const [error, setError] = useState('')
  const [abierto, setAbierto] = useState(false)
  const [valorTotalInventario, setValorTotalInventario] = useState(0)
  const [galeria, setGaleria] = useState([]) // fotos adicionales del producto en edición
  const [subiendoFoto, setSubiendoFoto] = useState(false)
  const [unidadCustom, setUnidadCustom] = useState(false)
  const [usarPresentacion, setUsarPresentacion] = useState(false)
  const [buscar, setBuscar] = useState('')
  const [categoriaFiltro, setCategoriaFiltro] = useState('')

  async function cargar(termino = buscar, categoria = categoriaFiltro) {
    const params = new URLSearchParams()
    if (termino) params.set('buscar', termino)
    if (categoria) params.set('categoria_id', categoria)
    const qs = params.toString() ? `?${params.toString()}` : ''
    const data = await api(`/productos${qs}`)
    setLista(data.data ?? data)
    setValorTotalInventario(data.valor_total_inventario ?? 0)
  }
  useEffect(() => {
    cargar()
    api('/categorias').then(setCategorias)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Vuelve a consultar cada vez que cambia el texto de búsqueda o la categoría
  // (con un pequeño debounce para no disparar una petición por tecla).
  useEffect(() => {
    const t = setTimeout(() => cargar(buscar, categoriaFiltro), 300)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [buscar, categoriaFiltro])

  function nuevo() { setForm(VACIO); setImagen(null); setEditId(null); setError(''); setGaleria([]); setUnidadCustom(false); setUsarPresentacion(false); setAbierto(true) }
  async function editar(p) {
    setForm({ ...VACIO, ...p, categoria_id: p.categoria_id ?? '', unidad_compra: p.unidad_compra ?? '', unidades_por_compra: p.unidades_por_compra ?? '' })
    setImagen(null); setEditId(p.id); setError(''); setAbierto(true); setGaleria([])
    setUnidadCustom(!UNIDADES_MEDIDA.includes(String(p.unidad_medida ?? 'UND').toUpperCase()))
    setUsarPresentacion(!!p.unidad_compra)
    try { setGaleria((await api(`/productos/${p.id}`)).galeria ?? []) } catch { /* no bloquea la edición */ }
  }

  async function agregarFotoGaleria(file) {
    if (!file || !editId) return
    setSubiendoFoto(true); setError('')
    try {
      const fd = new FormData()
      fd.append('imagen', file)
      const item = await api(`/productos/${editId}/galeria`, { method: 'POST', body: fd, isForm: true })
      setGaleria((g) => [...g, item])
    } catch (err) {
      setError(err.message || 'No se pudo subir la foto.')
    } finally {
      setSubiendoFoto(false)
    }
  }

  async function quitarFotoGaleria(imagenId) {
    if (!editId) return
    try {
      await api(`/productos/${editId}/galeria/${imagenId}`, { method: 'DELETE' })
      setGaleria((g) => g.filter((f) => f.id !== imagenId))
    } catch (err) { alert(err.message || 'No se pudo quitar la foto.') }
  }

  async function guardar(e) {
    e.preventDefault()
    setError('')
    try {
      const fd = new FormData()
      Object.entries(form).forEach(([k, v]) => {
        // Estos campos no tienen control en este formulario (vienen heredados
        // del producto al editar, ver `editar()`) - no se reenvían para no
        // pisarlos con un valor mal serializado. Un booleano `false` via
        // FormData.append() se vuelve el string "false", que Laravel rechaza
        // (la regla "boolean" solo acepta true/false/0/1/"0"/"1").
        if (['is_service', 'has_commission', 'commission_type', 'commission_value', 'unidad_compra', 'unidades_por_compra'].includes(k)) return
        if (k === 'activo' || k === 'disponible') fd.append(k, v ? '1' : '0')
        // Precios en formato colombiano: "400.000" debe llegar como 400000.
        else if (k === 'precio_costo' || k === 'precio_venta') fd.append(k, aNumero(v))
        else if (v !== null && v !== '') fd.append(k, v)
      })
      if (imagen) fd.append('imagen', imagen)
      // Presentación de compra: si está desactivada se manda vacío para
      // que el backend la borre (limpiar la config previa), en vez de
      // simplemente omitirla y dejar el valor viejo pegado.
      fd.append('unidad_compra', usarPresentacion ? form.unidad_compra : '')
      fd.append('unidades_por_compra', usarPresentacion ? aNumero(form.unidades_por_compra) : '')

      if (editId) await api(`/productos/${editId}/update`, { method: 'POST', body: fd, isForm: true })
      else await api('/productos', { method: 'POST', body: fd, isForm: true })
      setAbierto(false); cargar()
    } catch (err) {
      setError(err.message + (err.errors ? ' ' + JSON.stringify(err.errors) : ''))
    }
  }

  /** Marca agotado/disponible sin abrir el formulario completo (catálogo público del portal). */
  async function toggleDisponible(p) {
    const fd = new FormData()
    fd.append('sku', p.sku)
    if (p.codigo_barras) fd.append('codigo_barras', p.codigo_barras)
    fd.append('nombre', p.nombre)
    if (p.categoria_id) fd.append('categoria_id', p.categoria_id)
    if (p.descripcion) fd.append('descripcion', p.descripcion)
    fd.append('precio_costo', aNumero(p.precio_costo))
    fd.append('precio_venta', aNumero(p.precio_venta))
    fd.append('activo', p.activo ? '1' : '0')
    fd.append('disponible', p.disponible ? '0' : '1')
    await api(`/productos/${p.id}/update`, { method: 'POST', body: fd, isForm: true })
    cargar()
  }

  async function eliminar(id) {
    if (!confirm('¿Eliminar producto?')) return
    await api(`/productos/${id}`, { method: 'DELETE' })
    cargar()
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold">Productos</h1>
          <p className="text-sm text-slate-400 mt-1">
            Valor total del inventario: <span className="text-white font-semibold">${Number(valorTotalInventario).toLocaleString()}</span>
          </p>
        </div>
        <button onClick={nuevo} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nuevo</button>
      </div>

      <div className="flex flex-wrap gap-2 mb-4">
        <input
          type="text"
          placeholder="Buscar por nombre, SKU o código de barras…"
          value={buscar}
          onChange={(e) => setBuscar(e.target.value)}
          className="input flex-1 min-w-[220px]"
        />
        <select value={categoriaFiltro} onChange={(e) => setCategoriaFiltro(e.target.value)} className="input w-48">
          <option value="">Todas las categorías</option>
          {categorias.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
        </select>
      </div>

      {abierto && (
        <form onSubmit={guardar} className="mb-6 rounded-xl border border-slate-800 bg-slate-800/50 p-5 grid sm:grid-cols-2 gap-3">
          {error && <div className="sm:col-span-2 text-red-300 text-sm">{error}</div>}
          <input placeholder="SKU (vacío = se genera automático)" value={form.sku} onChange={set('sku')} className="input" />
          <input placeholder="Código de barras" value={form.codigo_barras ?? ''} onChange={set('codigo_barras')} className="input" />
          <input required placeholder="Nombre" value={form.nombre} onChange={set('nombre')} className="input sm:col-span-2" />
          <select value={form.categoria_id} onChange={set('categoria_id')} className="input">
            <option value="">Sin categoría</option>
            {categorias.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
          </select>
          <div className="flex gap-2">
            <select
              value={unidadCustom ? 'personalizado' : form.unidad_medida}
              onChange={(e) => {
                if (e.target.value === 'personalizado') { setUnidadCustom(true); setForm({ ...form, unidad_medida: '' }) }
                else { setUnidadCustom(false); setForm({ ...form, unidad_medida: e.target.value }) }
              }}
              className="input"
              title="Unidad de medida (se usa también para armar el SKU automático)"
            >
              {UNIDADES_MEDIDA.map((u) => <option key={u} value={u}>{u}</option>)}
              <option value="personalizado">Personalizado…</option>
            </select>
            {unidadCustom && (
              <input
                required
                placeholder="Ej: ROLLO, BULTO, GALÓN…"
                value={form.unidad_medida}
                onChange={(e) => setForm({ ...form, unidad_medida: e.target.value.toUpperCase().slice(0, 20) })}
                maxLength={20}
                className="input"
              />
            )}
          </div>
          <div className="sm:col-span-2 rounded-lg border border-slate-700 p-3">
            <label className="flex items-center gap-2 text-sm text-slate-300">
              <input type="checkbox" checked={usarPresentacion} onChange={(e) => setUsarPresentacion(e.target.checked)} />
              Se compra por caja/paquete (ej: CAJA de 12 {form.unidad_medida || 'UND'})
            </label>
            {usarPresentacion && (
              <div className="mt-2 flex gap-2">
                <input
                  required
                  placeholder="Nombre presentación (ej: CAJA)"
                  value={form.unidad_compra}
                  onChange={(e) => setForm({ ...form, unidad_compra: e.target.value.toUpperCase().slice(0, 20) })}
                  maxLength={20}
                  className="input"
                />
                <input
                  required
                  type="text"
                  inputMode="decimal"
                  placeholder={`Cuántas ${form.unidad_medida || 'UND'} trae`}
                  value={form.unidades_por_compra}
                  onChange={(e) => setForm({ ...form, unidades_por_compra: formatearEnVivo(e.target.value) })}
                  className="input"
                />
              </div>
            )}
          </div>
          <input type="file" accept="image/*" onChange={(e) => setImagen(e.target.files?.[0] ?? null)} className="input" />
          <input type="text" inputMode="decimal" placeholder="Precio costo (ej: 250.000)" value={form.precio_costo}
            onChange={(e) => setForm({ ...form, precio_costo: formatearEnVivo(e.target.value) })} className="input" required />
          <input type="text" inputMode="decimal" placeholder="Precio venta (ej: 400.000)" value={form.precio_venta}
            onChange={(e) => setForm({ ...form, precio_venta: formatearEnVivo(e.target.value) })} className="input" required />
          <textarea placeholder="Descripción" value={form.descripcion ?? ''} onChange={set('descripcion')} className="input sm:col-span-2" />
          {editId && (
            <div className="sm:col-span-2">
              <p className="text-sm text-slate-300 mb-1">Fotos adicionales del producto</p>
              <div className="flex flex-wrap gap-2">
                {galeria.map((f) => (
                  <div key={f.id} className="relative group">
                    <img src={f.url} alt="" className="h-16 w-16 rounded-lg object-cover border border-slate-700" />
                    <button type="button" onClick={() => quitarFotoGaleria(f.id)}
                      className="absolute -top-1.5 -right-1.5 h-5 w-5 rounded-full bg-red-600 hover:bg-red-500 text-white text-xs leading-5">✕</button>
                  </div>
                ))}
                <label className="h-16 w-16 rounded-lg border border-dashed border-slate-600 flex items-center justify-center text-slate-500 hover:text-slate-300 hover:border-slate-500 cursor-pointer text-xs text-center">
                  {subiendoFoto ? '…' : '+ Foto'}
                  <input type="file" accept="image/*" className="hidden" disabled={subiendoFoto}
                    onChange={(e) => { const f = e.target.files?.[0]; if (f) agregarFotoGaleria(f); e.target.value = '' }} />
                </label>
              </div>
            </div>
          )}
          <label className="sm:col-span-2 flex items-center gap-2 text-sm text-slate-300">
            <input type="checkbox" checked={form.disponible} onChange={(e) => setForm({ ...form, disponible: e.target.checked })} />
            Disponible en el catálogo público (desmarca si está agotado)
          </label>
          <div className="sm:col-span-2 flex gap-2">
            <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar</button>
            <button type="button" onClick={() => setAbierto(false)} className="rounded-lg bg-slate-700 px-4 py-2 text-sm">Cancelar</button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-800">
        <table className="w-full text-sm">
          <thead className="bg-slate-800 text-slate-300">
            <tr><th className="p-3"></th><th className="text-left p-3">SKU</th><th className="text-left p-3">Nombre</th><th className="text-right p-3">Costo</th><th className="text-right p-3">Venta</th><th className="text-right p-3">Stock</th><th className="text-right p-3">Salidas</th><th className="text-right p-3">Valor inv.</th><th className="text-center p-3">Catálogo</th><th className="p-3"></th></tr>
          </thead>
          <tbody>
            {lista.map((p) => (
              <tr key={p.id} className="border-t border-slate-800">
                <td className="p-2">
                  <div className="relative h-10 w-10 rounded bg-slate-700 overflow-hidden flex items-center justify-center text-slate-500 text-lg">
                    📦
                    {p.imagen_url && (
                      <img
                        src={p.imagen_url}
                        alt=""
                        className="absolute inset-0 h-full w-full object-cover"
                        onError={(e) => { e.currentTarget.style.display = 'none' }}
                      />
                    )}
                  </div>
                </td>
                <td className="p-3 text-slate-400">{p.sku}</td>
                <td className="p-3">{p.nombre}</td>
                <td className="p-3 text-right text-slate-400">${Number(p.precio_costo).toLocaleString()}</td>
                <td className="p-3 text-right">${Number(p.precio_venta).toLocaleString()}</td>
                <td className="p-3 text-right">{Number(p.stock_total ?? 0)}</td>
                <td className="p-3 text-right text-slate-400">{Number(p.salidas ?? 0)}</td>
                <td className="p-3 text-right text-slate-400">${Number(p.valor_inventario ?? 0).toLocaleString()}</td>
                <td className="p-3 text-center">
                  <button onClick={() => toggleDisponible(p)}
                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${p.disponible ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400'}`}>
                    {p.disponible ? 'Disponible' : 'Agotado'}
                  </button>
                </td>
                <td className="p-3 text-right whitespace-nowrap">
                  <button onClick={() => editar(p)} className="text-emerald-400 hover:underline mr-3">Editar</button>
                  <button onClick={() => eliminar(p.id)} className="text-red-400 hover:underline">Eliminar</button>
                </td>
              </tr>
            ))}
            {lista.length === 0 && <tr><td colSpan="10" className="p-6 text-center text-slate-500">Sin productos aún.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
