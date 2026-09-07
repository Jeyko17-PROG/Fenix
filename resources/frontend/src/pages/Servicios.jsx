import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'

const VACIO = { nombre: '', descripcion: '', categoria_id: '', icono: '', precio: '', duracion_min: 30, activo: true }
const COP = (n) => '$' + Number(n || 0).toLocaleString('es-CO')

export default function Servicios() {
  const { user } = useAuth()
  // Catálogo visual simplificado (solo imagen, nombre, precio, disponible) para
  // barbería y tatuajes; el resto de negocios conserva el formulario completo.
  const modoSimple = ['barberia', 'tatuajes'].includes(user?.empresa_info?.tipo_negocio?.clave)

  const [servicios, setServicios] = useState([])
  const [categorias, setCategorias] = useState([])
  const [form, setForm] = useState(VACIO)
  const [imagenActual, setImagenActual] = useState(null) // URL ya guardada (al editar)
  const [imagen, setImagen] = useState(null) // archivo nuevo elegido, o null
  const [arrastrando, setArrastrando] = useState(false)
  const [editando, setEditando] = useState(null) // id del servicio en edición, o null para "nuevo"
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [galeria, setGaleria] = useState([]) // fotos de referencia del servicio en edición (ej. cortes de una barbería)
  const [subiendoFoto, setSubiendoFoto] = useState(false)
  const [masOpciones, setMasOpciones] = useState(!modoSimple)
  const [abierto, setAbierto] = useState(!modoSimple) // en modo simple, el formulario arranca oculto tras el botón "+ Nuevo"

  async function cargar() {
    try {
      const [s, c] = await Promise.all([api('/servicios'), api('/categorias')])
      setServicios(s); setCategorias(c)
    } catch { /* sesión expirada: client.js redirige al login */ }
  }
  useEffect(() => { cargar() }, [])

  function nuevo() {
    setEditando(null); setForm(VACIO); setImagenActual(null); setImagen(null); setGaleria([])
    setMasOpciones(!modoSimple); setAbierto(true); setError('')
  }

  function editar(servicio) {
    setEditando(servicio.id)
    setForm({ ...VACIO, ...servicio, categoria_id: servicio.categoria_id ?? '', precio: String(servicio.precio) })
    setImagenActual(servicio.imagen ?? null)
    setImagen(null)
    setGaleria(servicio.galeria ?? [])
    setMasOpciones(!modoSimple)
    setAbierto(true)
  }

  function cancelar() {
    setEditando(null)
    setForm(VACIO)
    setImagenActual(null)
    setImagen(null)
    setGaleria([])
    setAbierto(!modoSimple)
  }

  // Fotos de referencia (varias por servicio, ej. distintos cortes de una barbería
  // que el cliente puede elegir al agendar su cita). Se suben una por una y solo
  // cuando el servicio ya existe (necesitan su id).
  async function agregarFotoGaleria(file) {
    if (!file || !editando) return
    setSubiendoFoto(true); setError('')
    try {
      const fd = new FormData()
      fd.append('imagen', file)
      const item = await api(`/servicios/${editando}/galeria`, { method: 'POST', body: fd, isForm: true })
      setGaleria((g) => [...g, item])
    } catch (err) {
      setError(err.message || 'No se pudo subir la foto.')
    } finally {
      setSubiendoFoto(false)
    }
  }

  async function quitarFotoGaleria(imagenId) {
    if (!editando) return
    try {
      await api(`/servicios/${editando}/galeria/${imagenId}`, { method: 'DELETE' })
      setGaleria((g) => g.filter((f) => f.id !== imagenId))
    } catch (err) { alert(err.message || 'No se pudo quitar la foto.') }
  }

  async function guardar(e) {
    e.preventDefault(); setError(''); setGuardando(true)
    try {
      // multipart/form-data: la foto se sube directo a Cloudinary (no se
      // pega un enlace) — evita el error de URL demasiado larga y permite
      // elegirla desde la galería o la cámara del celular.
      const fd = new FormData()
      Object.entries(form).forEach(([k, v]) => {
        if (k === 'activo') fd.append(k, v ? '1' : '0')
        else if (v !== null && v !== '') fd.append(k, v)
      })
      if (imagen) fd.append('imagen', imagen)

      if (editando) await api(`/servicios/${editando}/update`, { method: 'POST', body: fd, isForm: true })
      else await api('/servicios', { method: 'POST', body: fd, isForm: true })
      cancelar()
      cargar()
    } catch (err) { setError(err.message) } finally { setGuardando(false) }
  }

  async function eliminar(id) {
    if (!confirm('¿Eliminar este servicio?')) return
    await api(`/servicios/${id}`, { method: 'DELETE' })
    cargar()
  }

  async function toggleDisponible(s) {
    const fd = new FormData()
    fd.append('nombre', s.nombre)
    fd.append('precio', s.precio)
    fd.append('duracion_min', s.duracion_min)
    fd.append('activo', s.activo ? '0' : '1')
    await api(`/servicios/${s.id}/update`, { method: 'POST', body: fd, isForm: true })
    cargar()
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })

  function soltarImagen(e) {
    e.preventDefault(); setArrastrando(false)
    const file = e.dataTransfer.files?.[0]
    if (file && file.type.startsWith('image/')) setImagen(file)
  }

  return (
    <div>
      <h1 className="text-2xl font-bold mb-2">Servicios</h1>
      <p className="text-slate-400 text-sm mb-6">
        {modoSimple
          ? 'Tu catálogo visual: foto, nombre, precio y disponibilidad. Tus clientes lo ven así al agendar su cita.'
          : 'Define tus servicios agrupados por categoría (nombre, precio, duración). Los clientes los eligen al reservar desde el portal público y tu equipo los asigna en las órdenes.'}
      </p>

      {modoSimple && !abierto && (
        <button onClick={nuevo} className="mb-6 rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nuevo servicio</button>
      )}

      {abierto && (
        <form onSubmit={guardar} className="rounded-2xl border border-slate-800 bg-slate-800/40 p-4 mb-6 space-y-3">
          <h2 className="font-semibold">{editando ? 'Editar servicio' : 'Nuevo servicio'}</h2>
          {error && <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

          {/* Imagen: dropzone tipo Canva (arrastra y suelta, o toca para elegir) */}
          <label
            onDragOver={(e) => { e.preventDefault(); setArrastrando(true) }}
            onDragLeave={() => setArrastrando(false)}
            onDrop={soltarImagen}
            className={`flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-4 cursor-pointer transition ${
              arrastrando ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-700 hover:border-slate-600'}`}>
            {(imagen || imagenActual) ? (
              <img src={imagen ? URL.createObjectURL(imagen) : imagenActual} alt=""
                className="h-24 w-24 rounded-lg object-cover border border-slate-700" />
            ) : (
              <span className="text-3xl">🖼️</span>
            )}
            <span className="text-sm text-slate-300 text-center">Arrastra una foto aquí, o toca para elegirla / tomarla con la cámara</span>
            <input type="file" accept="image/*" className="hidden" onChange={(e) => setImagen(e.target.files?.[0] ?? null)} />
          </label>

          <div className="grid gap-3 sm:grid-cols-2">
            <input required placeholder="Nombre (ej. Corte Clásico, Manicure)" value={form.nombre} onChange={set('nombre')} className="input sm:col-span-2" />
            <input required type="number" min="0" step="0.01" placeholder="Precio" value={form.precio} onChange={set('precio')} className="input sm:col-span-2" />
          </div>

          <label className="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/40 px-3 py-2">
            <span className="text-sm text-slate-200">{form.activo ? '✅ Disponible' : '⛔ No disponible'}</span>
            <button type="button" onClick={() => setForm({ ...form, activo: !form.activo })}
              className={`relative h-6 w-11 rounded-full transition ${form.activo ? 'bg-emerald-600' : 'bg-slate-600'}`}>
              <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition ${form.activo ? 'left-5' : 'left-0.5'}`} />
            </button>
          </label>

          {modoSimple && (
            <button type="button" onClick={() => setMasOpciones(!masOpciones)} className="text-xs text-sky-400 hover:underline">
              {masOpciones ? '− Ocultar más opciones' : '+ Más opciones (categoría, duración, emoji, descripción)'}
            </button>
          )}

          {masOpciones && (
            <div className="space-y-3 border-t border-slate-800 pt-3">
              <div className="grid gap-3 sm:grid-cols-2">
                <select value={form.categoria_id} onChange={set('categoria_id')} className="input">
                  <option value="">Sin categoría</option>
                  {categorias.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
                </select>
                <input placeholder="Emoji (ej. 💅, opcional)" value={form.icono ?? ''} onChange={set('icono')} className="input" maxLength={10} />
                <input type="number" min="5" placeholder="Duración (min)" value={form.duracion_min} onChange={set('duracion_min')} className="input sm:col-span-2" />
              </div>
              <textarea placeholder="Descripción (opcional)" value={form.descripcion ?? ''} onChange={set('descripcion')} className="input" rows={2} />

              {editando && (
                <div>
                  <p className="text-sm text-slate-300 mb-1">Galería de referencias (ej. cortes de este servicio)</p>
                  <p className="text-xs text-slate-500 mb-2">El cliente podrá elegir una de estas fotos al agendar su cita.</p>
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
            </div>
          )}

          <div className="flex gap-2">
            <button disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
              {guardando ? 'Guardando…' : editando ? 'Guardar cambios' : '+ Crear servicio'}
            </button>
            <button type="button" onClick={cancelar} className="rounded-lg bg-slate-700 px-4 py-2 text-sm">Cancelar</button>
          </div>
        </form>
      )}

      {modoSimple ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
          {servicios.length === 0 && <p className="text-slate-500 text-sm col-span-full">Aún no tienes servicios creados.</p>}
          {servicios.map((s) => (
            <div key={s.id} className="rounded-xl border border-slate-800 bg-slate-800/40 overflow-hidden">
              <div className="relative h-28 w-full bg-slate-900 flex items-center justify-center text-3xl text-slate-600">
                {s.imagen ? <img src={s.imagen} alt="" className="h-full w-full object-cover" onError={(e) => { e.currentTarget.style.display = 'none' }} /> : '🖼️'}
                <button onClick={() => toggleDisponible(s)}
                  className={`absolute top-1.5 right-1.5 rounded-full px-2 py-0.5 text-[10px] font-semibold ${s.activo ? 'bg-emerald-500/90 text-white' : 'bg-red-600/90 text-white'}`}>
                  {s.activo ? 'Disponible' : 'No disponible'}
                </button>
              </div>
              <div className="p-2.5">
                <p className="text-sm font-medium leading-tight truncate">{s.icono ? `${s.icono} ` : ''}{s.nombre}</p>
                <p className="text-emerald-400 text-sm font-semibold mt-0.5">{COP(s.precio)}</p>
                <div className="flex gap-2 mt-2">
                  <button onClick={() => editar(s)} className="text-sky-400 text-xs hover:underline">Editar</button>
                  <button onClick={() => eliminar(s.id)} className="text-red-400 text-xs hover:underline">Eliminar</button>
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="space-y-2">
          {servicios.length === 0 && <p className="text-slate-500 text-sm">Aún no tienes servicios creados.</p>}
          {servicios.map((s) => (
            <div key={s.id} className="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-800/50 p-3">
              <div className="flex items-center gap-3">
                {s.imagen && <img src={s.imagen} alt="" className="h-10 w-10 rounded-lg object-cover" onError={(e) => { e.currentTarget.style.display = 'none' }} />}
                <div>
                  <p className="font-medium">{s.icono ? `${s.icono} ` : ''}{s.nombre} {!s.activo && <span className="text-xs text-slate-500">(inactivo)</span>}</p>
                  <p className="text-slate-400 text-sm">${Number(s.precio).toLocaleString()} · {s.duracion_min} min{s.categoria && ` · ${s.categoria.nombre}`}</p>
                </div>
              </div>
              <div className="flex gap-3">
                <button onClick={() => editar(s)} className="text-sky-400 text-sm hover:underline">Editar</button>
                <button onClick={() => eliminar(s.id)} className="text-red-400 text-sm hover:underline">Eliminar</button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
