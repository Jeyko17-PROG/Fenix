import { useEffect, useState } from 'react'
import { api } from '../api/client'

export default function Bodegas() {
  const [lista, setLista] = useState([])
  const [categorias, setCategorias] = useState([])
  const [nombre, setNombre] = useState('')
  const [direccion, setDireccion] = useState('')
  const [telefono, setTelefono] = useState('')
  const [ciudad, setCiudad] = useState('')
  const [catNombre, setCatNombre] = useState('')
  const [error, setError] = useState('')
  const [asignando, setAsignando] = useState(null) // bodega para asignar servicios, o null

  async function cargar() {
    setLista(await api('/bodegas'))
    setCategorias(await api('/categorias'))
  }
  useEffect(() => { cargar() }, [])

  async function crearBodega(e) {
    e.preventDefault(); setError('')
    try {
      await api('/bodegas', { method: 'POST', body: { nombre, direccion, telefono, ciudad, activo: true } })
      setNombre(''); setDireccion(''); setTelefono(''); setCiudad(''); cargar()
    } catch (err) { setError(err.message) }
  }

  async function crearCategoria(e) {
    e.preventDefault()
    await api('/categorias', { method: 'POST', body: { nombre: catNombre } })
    setCatNombre(''); cargar()
  }

  async function definirPrincipal(b) {
    await api(`/bodegas/${b.id}/principal`, { method: 'POST' }); cargar()
  }
  async function renombrar(b) {
    const nuevo = prompt('Nuevo nombre de la sucursal:', b.nombre)
    if (!nuevo || nuevo === b.nombre) return
    await api(`/bodegas/${b.id}`, { method: 'PUT', body: { nombre: nuevo, direccion: b.direccion, telefono: b.telefono, ciudad: b.ciudad, activo: b.activo } })
    cargar()
  }
  async function eliminar(b) {
    if (!confirm(`¿Eliminar la sucursal "${b.nombre}"?`)) return
    try { await api(`/bodegas/${b.id}`, { method: 'DELETE' }); cargar() }
    catch (err) { alert(err.message || 'No se pudo eliminar.') }
  }

  return (
    <div className="grid md:grid-cols-2 gap-8">
      <div>
        <h1 className="text-2xl font-bold mb-1">Sucursales</h1>
        <p className="text-slate-400 text-sm mb-4">Cada sucursal tiene su propia agenda: dos sucursales pueden tener citas a la misma hora sin chocar entre sí.</p>
        <form onSubmit={crearBodega} className="flex flex-col gap-2 mb-4">
          {error && <div className="text-red-300 text-sm">{error}</div>}
          <input required placeholder="Nombre de la sucursal" value={nombre} onChange={(e) => setNombre(e.target.value)} className="input" />
          <input placeholder="Dirección (opcional)" value={direccion} onChange={(e) => setDireccion(e.target.value)} className="input" />
          <div className="flex gap-2">
            <input placeholder="Teléfono (opcional)" value={telefono} onChange={(e) => setTelefono(e.target.value)} className="input !mt-0" />
            <input placeholder="Ciudad (opcional)" value={ciudad} onChange={(e) => setCiudad(e.target.value)} className="input !mt-0" />
          </div>
          <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold self-start">Agregar sucursal</button>
        </form>
        <ul className="rounded-xl border border-slate-800 divide-y divide-slate-800">
          {lista.map((b) => (
            <li key={b.id} className="p-3 flex items-center justify-between gap-2">
              <div className="min-w-0">
                <span className="font-medium">{b.nombre}</span>
                {b.es_principal && <span className="ml-2 text-xs rounded-full bg-emerald-500/15 text-emerald-400 px-2 py-0.5">Principal</span>}
                {(b.direccion || b.ciudad) && <div className="text-slate-500 text-xs truncate">{[b.direccion, b.ciudad].filter(Boolean).join(' · ')}</div>}
                {b.telefono && <div className="text-slate-500 text-xs truncate">📞 {b.telefono}</div>}
              </div>
              <div className="flex flex-wrap gap-1 shrink-0 justify-end">
                {!b.es_principal && <button onClick={() => definirPrincipal(b)} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Principal</button>}
                <button onClick={() => setAsignando(b)} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">💈 Servicios</button>
                <button onClick={() => renombrar(b)} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Renombrar</button>
                <button onClick={() => eliminar(b)} className="text-xs rounded bg-red-900 hover:bg-red-800 px-2 py-1">Eliminar</button>
              </div>
            </li>
          ))}
          {lista.length === 0 && <li className="p-4 text-slate-500 text-sm">Sin sucursales.</li>}
        </ul>
      </div>

      <div>
        <h1 className="text-2xl font-bold mb-4">Categorías</h1>
        <form onSubmit={crearCategoria} className="flex gap-2 mb-4">
          <input required placeholder="Nueva categoría" value={catNombre} onChange={(e) => setCatNombre(e.target.value)} className="input" />
          <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold whitespace-nowrap">Agregar</button>
        </form>
        <ul className="rounded-xl border border-slate-800 divide-y divide-slate-800">
          {categorias.map((c) => <li key={c.id} className="p-3">{c.nombre}</li>)}
          {categorias.length === 0 && <li className="p-4 text-slate-500 text-sm">Sin categorías.</li>}
        </ul>
      </div>

      {asignando && (
        <AsignarServiciosModal bodega={asignando} onClose={() => setAsignando(null)} />
      )}
    </div>
  )
}

