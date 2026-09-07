import { useEffect, useRef, useState } from 'react'
import { api, API_BASE } from '../api/client'

// Categorías sugeridas (el usuario puede escribir una propia).
const CATEGORIAS = ['Cámara de comercio', 'RUT', 'Certificación', 'Contrato', 'Cotización', 'Factura', 'Otro']

const absUrl = (u) => (!u ? '' : u.startsWith('http') ? u : `${API_BASE}${u}`)
const esImagen = (mime = '', nombre = '') => /^image\//.test(mime) || /\.(png|jpe?g|webp|gif)$/i.test(nombre)
const esPdf = (mime = '', nombre = '') => mime === 'application/pdf' || /\.pdf$/i.test(nombre)

function tamano(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}

/**
 * Gestor documental reutilizable (modal) para proveedores y clientes.
 * Props: tipo ('proveedor'|'cliente') · id · titulo · onClose
 */
export default function GestorDocumentos({ tipo, id, titulo, onClose }) {
  const [lista, setLista] = useState([])
  const [pendientes, setPendientes] = useState([])   // { file, nombre, previewUrl, mime }
  const [categoria, setCategoria] = useState('')
  const [drag, setDrag] = useState(false)
  const [subiendo, setSubiendo] = useState(false)
  const [error, setError] = useState('')
  const [preview, setPreview] = useState(null)        // { url, mime, nombre }
  const inputRef = useRef(null)
  const reemplazarRef = useRef(null)

  async function cargar() {
    try {
      const data = await api(`/adjuntos?tipo=${tipo}&id=${id}`)
      setLista(data.data ?? data)
    } catch (e) { setError(e.message) }
  }
  useEffect(() => { cargar() }, []) // eslint-disable-line

  // --- Selección / arrastre de archivos ---
  function agregar(files) {
    const nuevos = Array.from(files).map((file) => ({
      file, nombre: file.name, mime: file.type,
      previewUrl: URL.createObjectURL(file),
    }))
    setPendientes((p) => [...p, ...nuevos])
  }
  function onDrop(e) {
    e.preventDefault(); setDrag(false)
    if (e.dataTransfer.files?.length) agregar(e.dataTransfer.files)
  }
  function quitarPendiente(i) {
    setPendientes((p) => p.filter((_, j) => j !== i))
  }

  async function subirTodo() {
    if (!pendientes.length) return
    setSubiendo(true); setError('')
    try {
      for (const p of pendientes) {
        const fd = new FormData()
        fd.append('tipo', tipo); fd.append('id', id)
        if (categoria) fd.append('categoria', categoria)
        fd.append('archivo', p.file)
        await api('/adjuntos', { method: 'POST', body: fd, isForm: true })
      }
      pendientes.forEach((p) => URL.revokeObjectURL(p.previewUrl))
      setPendientes([]); setCategoria('')
      cargar()
    } catch (e) { setError(e.message) } finally { setSubiendo(false) }
  }

  async function eliminar(adj) {
    if (!confirm(`¿Eliminar "${adj.nombre}"?`)) return
    await api(`/adjuntos/${adj.id}`, { method: 'DELETE' })
    cargar()
  }

  function pedirReemplazo(adj) {
    reemplazarRef.current.dataset.id = adj.id
    reemplazarRef.current.click()
  }
  async function reemplazar(e) {
    const file = e.target.files?.[0]
    const adjId = e.target.dataset.id
    e.target.value = ''
    if (!file) return
    const fd = new FormData(); fd.append('archivo', file)
    await api(`/adjuntos/${adjId}/reemplazar`, { method: 'POST', body: fd, isForm: true })
    cargar()
  }

  function descargar(adj) {
    const a = document.createElement('a')
    a.href = absUrl(adj.url); a.download = adj.nombre; a.target = '_blank'
    document.body.appendChild(a); a.click(); a.remove()
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
      <div className="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        {/* Header */}
        <div className="flex items-center justify-between border-b border-slate-800 px-6 py-4">
          <div>
            <h2 className="text-lg font-semibold">Documentos</h2>
            <p className="text-sm text-slate-400">{titulo}</p>
          </div>
          <button onClick={onClose} className="text-slate-400 hover:text-white text-xl">✕</button>
        </div>

        <div className="overflow-y-auto px-6 py-5 space-y-5">
          {error && <div className="rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-300">{error}</div>}

          {/* Zona de carga (drag & drop) */}
          <div>
            <div className="mb-2 flex flex-wrap items-center gap-2">
              <input list="categorias-doc" value={categoria} onChange={(e) => setCategoria(e.target.value)}
                placeholder="Categoría (opcional)" className="input max-w-xs" />
              <datalist id="categorias-doc">
                {CATEGORIAS.map((c) => <option key={c} value={c} />)}
              </datalist>
            </div>

            <div
              onDragOver={(e) => { e.preventDefault(); setDrag(true) }}
              onDragLeave={() => setDrag(false)}
              onDrop={onDrop}
              onClick={() => inputRef.current.click()}
              className={`flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-8 text-center transition ${drag ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-700 hover:border-slate-600'}`}
            >
              <span className="text-3xl">📎</span>
              <p className="mt-2 text-sm text-slate-300">Arrastra archivos aquí o haz clic para seleccionar</p>
              <p className="text-xs text-slate-500">PDF, Word, Excel, JPG, PNG · máx. 10 MB</p>
              <input ref={inputRef} type="file" multiple
                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp"
                onChange={(e) => { agregar(e.target.files); e.target.value = '' }} className="hidden" />
            </div>
          </div>

          {/* Pendientes de subir (con vista previa antes de guardar) */}
          {pendientes.length > 0 && (
            <div className="rounded-xl border border-slate-800 bg-slate-800/30 p-3">
              <div className="mb-2 flex items-center justify-between">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">Por subir ({pendientes.length})</span>
                <button onClick={subirTodo} disabled={subiendo} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-3 py-1.5 text-sm font-semibold disabled:opacity-50">
                  {subiendo ? 'Subiendo…' : 'Subir todo'}
                </button>
              </div>
              <ul className="space-y-2">
                {pendientes.map((p, i) => (
                  <li key={i} className="flex items-center gap-3 rounded-lg bg-slate-800/50 p-2">
                    {esImagen(p.mime, p.nombre)
                      ? <img src={p.previewUrl} alt="" className="h-10 w-10 rounded object-cover" />
                      : <span className="flex h-10 w-10 items-center justify-center rounded bg-slate-700 text-lg">{esPdf(p.mime, p.nombre) ? '📄' : '🗎'}</span>}
                    <span className="flex-1 truncate text-sm">{p.nombre}</span>
                    <button onClick={() => setPreview({ url: p.previewUrl, mime: p.mime, nombre: p.nombre })} className="text-sky-400 hover:underline text-sm">Ver</button>
                    <button onClick={() => quitarPendiente(i)} className="text-red-400 hover:text-red-300">✕</button>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Documentos guardados */}
          <div>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Guardados ({lista.length})</h3>
            {lista.length === 0
              ? <p className="rounded-lg border border-slate-800 px-4 py-6 text-center text-sm text-slate-500">Aún no hay documentos.</p>
              : (
                <ul className="space-y-2">
                  {lista.map((d) => (
                    <li key={d.id} className="flex items-center gap-3 rounded-lg border border-slate-800 bg-slate-800/30 p-3">
                      <span className="flex h-10 w-10 items-center justify-center rounded bg-slate-700 text-lg">
                        {esImagen(d.tipo_mime, d.nombre) ? '🖼️' : esPdf(d.tipo_mime, d.nombre) ? '📄' : '🗎'}
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{d.nombre}</p>
                        <p className="text-xs text-slate-500">
                          {d.categoria && <span className="mr-2 rounded-full bg-slate-700 px-2 py-0.5">{d.categoria}</span>}
                          {tamano(d.tamano_bytes)}
                        </p>
                      </div>
                      <div className="flex shrink-0 gap-3 text-sm">
                        {(esImagen(d.tipo_mime, d.nombre) || esPdf(d.tipo_mime, d.nombre)) &&
                          <button onClick={() => setPreview({ url: absUrl(d.url), mime: d.tipo_mime, nombre: d.nombre })} className="text-sky-400 hover:underline">Ver</button>}
                        <button onClick={() => descargar(d)} className="text-emerald-400 hover:underline">Descargar</button>
                        <button onClick={() => pedirReemplazo(d)} className="text-amber-400 hover:underline">Reemplazar</button>
                        <button onClick={() => eliminar(d)} className="text-red-400 hover:underline">Eliminar</button>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
          </div>
        </div>

        <input ref={reemplazarRef} type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" onChange={reemplazar} className="hidden" />
      </div>

      {/* Vista previa */}
      {preview && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-4" onClick={() => setPreview(null)}>
          <div className="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-slate-900" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between border-b border-slate-800 px-4 py-2">
              <span className="truncate text-sm">{preview.nombre}</span>
              <button onClick={() => setPreview(null)} className="text-slate-400 hover:text-white">✕</button>
            </div>
            <div className="flex-1 overflow-auto bg-slate-950 p-2">
              {esImagen(preview.mime, preview.nombre)
                ? <img src={preview.url} alt={preview.nombre} className="mx-auto max-h-[75vh]" />
                : esPdf(preview.mime, preview.nombre)
                  ? <iframe src={preview.url} title={preview.nombre} className="h-[75vh] w-full" />
                  : <p className="p-8 text-center text-slate-400">Sin vista previa. Usa “Descargar”.</p>}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
