import { useEffect, useState } from 'react'
import { api } from '../api/client'
import GestorDocumentos from '../components/GestorDocumentos'
import { useFeatures } from '../context/FeaturesContext'

const VACIO = {
  razon_social: '', tipo_documento: 'NIT', numero_documento: '',
  digito_verificacion: '', email: '', telefono: '', direccion: '', terminos_pago: '',
}

export default function Proveedores() {
  const { activa, visible } = useFeatures()
  const [lista, setLista] = useState([])
  const [form, setForm] = useState(VACIO)
  const [editId, setEditId] = useState(null)
  const [error, setError] = useState('')
  const [abierto, setAbierto] = useState(false)
  const [docsDe, setDocsDe] = useState(null)
  const [leyendo, setLeyendo] = useState(false)

  async function cargar() {
    const data = await api('/proveedores')
    setLista(data.data ?? data)
  }
  useEffect(() => { cargar() }, [])

  function editar(p) {
    setForm({ ...VACIO, ...p })
    setEditId(p.id)
    setAbierto(true)
  }

  async function guardar(e) {
    e.preventDefault()
    setError('')
    try {
      // El dígito de verificación solo aplica al NIT.
      const body = { ...form, digito_verificacion: form.tipo_documento === 'NIT' ? (form.digito_verificacion || null) : null }
      if (editId) await api(`/proveedores/${editId}`, { method: 'PUT', body })
      else await api('/proveedores', { method: 'POST', body })
      setForm(VACIO); setEditId(null); setAbierto(false)
      cargar()
    } catch (err) {
      setError(err.message)
    }
  }

  async function eliminar(id) {
    if (!confirm('¿Eliminar proveedor?')) return
    await api(`/proveedores/${id}`, { method: 'DELETE' })
    cargar()
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  // Lectura inteligente: sube un documento y autocompleta los campos con la API de Claude.
  async function autocompletar(e) {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setLeyendo(true); setError('')
    try {
      const fd = new FormData(); fd.append('archivo', file)
      const r = await api('/proveedores/extraer', { method: 'POST', body: fd, isForm: true })
      const c = r.campos || {}
      setForm((f) => ({
        ...f,
        razon_social: c.razon_social || f.razon_social,
        tipo_documento: c.tipo_documento || f.tipo_documento,
        numero_documento: c.numero_documento || f.numero_documento,
        digito_verificacion: c.digito_verificacion || f.digito_verificacion,
        email: c.email || f.email,
        telefono: c.telefono || f.telefono,
        direccion: c.direccion || f.direccion,
      }))
    } catch (err) {
      setError(err.message)
    } finally {
      setLeyendo(false)
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold">Proveedores</h1>
        <button onClick={() => { setForm(VACIO); setEditId(null); setAbierto(true) }}
          className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nuevo</button>
      </div>

      {abierto && (
        <form onSubmit={guardar} className="mb-6 rounded-xl border border-slate-800 bg-slate-800/50 p-5 grid sm:grid-cols-2 gap-3">
          {error && <div className="sm:col-span-2 text-red-300 text-sm">{error}</div>}

          {/* Carga inteligente: lee el documento y autocompleta los campos (solo planes con OCR) */}
          {activa('ocr') && (
            <label className="sm:col-span-2 flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-dashed border-sky-700/60 bg-sky-500/5 px-4 py-3 hover:border-sky-500">
              <div className="text-sm">
                <p className="font-medium text-sky-300">✨ Cargar documento para autocompletar</p>
                <p className="text-xs text-slate-400">RUT, cámara de comercio o factura (PDF/JPG/PNG). Lee y rellena los campos automáticamente.</p>
              </div>
              <span className="shrink-0 rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-semibold">{leyendo ? 'Leyendo…' : 'Subir'}</span>
              <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" onChange={autocompletar} disabled={leyendo} className="hidden" />
            </label>
          )}
          <input required placeholder="Nombre o razón social" value={form.razon_social} onChange={set('razon_social')} className="input" />
          <select value={form.tipo_documento} onChange={set('tipo_documento')} className="input">
            <option>NIT</option><option>CC</option><option>CE</option>
          </select>
          {/* Con NIT, el DV va en su propio campo etiquetado (máx. 1 dígito, ej: 900123456-7) */}
          {form.tipo_documento === 'NIT' ? (
            <div className="flex gap-2">
              <input required placeholder="NIT (sin dígito de verificación)" value={form.numero_documento} onChange={set('numero_documento')} className="input flex-1" />
              <label className="block w-24 shrink-0">
                <span className="block text-[10px] font-semibold uppercase tracking-wide text-slate-400 mb-1">Dígito verif. (DV)</span>
                <input placeholder="Ej: 7" maxLength={2} inputMode="numeric"
                  value={form.digito_verificacion ?? ''}
                  onChange={(e) => setForm({ ...form, digito_verificacion: e.target.value.replace(/\D/g, '').slice(0, 2) })}
                  className="input text-center" />
              </label>
            </div>
          ) : (
            <input required placeholder="Número de documento" value={form.numero_documento} onChange={set('numero_documento')} className="input" />
          )}
          <input placeholder="correo@empresa.com" value={form.email ?? ''} onChange={set('email')} className="input" />
          <input placeholder="Ejemplo: 3001234567" value={form.telefono ?? ''} onChange={set('telefono')} className="input" />
          <input placeholder="Dirección del proveedor" value={form.direccion ?? ''} onChange={set('direccion')} className="input" />
          <input placeholder="Ej: 30 días, contado…" value={form.terminos_pago ?? ''} onChange={set('terminos_pago')} className="input" />
          <div className="sm:col-span-2 flex gap-2">
            <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar</button>
            <button type="button" onClick={() => setAbierto(false)} className="rounded-lg bg-slate-700 px-4 py-2 text-sm">Cancelar</button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-800">
        <table className="w-full text-sm">
          <thead className="bg-slate-800 text-slate-300">
            <tr><th className="text-left p-3">Razón social</th><th className="text-left p-3">Documento</th><th className="text-left p-3">Contacto</th><th className="p-3"></th></tr>
          </thead>
          <tbody>
            {lista.map((p) => (
              <tr key={p.id} className="border-t border-slate-800">
                <td className="p-3">{p.razon_social}</td>
                <td className="p-3 text-slate-400">{p.tipo_documento} {p.numero_documento}{p.digito_verificacion ? `-${p.digito_verificacion}` : ''}</td>
                <td className="p-3 text-slate-400">{p.email || p.telefono || '—'}</td>
                <td className="p-3 text-right whitespace-nowrap">
                  {visible('documental') && <button onClick={() => setDocsDe(p)} className="text-sky-400 hover:underline mr-3">Documentos</button>}
                  <button onClick={() => editar(p)} className="text-emerald-400 hover:underline mr-3">Editar</button>
                  <button onClick={() => eliminar(p.id)} className="text-red-400 hover:underline">Eliminar</button>
                </td>
              </tr>
            ))}
            {lista.length === 0 && <tr><td colSpan="4" className="p-6 text-center text-slate-500">Sin proveedores aún.</td></tr>}
          </tbody>
        </table>
      </div>

      {docsDe && (
        <GestorDocumentos tipo="proveedor" id={docsDe.id} titulo={docsDe.razon_social} onClose={() => setDocsDe(null)} />
      )}
    </div>
  )
}