/** Marca qué servicios ofrece una sucursal (vacío = disponible en todas). */
function AsignarServiciosModal({ bodega, onClose }) {
  const [servicios, setServicios] = useState([])
  const [seleccion, setSeleccion] = useState([])
  const [cargando, setCargando] = useState(true)
  const [guardando, setGuardando] = useState(false)

  useEffect(() => {
    Promise.all([api('/servicios'), api(`/bodegas/${bodega.id}/servicios`)])
      .then(([todos, asignados]) => {
        setServicios(todos)
        setSeleccion(asignados.map((s) => s.id))
      })
      .finally(() => setCargando(false))
  }, [bodega.id])

  function toggle(id) {
    setSeleccion((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))
  }

  async function guardar() {
    setGuardando(true)
    try {
      await api(`/bodegas/${bodega.id}/servicios`, { method: 'PUT', body: { servicio_ids: seleccion } })
      onClose()
    } catch (err) { alert(err.message || 'No se pudo guardar.') }
    finally { setGuardando(false) }
  }

  return (
    <div onClick={onClose} className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
      <div onClick={(e) => e.stopPropagation()} className="bg-slate-800 rounded-2xl p-6 max-w-md w-full max-h-[85vh] overflow-y-auto">
        <h2 className="text-lg font-bold mb-1">Servicios de {bodega.nombre}</h2>
        <p className="text-xs text-slate-400 mb-4">
          {seleccion.length === 0 ? 'Sin marcar ninguno: esta sucursal ofrece TODOS los servicios.' : 'Solo los marcados están disponibles en esta sucursal.'}
        </p>
        {cargando ? <p className="text-slate-500 text-sm">Cargando…</p> : (
          <div className="space-y-1.5 mb-4">
            {servicios.map((s) => (
              <label key={s.id} className="flex items-center gap-2 text-sm rounded-lg border border-slate-700 px-3 py-2 cursor-pointer hover:bg-slate-700/40">
                <input type="checkbox" checked={seleccion.includes(s.id)} onChange={() => toggle(s.id)} className="accent-emerald-500" />
                {s.nombre} {s.categoria && <span className="text-xs text-slate-500">· {s.categoria.nombre}</span>}
              </label>
            ))}
            {servicios.length === 0 && <p className="text-slate-500 text-sm">Aún no tienes servicios creados.</p>}
          </div>
        )}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
          <button onClick={guardar} disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
            {guardando ? 'Guardando…' : 'Guardar'}
          </button>
        </div>
      </div>
    </div>
  )
}
