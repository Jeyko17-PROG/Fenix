import { useEffect, useState } from 'react'
import { api } from '../api/client'

const ACCION_LABEL = {
  ESTADO: 'Cambio de estado',
  PLAN: 'Cambio de plan',
  LIMITE: 'Cambio de límite',
  FUNCIONALIDAD: 'Funcionalidad',
  ELIMINAR: 'Eliminación de usuario',
}

export default function Auditoria() {
  const [registros, setRegistros] = useState([])
  const [cargando, setCargando] = useState(true)

  useEffect(() => {
    api('/admin/auditorias').then(setRegistros).catch(() => {}).finally(() => setCargando(false))
  }, [])

  return (
    <div>
      <h1 className="text-2xl font-bold mb-2">Auditoría de cambios</h1>
      <p className="text-slate-400 text-sm mb-6">Registro de las acciones realizadas por el Super Administrador (últimos 200).</p>

      {cargando ? <p className="text-slate-500">Cargando…</p> : (
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-slate-800/60 text-slate-300">
              <tr>
                <th className="text-left p-3">Fecha</th>
                <th className="text-left p-3">Administrador</th>
                <th className="text-left p-3">Usuario afectado</th>
                <th className="text-left p-3">Acción</th>
                <th className="text-left p-3">Detalle</th>
                <th className="text-left p-3">Antes → Después</th>
              </tr>
            </thead>
            <tbody>
              {registros.map((a) => (
                <tr key={a.id} className="border-t border-slate-800">
                  <td className="p-3 text-slate-400 whitespace-nowrap">{a.fecha ? new Date(a.fecha).toLocaleString('es') : '—'}</td>
                  <td className="p-3">{a.admin ?? '—'}</td>
                  <td className="p-3">{a.usuario ?? '—'}<div className="text-slate-500 text-xs">{a.usuario_email}</div></td>
                  <td className="p-3">{ACCION_LABEL[a.accion] ?? a.accion}</td>
                  <td className="p-3 text-slate-400">{a.funcionalidad ?? '—'}</td>
                  <td className="p-3"><span className="text-slate-400">{a.estado_anterior ?? '—'}</span> <span className="text-slate-600">→</span> <span className="text-emerald-400">{a.estado_nuevo ?? '—'}</span></td>
                </tr>
              ))}
              {registros.length === 0 && <tr><td colSpan="6" className="p-6 text-center text-slate-500">Sin registros de auditoría.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
