import { useEffect, useRef, useState } from 'react'
import { api } from '../api/client'

export default function Notas() {
  const [notas, setNotas] = useState([])
  const [activa, setActiva] = useState(null)
  const [guardado, setGuardado] = useState('')
  const timer = useRef(null)

  async function cargar() {
    const data = await api('/notas')
    setNotas(data)
  }
  useEffect(() => { cargar() }, [])

  async function nueva() {
    const n = await api('/notas', { method: 'POST', body: { titulo: 'Nueva nota', contenido: '' } })
    await cargar()
    setActiva(n)
  }

  // Autosave: guarda 800ms después de dejar de escribir.
  function editar(campo, valor) {
    const actualizada = { ...activa, [campo]: valor }
    setActiva(actualizada)
    setGuardado('Guardando…')
    clearTimeout(timer.current)
    timer.current = setTimeout(async () => {
      await api(`/notas/${actualizada.id}`, { method: 'PUT', body: { titulo: actualizada.titulo, contenido: actualizada.contenido } })
      setGuardado('Guardado ✓')
      cargar()
    }, 800)
  }

  async function eliminar(id) {
    if (!confirm('¿Eliminar nota?')) return
    await api(`/notas/${id}`, { method: 'DELETE' })
    if (activa?.id === id) setActiva(null)
    cargar()
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold">Bloc de Notas</h1>
        <button onClick={nueva} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nueva nota</button>
      </div>

      <div className="grid md:grid-cols-3 gap-4">
        <div className="md:col-span-1 space-y-2">
          {notas.map((n) => (
            <div key={n.id} onClick={() => setActiva(n)}
              className={`rounded-lg border p-3 cursor-pointer ${activa?.id === n.id ? 'border-emerald-500 bg-slate-800' : 'border-slate-800 bg-slate-800/40 hover:bg-slate-800'}`}>
              <div className="flex justify-between items-start">
                <p className="font-medium truncate">{n.titulo || 'Sin título'}</p>
                <button onClick={(e) => { e.stopPropagation(); eliminar(n.id) }} className="text-red-400 text-xs hover:underline">×</button>
              </div>
              <p className="text-slate-500 text-xs truncate">{n.contenido || '—'}</p>
              <p className="text-slate-600 text-xs mt-1">{new Date(n.updated_at).toLocaleDateString('es')}</p>
            </div>
          ))}
          {notas.length === 0 && <p className="text-slate-500 text-sm">Sin notas. Crea una.</p>}
        </div>

        <div className="md:col-span-2">
          {activa ? (
            <div className="rounded-xl border border-slate-800 bg-slate-800/40 p-4">
              <div className="flex justify-between items-center mb-2">
                <input value={activa.titulo ?? ''} onChange={(e) => editar('titulo', e.target.value)} placeholder="Título"
                  className="input !bg-transparent !border-0 text-lg font-semibold px-0" />
                <span className="text-xs text-slate-500 whitespace-nowrap">{guardado}</span>
              </div>
              <textarea value={activa.contenido ?? ''} onChange={(e) => editar('contenido', e.target.value)} placeholder="Escribe aquí…"
                rows={14} className="input resize-none" />
            </div>
          ) : (
            <div className="rounded-xl border border-slate-800 bg-slate-800/40 p-10 text-center text-slate-500">
              Selecciona o crea una nota. Se guarda automáticamente.
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
