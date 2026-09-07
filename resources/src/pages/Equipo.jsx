import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'

const ESTADO_COLOR = {
  ACTIVO: 'bg-emerald-500/15 text-emerald-400',
  SUSPENDIDO: 'bg-amber-500/15 text-amber-400',
  DESACTIVADO: 'bg-red-500/15 text-red-400',
}

const NUEVO_VACIO = { name: '', email: '', telefono: '', rol_id: '', bodega_id: '', password: '' }

// Algunos roles solo tienen sentido para el rubro que los usa (Mecánico ve
// solo sus órdenes de taller, Lavador solo las de lavadero): en cualquier
// otro tipo de negocio (barbería, tatuajes, tienda...) no deberían aparecer
// como opción, para no confundir con roles de un rubro que no es el suyo.
const ROLES_POR_RUBRO = {
  Mecanico: ['taller_motos', 'taller_carros', 'taller_general'],
  Lavador: ['lavadero'],
}
const rolAplica = (nombreRol, tipoNegocio) => !ROLES_POR_RUBRO[nombreRol] || ROLES_POR_RUBRO[nombreRol].includes(tipoNegocio)

export default function Equipo() {
  const { user } = useAuth()
  const tipoNegocio = user?.empresa_info?.tipo_negocio?.clave
  const [usuarios, setUsuarios] = useState([])
  const [rolesTodos, setRolesTodos] = useState([])
  const [bodegas, setBodegas] = useState([])
  const [cargando, setCargando] = useState(true)
  const [error, setError] = useState('')
  const [abierto, setAbierto] = useState(false)
  const [nuevo, setNuevo] = useState(NUEVO_VACIO)
  const [creando, setCreando] = useState(false)
  const [creado, setCreado] = useState(null) // { email, password } tras crear, para mostrar una sola vez
  const [verRol, setVerRol] = useState(null) // rol cuyo detalle de permisos se muestra

  // Solo los roles que aplican al rubro de este negocio (ver ROLES_POR_RUBRO).
  const roles = rolesTodos.filter((r) => rolAplica(r.nombre, tipoNegocio))

  async function cargar() {
    setCargando(true); setError('')
    try {
      const [u, r, b] = await Promise.all([
        api('/equipo/usuarios'),
        api('/equipo/roles'),
        api('/bodegas').catch(() => []),
      ])
      setUsuarios(u); setRolesTodos(r); setBodegas(b.data ?? b)
    } catch (err) {
      setError(err.message || 'No se pudo cargar el equipo.')
    } finally {
      setCargando(false)
    }
  }
  useEffect(() => { cargar() }, [])

  async function crear(e) {
    e.preventDefault(); setError(''); setCreando(true)
    try {
      const body = { ...nuevo, bodega_id: nuevo.bodega_id || undefined, password: nuevo.password || undefined }
      await api('/equipo/usuarios', { method: 'POST', body })
      setCreado({ email: nuevo.email, password: nuevo.password || '(se generó una temporal — pídesela al empleado con "Reset clave" si la perdió)' })
      setNuevo(NUEVO_VACIO); setAbierto(false)
      cargar()
    } catch (err) {
      setError(err.message || 'No se pudo crear el usuario.')
    } finally {
      setCreando(false)
    }
  }

  async function cambiarRol(u, rolId) {
    try {
      await api(`/equipo/usuarios/${u.id}`, { method: 'PUT', body: { rol_id: Number(rolId) } })
      cargar()
    } catch (err) { alert(err.message || 'No se pudo cambiar el rol.') }
  }

  async function cambiarEstado(u, estado) {
    try {
      await api(`/equipo/usuarios/${u.id}/estado`, { method: 'POST', body: { estado } })
      cargar()
    } catch (err) { alert(err.message || 'No se pudo cambiar el estado.') }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold">Mi equipo</h1>
          <p className="text-sm text-slate-400">Agrega personas a tu negocio y asígnales un rol con sus funcionalidades.</p>
        </div>
        <button onClick={() => setAbierto(true)} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nuevo usuario</button>
      </div>

      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {creado && (
        <div className="mb-4 rounded-lg border border-emerald-600/40 bg-emerald-500/10 px-4 py-3 text-sm">
          <p className="font-semibold text-emerald-300">Usuario creado: {creado.email}</p>
          <p className="text-slate-300">Contraseña: <span className="font-mono">{creado.password}</span></p>
          <button onClick={() => setCreado(null)} className="mt-1 text-xs text-slate-400 hover:underline">Cerrar</button>
        </div>
      )}

      {/* Referencia: roles disponibles y qué puede hacer cada uno */}
      <div className="mb-6 rounded-xl border border-slate-800 bg-slate-900/60 p-4">
        <h2 className="mb-3 text-sm font-semibold text-slate-300">Roles disponibles</h2>
        <div className="flex flex-wrap gap-2">
          {roles.map((r) => (
            <button key={r.id} onClick={() => setVerRol(r)}
              className="rounded-full bg-slate-800 hover:bg-slate-700 px-3 py-1.5 text-xs font-medium text-slate-200">
              {r.nombre} <span className="text-slate-500">· {r.permisos?.length ?? 0} permisos</span>
            </button>
          ))}
        </div>
      </div>

      {cargando ? (
        <p className="text-slate-500">Cargando…</p>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-slate-800/60 text-slate-300">
              <tr>
                <th className="text-left p-3">Usuario</th>
                <th className="text-left p-3">Rol</th>
                <th className="text-left p-3">Establecimiento</th>
                <th className="text-left p-3">Estado</th>
                <th className="text-right p-3">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {usuarios.map((u) => (
                <tr key={u.id} className="border-t border-slate-800 hover:bg-slate-800/30">
                  <td className="p-3">
                    <div className="font-medium">{u.name} {u.es_propietario && <span className="text-xs text-amber-400">★ Dueño</span>}</div>
                    <div className="text-slate-500 text-xs">{u.email}</div>
                  </td>
                  <td className="p-3">
                    {u.es_propietario ? (
                      <span className="text-slate-300">{u.rol}</span>
                    ) : (
                      <select value={u.rol_id ?? ''} onChange={(e) => cambiarRol(u, e.target.value)}
                        className="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs">
                        {roles.map((r) => <option key={r.id} value={r.id}>{r.nombre}</option>)}
                      </select>
                    )}
                  </td>
                  <td className="p-3 text-slate-400">{u.bodega ?? '—'}</td>
                  <td className="p-3">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${ESTADO_COLOR[u.estado] ?? ''}`}>{u.estado}</span>
                  </td>
                  <td className="p-3 text-right">
                    {!u.es_propietario && (
                      <div className="flex flex-wrap gap-1 justify-end">
                        {u.estado !== 'ACTIVO' && <button onClick={() => cambiarEstado(u, 'ACTIVO')} className="text-xs rounded bg-emerald-700 hover:bg-emerald-600 px-2 py-1">Activar</button>}
                        {u.estado !== 'SUSPENDIDO' && <button onClick={() => cambiarEstado(u, 'SUSPENDIDO')} className="text-xs rounded bg-amber-700 hover:bg-amber-600 px-2 py-1">Suspender</button>}
                        {u.estado !== 'DESACTIVADO' && <button onClick={() => cambiarEstado(u, 'DESACTIVADO')} className="text-xs rounded bg-red-800 hover:bg-red-700 px-2 py-1">Desactivar</button>}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
              {usuarios.length === 0 && <tr><td colSpan="5" className="p-6 text-center text-slate-500">Sin usuarios en tu equipo.</td></tr>}
            </tbody>
          </table>
        </div>
      )}

      {/* Nuevo usuario */}
      {abierto && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={() => setAbierto(false)}>
          <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
            <h2 className="text-lg font-bold mb-4">Nuevo usuario</h2>
            <form onSubmit={crear} className="space-y-3" autoComplete="off">
              <input required autoComplete="off" value={nuevo.name} onChange={(e) => setNuevo({ ...nuevo, name: e.target.value })} placeholder="Nombre completo" className="input" />
              <input required type="email" autoComplete="off" value={nuevo.email} onChange={(e) => setNuevo({ ...nuevo, email: e.target.value })} placeholder="Correo de este nuevo usuario (no el tuyo)" className="input" />
              <input autoComplete="off" value={nuevo.telefono} onChange={(e) => setNuevo({ ...nuevo, telefono: e.target.value })} placeholder="Celular (opcional)" className="input" />
              <select required value={nuevo.rol_id} onChange={(e) => setNuevo({ ...nuevo, rol_id: e.target.value })} className="input">
                <option value="">Rol…</option>
                {roles.filter((r) => r.nombre !== 'Usuario').map((r) => <option key={r.id} value={r.id}>{r.nombre}</option>)}
              </select>
              {bodegas.length > 0 && (
                <select required value={nuevo.bodega_id} onChange={(e) => setNuevo({ ...nuevo, bodega_id: e.target.value })} className="input">
                  <option value="">Establecimiento…</option>
                  {bodegas.map((b) => <option key={b.id} value={b.id}>{b.nombre}</option>)}
                </select>
              )}
              <input type="password" autoComplete="new-password" value={nuevo.password} onChange={(e) => setNuevo({ ...nuevo, password: e.target.value })} placeholder="Contraseña (opcional, se genera una si la dejas vacía)" className="input" />
              <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={() => setAbierto(false)} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
                <button disabled={creando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">{creando ? 'Creando…' : 'Crear usuario'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Detalle de permisos de un rol */}
      {verRol && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={() => setVerRol(null)}>
          <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
            <div className="flex justify-between items-start mb-3">
              <h2 className="text-lg font-bold">{verRol.nombre}</h2>
              <button onClick={() => setVerRol(null)} className="text-slate-400 hover:text-white text-xl">×</button>
            </div>
            {verRol.descripcion && <p className="text-sm text-slate-400 mb-3">{verRol.descripcion}</p>}
            {verRol.permisos?.length > 0 ? (
              <ul className="space-y-1 text-sm">
                {verRol.permisos.map((p) => (
                  <li key={p.id} className="flex items-start gap-2">
                    <span className="text-emerald-400">✓</span>
                    <span>{p.descripcion || p.clave}</span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-slate-500">Este rol no tiene permisos administrativos asignados.</p>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
