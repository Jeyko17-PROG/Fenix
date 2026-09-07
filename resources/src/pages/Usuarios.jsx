import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'

const ESTADO_COLOR = {
  ACTIVO: 'bg-emerald-500/15 text-emerald-400',
  SUSPENDIDO: 'bg-amber-500/15 text-amber-400',
  DESACTIVADO: 'bg-red-500/15 text-red-400',
  PENDIENTE_ACTIVACION: 'bg-sky-500/15 text-sky-400',
}

export default function Usuarios() {
  const navigate = useNavigate()
  const [usuarios, setUsuarios] = useState([])
  const [planes, setPlanes] = useState([])
  const [buscar, setBuscar] = useState('')
  const [cargando, setCargando] = useState(true)
  const [error, setError] = useState('')
  const [editando, setEditando] = useState(null) // usuario en modal de edición
  const [viendo, setViendo] = useState(null)     // usuario en modal de detalle
  const [eliminando, setEliminando] = useState(null) // usuario en modal de eliminación
  const [openQuick, setOpenQuick] = useState(false)

  async function cargar() {
    setCargando(true); setError('')
    try {
      const q = buscar ? `?buscar=${encodeURIComponent(buscar)}` : ''
      const [u, p] = await Promise.all([api(`/admin/usuarios${q}`), api('/planes')])
      setUsuarios(u); setPlanes(p)
    } catch (err) {
      setError(err.message || 'No se pudieron cargar los usuarios.')
    } finally {
      setCargando(false)
    }
  }

  useEffect(() => { cargar() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function accion(fn) {
    try { await fn(); await cargar() }
    catch (err) { alert(err.message || 'Error en la operación.') }
  }

  const cambiarEstado = (u, estado) => accion(() => api(`/admin/usuarios/${u.id}/estado`, { method: 'POST', body: { estado } }))
  const cambiarPlan = (u, plan_id) => accion(() => api(`/admin/usuarios/${u.id}/plan`, { method: 'POST', body: { plan_id: Number(plan_id) } }))

  async function restablecer(u) {
    if (!confirm(`¿Restablecer la contraseña de ${u.name}?`)) return
    try {
      const r = await api(`/admin/usuarios/${u.id}/restablecer-password`, { method: 'POST', body: {} })
      alert(`Contraseña temporal de ${u.name}:\n\n${r.password_temporal}\n\nEntrégasela al usuario.`)
    } catch (err) { alert(err.message || 'No se pudo restablecer.') }
  }

  async function cambiarLimite(u) {
    const v = prompt(`Límite manual de clientes para ${u.name} (vacío = usar el del plan):`, u.limite_manual ?? '')
    if (v === null) return
    accion(() => api(`/admin/usuarios/${u.id}/limite`, { method: 'POST', body: { limite_clientes: v === '' ? null : Number(v) } }))
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 className="text-2xl font-bold">Usuarios registrados</h1>
        <div className="flex gap-2">
          <button onClick={() => setOpenQuick(true)} className="rounded-lg bg-blue-600 hover:bg-blue-500 px-4 text-sm font-semibold">Agregar Empleado</button>
          <input value={buscar} onChange={(e) => setBuscar(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && cargar()}
            placeholder="Buscar nombre, correo, documento…" className="input max-w-xs" />
          <button onClick={cargar} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 text-sm font-semibold">Buscar</button>
        </div>
      </div>

      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {cargando ? (
        <p className="text-slate-500">Cargando…</p>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-slate-800/60 text-slate-300">
              <tr>
                <th className="text-left p-3">ID</th>
                <th className="text-left p-3">Usuario</th>
                <th className="text-left p-3">Documento</th>
                <th className="text-left p-3">Celular</th>
                <th className="text-left p-3">Registro</th>
                <th className="text-left p-3">Último acceso</th>
                <th className="text-left p-3">Plan</th>
                <th className="text-left p-3">Clientes</th>
                <th className="text-left p-3">Estado</th>
                <th className="text-right p-3">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {usuarios.map((u) => (
                <tr key={u.id} className="border-t border-slate-800 hover:bg-slate-800/30">
                  <td className="p-3 text-slate-500">#{u.id}</td>
                  <td className="p-3">
                    <div className="font-medium">{u.name} {u.es_super_admin && <span className="text-xs text-amber-400">★ Super</span>}</div>
                    <div className="text-slate-500 text-xs">{u.email}</div>
                  </td>
                  <td className="p-3 text-slate-400">{u.tipo_documento} {u.numero_documento}</td>
                  <td className="p-3 text-slate-400">{u.telefono ?? '—'}</td>
                  <td className="p-3 text-slate-400">{u.fecha_registro ? new Date(u.fecha_registro).toLocaleDateString('es') : '—'}</td>
                  <td className="p-3 text-slate-400">{u.ultimo_acceso ? new Date(u.ultimo_acceso).toLocaleString('es') : 'Nunca'}</td>
                  <td className="p-3">
                    <select value={u.plan?.id ?? ''} disabled={u.es_super_admin}
                      onChange={(e) => cambiarPlan(u, e.target.value)}
                      className="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs disabled:opacity-50">
                      <option value="" disabled>—</option>
                      {planes.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
                    </select>
                  </td>
                  <td className="p-3">
                    {u.clientes_disponibles === null
                      ? <span className="text-slate-400">∞</span>
                      : <span className={u.clientes_usados >= (u.limite_clientes ?? 0) ? 'text-red-400' : 'text-slate-300'}>
                          {u.clientes_usados} / {u.limite_clientes}
                        </span>}
                    <button onClick={() => cambiarLimite(u)} className="ml-2 text-xs text-sky-400 hover:underline">editar</button>
                  </td>
                  <td className="p-3">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${ESTADO_COLOR[u.estado] ?? ''}`}>
                      {u.estado === 'PENDIENTE_ACTIVACION' ? 'Pendiente de activación' : u.estado}
                    </span>
                    {u.codigo_activacion && (
                      <p className="mt-1 text-xs text-slate-300">
                        Código: <span className="font-mono font-bold tracking-widest">{u.codigo_activacion}</span>
                      </p>
                    )}
                  </td>
                  <td className="p-3">
                    <div className="flex flex-wrap gap-1 justify-end">
                      <button onClick={() => setViendo(u)} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Ver</button>
                      <button onClick={() => setEditando(u)} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Editar</button>
                      {!u.es_super_admin && <>
                        {u.estado !== 'ACTIVO' && <button onClick={() => cambiarEstado(u, 'ACTIVO')} className="text-xs rounded bg-emerald-700 hover:bg-emerald-600 px-2 py-1">Activar</button>}
                        {u.estado !== 'SUSPENDIDO' && <button onClick={() => cambiarEstado(u, 'SUSPENDIDO')} className="text-xs rounded bg-amber-700 hover:bg-amber-600 px-2 py-1">Suspender</button>}
                        {u.estado !== 'DESACTIVADO' && <button onClick={() => cambiarEstado(u, 'DESACTIVADO')} className="text-xs rounded bg-red-800 hover:bg-red-700 px-2 py-1">Desactivar</button>}
                        <button onClick={() => restablecer(u)} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Reset clave</button>
                        <button onClick={() => navigate(`/funcionalidades?u=${u.id}`)} className="text-xs rounded bg-indigo-700 hover:bg-indigo-600 px-2 py-1">Funcionalidades</button>
                        <button onClick={() => setEliminando(u)} className="text-xs rounded bg-red-900 hover:bg-red-800 px-2 py-1">Eliminar</button>
                      </>}
                    </div>
                  </td>
                </tr>
              ))}
              {usuarios.length === 0 && <tr><td colSpan="10" className="p-6 text-center text-slate-500">Sin usuarios.</td></tr>}
            </tbody>
          </table>
        </div>
      )}

      {editando && <ModalEditar usuario={editando} onClose={() => setEditando(null)} onGuardado={() => { setEditando(null); cargar() }} />}
      {viendo && <ModalVer usuario={viendo} onClose={() => setViendo(null)} />}
      {eliminando && <ModalEliminar usuario={eliminando} onClose={() => setEliminando(null)} onEliminado={() => { setEliminando(null); cargar() }} />}
      {openQuick && <ModalQuick onClose={() => setOpenQuick(false)} />}
    </div>
  )
}

function Dato({ label, children }) {
  return <div><span className="text-slate-500 text-xs">{label}</span><div className="text-slate-200">{children ?? '—'}</div></div>
}

function ModalVer({ usuario: u, onClose }) {
  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
        <div className="flex justify-between items-start mb-4">
          <h2 className="text-lg font-bold">{u.name} {u.es_super_admin && <span className="text-amber-400 text-sm">★ Super Admin</span>}</h2>
          <button onClick={onClose} className="text-slate-400 hover:text-white text-xl">×</button>
        </div>
        <div className="grid grid-cols-2 gap-3 text-sm">
          <Dato label="ID interno">#{u.id}</Dato>
          <Dato label="Estado">{u.estado === 'PENDIENTE_ACTIVACION' ? 'Pendiente de activación' : u.estado}</Dato>
          {u.codigo_activacion && <Dato label="Código de activación">{u.codigo_activacion}</Dato>}
          <Dato label="Tipo documento">{u.tipo_documento}</Dato>
          <Dato label="N° documento">{u.numero_documento}</Dato>
          <Dato label="Celular">{u.telefono}</Dato>
          <Dato label="Correo">{u.email}</Dato>
          <Dato label="Plan">{u.plan?.nombre}</Dato>
          <Dato label="Clientes">{u.clientes_disponibles === null ? `${u.clientes_usados} / ∞` : `${u.clientes_usados} / ${u.limite_clientes}`}</Dato>
          <Dato label="Fecha de registro">{u.fecha_registro ? new Date(u.fecha_registro).toLocaleString('es') : '—'}</Dato>
          <Dato label="Último acceso">{u.ultimo_acceso ? new Date(u.ultimo_acceso).toLocaleString('es') : 'Nunca'}</Dato>
        </div>
      </div>
    </div>
  )
}

function ModalEliminar({ usuario, onClose, onEliminado }) {
  const [procesando, setProcesando] = useState(false)
  const [confirmandoPermanente, setConfirmandoPermanente] = useState(false)

  async function eliminarLogica() {
    setProcesando(true)
    try {
      const r = await api(`/admin/usuarios/${usuario.id}`, { method: 'DELETE' })
      alert(r.message); onEliminado()
    } catch (err) { alert(err.message || 'No se pudo eliminar.'); setProcesando(false) }
  }

  async function eliminarPermanente() {
    setProcesando(true)
    try {
      const r = await api(`/admin/usuarios/${usuario.id}/permanente`, { method: 'DELETE' })
      alert(r.message); onEliminado()
    } catch (err) { alert(err.message || 'No se pudo eliminar.'); setProcesando(false) }
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
        {!confirmandoPermanente ? (
          <>
            <h2 className="text-lg font-bold mb-2">¿Está seguro de que desea eliminar este usuario?</h2>
            <p className="text-sm text-slate-400 mb-4"><b>{usuario.name}</b> — {usuario.email}</p>
            <div className="rounded-lg bg-slate-800/60 p-3 text-xs text-slate-400 mb-4">
              <p className="text-slate-300 font-semibold mb-1">Eliminación lógica (recomendada)</p>
              No podrá iniciar sesión y la cuenta queda marcada como eliminada. La información se conserva para auditoría.
            </div>
            <div className="flex flex-wrap justify-end gap-2">
              <button onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
              <button onClick={() => setConfirmandoPermanente(true)} className="rounded-lg bg-red-900 hover:bg-red-800 px-4 py-2 text-sm">Eliminar permanentemente…</button>
              <button onClick={eliminarLogica} disabled={procesando} className="rounded-lg bg-red-700 hover:bg-red-600 disabled:opacity-50 px-4 py-2 text-sm font-semibold">{procesando ? 'Eliminando…' : 'Sí, eliminar'}</button>
            </div>
          </>
        ) : (
          <>
            <h2 className="text-lg font-bold text-red-300 mb-2">⚠️ Eliminación permanente</h2>
            <p className="text-sm text-slate-300 mb-3">Se eliminarán <b>{usuario.name}</b> y <b>TODA</b> su información: clientes, citas, facturas, inventario, productos y notas. <b>Esta acción es irreversible.</b></p>
            <div className="flex flex-wrap justify-end gap-2">
              <button onClick={() => setConfirmandoPermanente(false)} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
              <button onClick={eliminarPermanente} disabled={procesando} className="rounded-lg bg-red-700 hover:bg-red-600 disabled:opacity-50 px-4 py-2 text-sm font-semibold">{procesando ? 'Eliminando…' : 'Sí, eliminar todo permanentemente'}</button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function ModalEditar({ usuario, onClose, onGuardado }) {
  const [form, setForm] = useState({
    name: usuario.name ?? '', email: usuario.email ?? '',
    tipo_documento: usuario.tipo_documento ?? 'CC', numero_documento: usuario.numero_documento ?? '',
    telefono: usuario.telefono ?? '',
  })
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function guardar(e) {
    e.preventDefault(); setError(''); setGuardando(true)
    try {
      await api(`/admin/usuarios/${usuario.id}`, { method: 'PUT', body: form })
      onGuardado()
    } catch (err) {
      setError(err.message || 'No se pudo guardar.')
    } finally { setGuardando(false) }
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold mb-4">Editar usuario</h2>
        {error && <div className="mb-3 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}
        <form onSubmit={guardar} className="space-y-3">
          <input value={form.name} onChange={set('name')} placeholder="Nombre" className="input" required />
          <input value={form.email} onChange={set('email')} type="email" placeholder="Correo" className="input" required />
          <div className="flex gap-2">
            <select value={form.tipo_documento} onChange={set('tipo_documento')} className="input max-w-[120px]">
              <option value="CC">CC</option><option value="CE">CE</option><option value="NIT">NIT</option><option value="PAS">PAS</option>
            </select>
            <input value={form.numero_documento} onChange={set('numero_documento')} placeholder="N° documento" className="input" />
          </div>
          <input value={form.telefono} onChange={set('telefono')} placeholder="Celular" className="input" />
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button type="submit" disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">{guardando ? 'Guardando…' : 'Guardar'}</button>
          </div>
        </form>
      </div>
    </div>
  )
}

function ModalQuick({ onClose }) {
  const [nombre, setNombre] = useState('')
  const [apellido, setApellido] = useState('')
  const [guardando, setGuardando] = useState(false)
  async function guardar(e) {
    e.preventDefault(); setGuardando(true)
    try {
      await api('/equipo/usuarios/quick', { method: 'POST', body: { nombre, apellido } })
      onClose()
    } catch (err) { alert(err.message || 'No se pudo crear el empleado.'); setGuardando(false) }
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold mb-3">Agregar empleado rápido</h2>
        <form onSubmit={guardar} className="space-y-3">
          <input value={nombre} onChange={(e) => setNombre(e.target.value)} placeholder="Nombre" className="input" required />
          <input value={apellido} onChange={(e) => setApellido(e.target.value)} placeholder="Apellido" className="input" required />
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button type="submit" disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">{guardando ? 'Creando…' : 'Crear'}</button>
          </div>
        </form>
      </div>
    </div>
  )
}
