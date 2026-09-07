import { useEffect, useState } from 'react'
import { api } from '../api/client'

const ESTADO_COLOR = {
  ACTIVO: 'bg-emerald-500/15 text-emerald-400',
  SUSPENDIDO: 'bg-amber-500/15 text-amber-400',
  DESACTIVADO: 'bg-red-500/15 text-red-400',
}

/**
 * Administración de licencias (multiempresa): el plan, el límite y el estado
 * se gestionan por EMPRESA — aplican a todo el equipo de esa empresa.
 */
export default function Licencias() {
  const [empresas, setEmpresas] = useState([])
  const [planes, setPlanes] = useState([])
  const [cargando, setCargando] = useState(true)
  const [error, setError] = useState('')

  async function cargar() {
    setCargando(true); setError('')
    try {
      const [e, p] = await Promise.all([api('/admin/empresas'), api('/planes')])
      setEmpresas(e); setPlanes(p)
    } catch (err) {
      setError(err.message || 'No se pudieron cargar las licencias.')
    } finally { setCargando(false) }
  }

  useEffect(() => { cargar() }, [])

  async function accion(fn) {
    try { await fn(); await cargar() }
    catch (err) { alert(err.message || 'Error en la operación.') }
  }

  const cambiarPlan = (e, plan_id) => accion(() => api(`/admin/empresas/${e.id}/plan`, { method: 'POST', body: { plan_id: Number(plan_id) } }))
  const cambiarEstado = (e, estado) => accion(() => api(`/admin/empresas/${e.id}/estado`, { method: 'POST', body: { estado } }))

  async function cambiarLimite(e) {
    const v = prompt(`Límite manual de clientes para ${e.nombre} (vacío = usar el del plan):`, e.limite_manual ?? '')
    if (v === null) return
    accion(() => api(`/admin/empresas/${e.id}/limite`, { method: 'POST', body: { limite_clientes: v === '' ? null : Number(v) } }))
  }

  return (
    <div>
      <h1 className="text-2xl font-bold mb-2">Administración de licencias</h1>
      <p className="text-slate-400 text-sm mb-6">Controla el plan, el límite de clientes y el estado de cada empresa (aplica a todo su equipo).</p>

      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {cargando ? <p className="text-slate-500">Cargando…</p> : (
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-slate-800/60 text-slate-300">
              <tr>
                <th className="text-left p-3">Empresa</th>
                <th className="text-left p-3">Dueño</th>
                <th className="text-left p-3">Plan</th>
                <th className="text-left p-3">Clientes / Límite</th>
                <th className="text-left p-3">Estado</th>
                <th className="text-right p-3">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {empresas.map((e) => (
                <tr key={e.id} className="border-t border-slate-800 hover:bg-slate-800/30">
                  <td className="p-3 font-medium">{e.nombre}</td>
                  <td className="p-3 text-slate-400">{e.dueno?.email ?? '—'}</td>
                  <td className="p-3">
                    <select value={e.plan?.id ?? ''}
                      onChange={(ev) => cambiarPlan(e, ev.target.value)}
                      className="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs">
                      <option value="" disabled>—</option>
                      {planes.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
                    </select>
                  </td>
                  <td className="p-3">
                    <span className={e.limite_clientes && e.clientes_usados >= e.limite_clientes ? 'text-red-400' : 'text-slate-300'}>
                      {e.clientes_usados} / {e.limite_clientes ?? '∞'}
                    </span>
                    <button onClick={() => cambiarLimite(e)} className="ml-2 text-xs text-sky-400 hover:underline">editar</button>
                  </td>
                  <td className="p-3"><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${ESTADO_COLOR[e.estado] ?? ''}`}>{e.estado}</span></td>
                  <td className="p-3">
                    <div className="flex flex-wrap gap-1 justify-end">
                      {e.estado !== 'ACTIVO' && <button onClick={() => cambiarEstado(e, 'ACTIVO')} className="text-xs rounded bg-emerald-700 hover:bg-emerald-600 px-2 py-1">Activar</button>}
                      {e.estado !== 'SUSPENDIDO' && <button onClick={() => cambiarEstado(e, 'SUSPENDIDO')} className="text-xs rounded bg-amber-700 hover:bg-amber-600 px-2 py-1">Suspender</button>}
                      {e.estado !== 'DESACTIVADO' && <button onClick={() => cambiarEstado(e, 'DESACTIVADO')} className="text-xs rounded bg-red-800 hover:bg-red-700 px-2 py-1">Desactivar</button>}
                    </div>
                  </td>
                </tr>
              ))}
              {empresas.length === 0 && <tr><td colSpan="6" className="p-6 text-center text-slate-500">Sin empresas.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
