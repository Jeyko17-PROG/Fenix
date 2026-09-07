import { useEffect, useState } from 'react'
import { api, descargarArchivo } from '../api/client'

export default function Reportes() {
  const [data, setData] = useState(null)
  const [descargando, setDescargando] = useState(false)

  useEffect(() => { api('/reportes/dashboard').then(setData).catch(() => {}) }, [])

  async function exportar() {
    setDescargando(true)
    try { await descargarArchivo('/reportes/inventario/excel', 'inventario.xlsx') }
    finally { setDescargando(false) }
  }

  return (
    <div>
      <h1 className="text-2xl font-bold mb-6">Reportes</h1>

      <div className="rounded-xl border border-slate-800 bg-slate-800/50 p-5 mb-6 flex items-center justify-between">
        <div>
          <h2 className="font-semibold">Inventario en Excel</h2>
          <p className="text-slate-400 text-sm">Descarga el estado del inventario con gráficas nativas incrustadas (barras y pastel).</p>
        </div>
        <button onClick={exportar} disabled={descargando}
          className="rounded-lg bg-sky-600 hover:bg-sky-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold whitespace-nowrap">
          {descargando ? 'Generando…' : '⬇ Exportar Excel'}
        </button>
      </div>

      <div className="grid md:grid-cols-2 gap-6">
        <div className="rounded-xl border border-slate-800 bg-slate-800/50 p-4">
          <h2 className="font-semibold mb-3">Productos más vendidos</h2>
          {(data?.mas_vendidos ?? []).length === 0 ? (
            <p className="text-slate-500 text-sm">Aún no hay ventas facturadas.</p>
          ) : (
            <ul className="space-y-2">
              {data.mas_vendidos.map((m, i) => (
                <li key={i} className="flex justify-between text-sm border-b border-slate-800 pb-1">
                  <span>{m.descripcion}</span><span className="text-emerald-400">{Number(m.total)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="rounded-xl border border-slate-800 bg-slate-800/50 p-4">
          <h2 className="font-semibold mb-3">Productos con mayor rotación (salidas)</h2>
          {(data?.top_rotacion ?? []).length === 0 ? (
            <p className="text-slate-500 text-sm">Sin salidas registradas.</p>
          ) : (
            <ul className="space-y-2">
              {data.top_rotacion.map((m, i) => (
                <li key={i} className="flex justify-between text-sm border-b border-slate-800 pb-1">
                  <span>{m.nombre}</span><span className="text-sky-400">{Number(m.total)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  )
}
