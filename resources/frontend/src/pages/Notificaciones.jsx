import { useEffect, useState } from 'react'
import { api } from '../api/client'

export default function Notificaciones() {
  const [items, setItems] = useState([])
  const [cargando, setCargando] = useState(true)

  async function cargar() {
    setCargando(true)
    try { setItems(await api('/notificaciones')) } finally { setCargando(false) }
  }

  useEffect(() => {
    cargar()
    // Al abrir la página, marca todo como leído.
    api('/notificaciones/marcar-leidas', { method: 'POST' }).catch(() => {})
  }, [])

  return (
    <div className="max-w-2xl mx-auto">
      <h1 className="text-2xl font-bold mb-6">Notificaciones</h1>

      {cargando ? (
        <p className="text-slate-500">Cargando…</p>
      ) : items.length === 0 ? (
        <p className="text-slate-500">No tienes notificaciones.</p>
      ) : (
        <div className="space-y-2">
          {items.map((n) => (
            <div key={n.id} className={`rounded-xl border p-4 ${n.leida ? 'border-slate-800 bg-slate-800/30' : 'border-emerald-700/50 bg-emerald-500/5'}`}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="font-medium">{n.titulo}</p>
                  {n.mensaje && <p className="text-slate-400 text-sm mt-0.5">{n.mensaje}</p>}
                </div>
                {!n.leida && <span className="mt-1 h-2 w-2 rounded-full bg-emerald-400 shrink-0" />}
              </div>
              <p className="text-slate-600 text-xs mt-2">{new Date(n.created_at).toLocaleString('es')}</p>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
