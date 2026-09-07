import { useEffect, useState } from 'react'
import { api } from '../api/client'

const VACIO = { nombre: '', descripcion: '', precio: '', duracion_min: 30, aplica_moto: true, aplica_carro: true, icono: '', activo: true }

export default function PlanesLavado() {
  const [planes, setPlanes] = useState([])
  const [form, setForm] = useState(VACIO)
  const [editando, setEditando] = useState(null) // id del plan en edición, o null para "nuevo"
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)

  async function cargar() {
    try { setPlanes(await api('/planes-lavado')) } catch { /* sesión expirada: client.js redirige al login */ }
  }
  useEffect(() => { cargar() }, [])

  function editar(plan) {
    setEditando(plan.id)
    setForm({ ...VACIO, ...plan, precio: String(plan.precio) })
  }

  function cancelar() {
    setEditando(null)
    setForm(VACIO)
  }

  async function guardar(e) {
    e.preventDefault(); setError(''); setGuardando(true)
    try {
      if (editando) await api(`/planes-lavado/${editando}`, { method: 'PUT', body: form })
      else await api('/planes-lavado', { method: 'POST', body: form })
      cancelar()
      cargar()
    } catch (err) { setError(err.message) } finally { setGuardando(false) }
  }

  async function eliminar(id) {
    if (!confirm('¿Eliminar este plan de lavado?')) return
    await api(`/planes-lavado/${id}`, { method: 'DELETE' })
    cargar()
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })

  return (
    <div>
      <h1 className="text-2xl font-bold mb-2">Planes de Lavado</h1>
      <p className="text-slate-400 text-sm mb-6">Define tus planes (nombre, precio, duración) y si aplican a moto, carro o ambos. Los clientes los eligen al reservar desde el portal público.</p>

      <form onSubmit={guardar} className="rounded-2xl border border-slate-800 bg-slate-800/40 p-4 mb-6 space-y-3">
        <h2 className="font-semibold">{editando ? 'Editar plan' : 'Nuevo plan'}</h2>
        {error && <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}
        <div className="grid gap-3 sm:grid-cols-2">
          <input required placeholder="Nombre (ej. Plan Premium)" value={form.nombre} onChange={set('nombre')} className="input" />
          <input placeholder="Ícono (emoji, opcional)" value={form.icono ?? ''} onChange={set('icono')} className="input" maxLength={10} />
          <input required type="number" min="0" step="0.01" placeholder="Precio" value={form.precio} onChange={set('precio')} className="input" />
          <input required type="number" min="5" placeholder="Duración (min)" value={form.duracion_min} onChange={set('duracion_min')} className="input" />
        </div>
        <textarea placeholder="Descripción (opcional)" value={form.descripcion ?? ''} onChange={set('descripcion')} className="input" rows={2} />
        <div className="flex flex-wrap items-center gap-4 text-sm">
          <label className="flex items-center gap-2"><input type="checkbox" checked={form.aplica_moto} onChange={set('aplica_moto')} /> 🏍️ Aplica a moto</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={form.aplica_carro} onChange={set('aplica_carro')} /> 🚗 Aplica a carro</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={form.activo} onChange={set('activo')} /> Activo</label>
        </div>
        <div className="flex gap-2">
          <button disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
            {guardando ? 'Guardando…' : editando ? 'Guardar cambios' : '+ Crear plan'}
          </button>
          {editando && <button type="button" onClick={cancelar} className="rounded-lg bg-slate-700 px-4 py-2 text-sm">Cancelar</button>}
        </div>
      </form>

      <div className="space-y-2">
        {planes.length === 0 && <p className="text-slate-500 text-sm">Aún no tienes planes de lavado creados.</p>}
        {planes.map((p) => (
          <div key={p.id} className="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-800/50 p-3">
            <div>
              <p className="font-medium">{p.icono ? `${p.icono} ` : ''}{p.nombre} {!p.activo && <span className="text-xs text-slate-500">(inactivo)</span>}</p>
              <p className="text-slate-400 text-sm">${Number(p.precio).toLocaleString()} · {p.duracion_min} min · {p.aplica_moto ? '🏍️' : ''}{p.aplica_carro ? '🚗' : ''}</p>
            </div>
            <div className="flex gap-3">
              <button onClick={() => editar(p)} className="text-sky-400 text-sm hover:underline">Editar</button>
              <button onClick={() => eliminar(p.id)} className="text-red-400 text-sm hover:underline">Eliminar</button>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
