import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import GestorDocumentos from '../components/GestorDocumentos'
import { useFeatures } from '../context/FeaturesContext'

const VACIO = {
  nombre_completo: '', tipo_documento: 'CC', numero_documento: '',
  email: '', telefono: '', direccion: '', estado: 'ACTIVO', seguimiento_comercial: '',
}
const ESTADO_COLOR = { ACTIVO: 'bg-emerald-600', POTENCIAL: 'bg-amber-600', INACTIVO: 'bg-slate-600' }

export default function Clientes() {
  const { visible } = useFeatures()
  const [lista, setLista] = useState([])
  const [buscar, setBuscar] = useState('')
  const [form, setForm] = useState(VACIO)
  const [editId, setEditId] = useState(null)
  const [abierto, setAbierto] = useState(false)
  const [ficha, setFicha] = useState(null)
  const [docsDe, setDocsDe] = useState(null)
  const [error, setError] = useState('')

  async function cargar() {
    const data = await api(`/clientes${buscar ? `?buscar=${encodeURIComponent(buscar)}` : ''}`)
    setLista(data.data ?? data)
  }
  useEffect(() => { cargar() }, []) // eslint-disable-line

  function nuevo() { setForm(VACIO); setEditId(null); setError(''); setAbierto(true) }
  function editar(c) { setForm({ ...VACIO, ...c }); setEditId(c.id); setError(''); setAbierto(true) }

  async function verFicha(id) {
    setFicha(await api(`/clientes/${id}`))
  }

  const [limiteAlcanzado, setLimiteAlcanzado] = useState(false)

  async function guardar(e) {
    e.preventDefault(); setError(''); setLimiteAlcanzado(false)
    try {
      if (editId) await api(`/clientes/${editId}`, { method: 'PUT', body: form })
      else await api('/clientes', { method: 'POST', body: form })
      setAbierto(false); cargar()
    } catch (err) {
      setError(err.message)
      if (err.status === 403) setLimiteAlcanzado(true)
    }
  }

  async function eliminar(id) {
    if (!confirm('¿Eliminar cliente?')) return
    await api(`/clientes/${id}`, { method: 'DELETE' })
    cargar()
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  return (
    <div>
      <div className="flex items-center justify-between mb-6 gap-3 flex-wrap">
        <h1 className="text-2xl font-bold">Clientes</h1>
        <div className="flex gap-2">
          <input placeholder="Buscar…" value={buscar} onChange={(e) => setBuscar(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && cargar()} className="input !w-auto" />
          <button onClick={cargar} className="rounded-lg bg-slate-700 px-3 py-2 text-sm">Buscar</button>
          <button onClick={nuevo} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nuevo</button>
        </div>
      </div>

      {abierto && (
        <form onSubmit={guardar} className="mb-6 rounded-xl border border-slate-800 bg-slate-800/50 p-5 grid sm:grid-cols-2 gap-3">
          {error && (
            <div className="sm:col-span-2 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-red-300 text-sm">
              {error}
              {limiteAlcanzado && <Link to="/planes" className="ml-2 underline text-blue-300 font-semibold">Actualizar plan →</Link>}
            </div>
          )}
          <input required placeholder="Nombre completo o razón social" value={form.nombre_completo} onChange={set('nombre_completo')} className="input sm:col-span-2" />
          <select value={form.tipo_documento ?? 'CC'} onChange={set('tipo_documento')} className="input">
            <option>CC</option><option>NIT</option><option>CE</option>
          </select>
          <input placeholder="Número de documento o NIT" value={form.numero_documento ?? ''} onChange={set('numero_documento')} className="input" />
          <input placeholder="correo@empresa.com" value={form.email ?? ''} onChange={set('email')} className="input" />
          <input placeholder="Ejemplo: 3001234567" value={form.telefono ?? ''} onChange={set('telefono')} className="input" />
          <input placeholder="Dirección del cliente" value={form.direccion ?? ''} onChange={set('direccion')} className="input sm:col-span-2" />
          <select value={form.estado ?? 'ACTIVO'} onChange={set('estado')} className="input">
            <option>ACTIVO</option><option>POTENCIAL</option><option>INACTIVO</option>
          </select>
          <textarea placeholder="Seguimiento comercial / notas" value={form.seguimiento_comercial ?? ''} onChange={set('seguimiento_comercial')} className="input sm:col-span-2" />
          <div className="sm:col-span-2 flex gap-2">
            <button className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar</button>
            <button type="button" onClick={() => setAbierto(false)} className="rounded-lg bg-slate-700 px-4 py-2 text-sm">Cancelar</button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-800">
        <table className="w-full text-sm">
          <thead className="bg-slate-800 text-slate-300">
            <tr><th className="text-left p-3">Nombre</th><th className="text-left p-3">Contacto</th><th className="text-left p-3">Estado</th><th className="p-3"></th></tr>
          </thead>
          <tbody>
            {lista.map((c) => (
              <tr key={c.id} className="border-t border-slate-800">
                <td className="p-3">{c.nombre_completo}</td>
                <td className="p-3 text-slate-400">{c.email || c.telefono || '—'}</td>
                <td className="p-3"><span className={`text-xs rounded-full px-2 py-0.5 ${ESTADO_COLOR[c.estado]}`}>{c.estado}</span></td>
                <td className="p-3 text-right whitespace-nowrap">
                  <button onClick={() => verFicha(c.id)} className="text-sky-400 hover:underline mr-3">Ficha</button>
                  {visible('documental') && <button onClick={() => setDocsDe(c)} className="text-sky-400 hover:underline mr-3">Documentos</button>}
                  <button onClick={() => editar(c)} className="text-emerald-400 hover:underline mr-3">Editar</button>
                  <button onClick={() => eliminar(c.id)} className="text-red-400 hover:underline">Eliminar</button>
                </td>
              </tr>
            ))}
            {lista.length === 0 && <tr><td colSpan="4" className="p-6 text-center text-slate-500">Sin clientes aún.</td></tr>}
          </tbody>
        </table>
      </div>

      {/* Ficha del cliente */}
      {ficha && (
        <div onClick={() => setFicha(null)} className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
          <div onClick={(e) => e.stopPropagation()} className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-2xl max-h-[88vh] overflow-y-auto">
            {/* Encabezado */}
            <div className="flex justify-between items-start gap-4 border-b border-slate-800 p-5">
              <div className="flex items-center gap-3">
                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500/15 text-lg font-bold text-emerald-300">
                  {(ficha.nombre_completo || '?').trim().charAt(0).toUpperCase()}
                </div>
                <div>
                  <h2 className="text-xl font-bold">{ficha.nombre_completo}</h2>
                  <span className={`text-xs rounded-full px-2 py-0.5 ${ESTADO_COLOR[ficha.estado]}`}>{ficha.estado}</span>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <button onClick={() => { setDocsDe(ficha); setFicha(null) }} className="rounded-lg bg-sky-600 hover:bg-sky-500 px-3 py-1.5 text-xs font-semibold">📁 Documentos</button>
                <button onClick={() => setFicha(null)} className="text-slate-400 hover:text-white text-2xl leading-none">×</button>
              </div>
            </div>

            <div className="p-5 space-y-5">
              {/* Datos de contacto */}
              <dl className="grid sm:grid-cols-2 gap-x-6 gap-y-1 text-sm text-slate-300">
                <div><span className="text-slate-500">Documento:</span> {ficha.tipo_documento} {ficha.numero_documento || '—'}</div>
                <div><span className="text-slate-500">Email:</span> {ficha.email || '—'}</div>
                <div><span className="text-slate-500">Teléfono:</span> {ficha.telefono || '—'}</div>
                <div><span className="text-slate-500">Dirección:</span> {ficha.direccion || '—'}</div>
                {ficha.seguimiento_comercial && <div className="sm:col-span-2"><span className="text-slate-500">Seguimiento:</span> {ficha.seguimiento_comercial}</div>}
              </dl>

              {/* Resumen visual */}
              <div className="grid grid-cols-3 gap-3">
                <div className="rounded-xl border border-slate-800 bg-slate-800/40 p-3 text-center">
                  <p className="text-2xl font-bold text-sky-400">{ficha.citas?.length ?? 0}</p>
                  <p className="text-[11px] uppercase tracking-wide text-slate-400">Citas</p>
                </div>
                <div className="rounded-xl border border-slate-800 bg-slate-800/40 p-3 text-center">
                  <p className="text-2xl font-bold text-emerald-400">{ficha.facturas?.length ?? 0}</p>
                  <p className="text-[11px] uppercase tracking-wide text-slate-400">Facturas</p>
                </div>
                <div className="rounded-xl border border-slate-800 bg-slate-800/40 p-3 text-center">
                  <p className="text-2xl font-bold text-emerald-400">
                    ${(ficha.facturas ?? []).filter((f) => f.estado !== 'ANULADA').reduce((t, f) => t + Number(f.total || 0), 0).toLocaleString('es-CO', { maximumFractionDigits: 0 })}
                  </p>
                  <p className="text-[11px] uppercase tracking-wide text-slate-400">Facturado</p>
                </div>
              </div>

              {/* Historial: citas y facturas */}
              <div className="grid sm:grid-cols-2 gap-4">
                <div>
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Últimas citas</h3>
                  {(ficha.citas?.length ?? 0) === 0
                    ? <p className="text-sm text-slate-600">Sin citas registradas.</p>
                    : <ul className="space-y-1.5">
                        {ficha.citas.slice(0, 6).map((c) => (
                          <li key={c.id} className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-slate-300">{new Date(c.inicio).toLocaleString('es', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
                            <span className="shrink-0 rounded-full bg-slate-700 px-2 py-0.5 text-[10px]">{c.estado}</span>
                          </li>
                        ))}
                      </ul>}
                </div>
                <div>
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Últimas facturas</h3>
                  {(ficha.facturas?.length ?? 0) === 0
                    ? <p className="text-sm text-slate-600">Sin facturas.</p>
                    : <ul className="space-y-1.5">
                        {ficha.facturas.slice(0, 6).map((f) => (
                          <li key={f.id} className="flex items-center justify-between gap-2 text-sm">
                            <span className="font-mono text-slate-400">{f.numero}</span>
                            <span className="text-emerald-400">${Number(f.total).toLocaleString('es-CO', { maximumFractionDigits: 0 })}</span>
                          </li>
                        ))}
                      </ul>}
                </div>
              </div>

              {/* Notas */}
              {(ficha.notas?.length ?? 0) > 0 && (
                <div>
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Notas</h3>
                  <ul className="space-y-1.5">
                    {ficha.notas.slice(0, 5).map((n) => (
                      <li key={n.id} className="rounded-lg bg-slate-800/40 px-3 py-2 text-sm text-slate-300">{n.titulo || n.contenido}</li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {docsDe && (
        <GestorDocumentos tipo="cliente" id={docsDe.id} titulo={docsDe.nombre_completo} onClose={() => setDocsDe(null)} />
      )}
    </div>
  )
}
