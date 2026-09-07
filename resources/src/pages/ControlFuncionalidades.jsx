import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from '../api/client'

const ESTADO_COLOR = {
  ACTIVADA: 'text-emerald-400',
  RESTRINGIDA: 'text-amber-400',
  DESACTIVADA: 'text-red-400',
}

/**
 * Control de módulos POR EMPRESA (multiempresa): qué módulos puede usar
 * cada empresa según su tipo de negocio, su plan y los overrides del super-admin.
 */
export default function ControlFuncionalidades() {
  const [empresas, setEmpresas] = useState([])
  const [sel, setSel] = useState('')
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState('')
  const [params] = useSearchParams()

  useEffect(() => {
    api('/admin/empresas').then((lista) => {
      setEmpresas(lista)
      // Preselecciona la empresa si viene ?e=id (desde el botón "Módulos" de Empresas).
      const pre = params.get('e')
      if (pre && lista.some((x) => String(x.id) === pre)) cargarMatriz(pre)
    }).catch(() => {})
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function cargarMatriz(id) {
    setSel(id); setData(null); setError('')
    if (!id) return
    try { setData(await api(`/admin/empresas/${id}/modulos`)) }
    catch (err) { setError(err.message || 'No se pudo cargar.') }
  }

  async function cambiar(clave, estado) {
    setGuardando(clave)
    try {
      const r = await api(`/admin/empresas/${sel}/modulos`, { method: 'PUT', body: { clave, estado } })
      setData(r)
    } catch (err) { alert(err.message || 'No se pudo guardar.') }
    finally { setGuardando('') }
  }

  async function aplicarPlan() {
    if (!confirm('¿Restablecer todos los módulos a los valores por defecto del plan y tipo de negocio de la empresa?')) return
    try { setData(await api(`/admin/empresas/${sel}/modulos/aplicar-plan`, { method: 'POST', body: {} })) }
    catch (err) { alert(err.message || 'Error.') }
  }

  return (
    <div>
      <h1 className="text-2xl font-bold mb-2">Control de Módulos por Empresa</h1>
      <p className="text-slate-400 text-sm mb-6">
        Define qué módulos puede usar cada empresa. <span className="text-emerald-400">Activada</span> = uso normal ·{' '}
        <span className="text-amber-400">Restringida</span> = solo ver · <span className="text-red-400">Desactivada</span> = oculta.
        El tipo de negocio limita qué módulos aplican.
      </p>

      <div className="flex flex-wrap items-center gap-3 mb-6">
        <select value={sel} onChange={(e) => cargarMatriz(e.target.value)} className="input max-w-sm">
          <option value="">Selecciona una empresa…</option>
          {empresas.map((e) => (
            <option key={e.id} value={e.id}>
              {e.nombre} — {e.dueno?.email ?? ''} ({e.plan?.nombre ?? 'sin plan'})
            </option>
          ))}
        </select>
        {data && <button onClick={aplicarPlan} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Restablecer a plan</button>}
      </div>

      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {data && (
        <>
          <p className="text-sm text-slate-400 mb-3">
            🏪 <b className="text-slate-200">{data.empresa?.nombre}</b>
            {data.empresa?.tipo_negocio && <> · {data.empresa.tipo_negocio}</>}
            {data.empresa?.plan && <> · plan {data.empresa.plan}</>}
          </p>
          <div className="overflow-x-auto rounded-xl border border-slate-800">
            <table className="w-full text-sm">
              <thead className="bg-slate-800/60 text-slate-300">
                <tr>
                  <th className="text-left p-3">Módulo</th>
                  <th className="text-left p-3">Por plan</th>
                  <th className="text-left p-3">Estado actual</th>
                  <th className="text-left p-3">Cambiar a</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((it) => (
                  <tr key={it.clave} className={`border-t border-slate-800 ${it.permitido_por_tipo === false ? 'opacity-50' : ''}`}>
                    <td className="p-3 font-medium">
                      {it.label}
                      {it.permitido_por_tipo === false && <span className="ml-2 text-xs text-slate-500">(no aplica a este tipo de negocio)</span>}
                    </td>
                    <td className="p-3 text-slate-500 text-xs">{it.por_plan}</td>
                    <td className={`p-3 font-semibold ${ESTADO_COLOR[it.estado]}`}>
                      {it.estado}{it.override && <span className="text-slate-500 text-xs font-normal"> (manual)</span>}
                    </td>
                    <td className="p-3">
                      <select value={it.override ?? it.estado} disabled={guardando === it.clave || it.permitido_por_tipo === false}
                        onChange={(e) => cambiar(it.clave, e.target.value)}
                        className="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs disabled:opacity-40">
                        {data.estados.map((s) => <option key={s} value={s}>{s}</option>)}
                      </select>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}
