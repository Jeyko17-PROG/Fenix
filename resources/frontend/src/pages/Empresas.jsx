import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'

const ESTADO_COLOR = {
  ACTIVO: 'bg-emerald-500/15 text-emerald-400',
  SUSPENDIDO: 'bg-amber-500/15 text-amber-400',
  DESACTIVADO: 'bg-red-500/15 text-red-400',
  PENDIENTE_ACTIVACION: 'bg-sky-500/15 text-sky-400',
}

/**
 * Panel del super-admin: EMPRESAS registradas en la plataforma (tenants).
 * Cada empresa agrupa a su dueño y empleados; el plan, la membresía y los
 * módulos se administran aquí a nivel de empresa.
 */
export default function Empresas() {
  const navigate = useNavigate()
  const [empresas, setEmpresas] = useState([])
  const [planes, setPlanes] = useState([])
  const [buscar, setBuscar] = useState('')
  const [cargando, setCargando] = useState(true)
  const [error, setError] = useState('')
  const [editando, setEditando] = useState(null) // empresa seleccionada para editar, o null

  async function cargar() {
    setCargando(true); setError('')
    try {
      const [e, p] = await Promise.all([
        api(`/admin/empresas?buscar=${encodeURIComponent(buscar)}`),
        api('/planes'),
      ])
      setEmpresas(e); setPlanes(p)
    } catch (err) {
      setError(err.message || 'No se pudieron cargar las empresas.')
    } finally { setCargando(false) }
  }

  useEffect(() => {
    const t = setTimeout(cargar, 300)
    return () => clearTimeout(t)
  }, [buscar]) // eslint-disable-line react-hooks/exhaustive-deps

  async function accion(fn) {
    try { await fn(); await cargar() }
    catch (err) { alert(err.message || 'Error en la operación.') }
  }

  const cambiarPlan = (e, plan_id) => accion(() => api(`/admin/empresas/${e.id}/plan`, { method: 'POST', body: { plan_id: Number(plan_id) } }))
  const cambiarEstado = (e, estado) => accion(() => api(`/admin/empresas/${e.id}/estado`, { method: 'POST', body: { estado } }))
  const cambiarModoCobro = (e, modo_cobro) => accion(() => api(`/admin/empresas/${e.id}/modo-cobro`, { method: 'POST', body: { modo_cobro } }))

  async function cambiarLimite(e) {
    const v = prompt(`Límite manual de clientes para ${e.nombre} (vacío = usar el del plan):`, e.limite_manual ?? '')
    if (v === null) return
    accion(() => api(`/admin/empresas/${e.id}/limite`, { method: 'POST', body: { limite_clientes: v === '' ? null : Number(v) } }))
  }

  // Fecha manual hasta la que la empresa tiene acceso (activa/extiende sin
  // depender del ciclo de pago normal): esto es lo que de verdad revisa
  // VerificarMembresia — cambiar solo el "Estado" (Activar/Suspender) no
  // alcanza si esta fecha sigue vencida.
  async function cambiarMembresia(e) {
    const actual = e.membresia_vence_at ? e.membresia_vence_at.slice(0, 10) : ''
    const v = prompt(`Fecha hasta la que ${e.nombre} tiene acceso (AAAA-MM-DD):`, actual)
    if (v === null || v === '') return
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { alert('Formato de fecha inválido. Usa AAAA-MM-DD, ej: 2026-10-15.'); return }
    accion(() => api(`/admin/empresas/${e.id}/membresia`, { method: 'POST', body: { membresia_vence_at: v } }))
  }

  const regenerarCodigo = (e) => {
    if (!confirm(`¿Generar un nuevo código de activación para ${e.nombre}? El código anterior dejará de funcionar.`)) return
    accion(() => api(`/admin/empresas/${e.id}/regenerar-codigo`, { method: 'POST' }))
  }

  async function enviarCodigo(e) {
    if (!confirm(`¿Enviar el código de activación por correo a ${e.dueno?.email || 'el dueño'}?`)) return
    try {
      const r = await api(`/admin/empresas/${e.id}/enviar-codigo`, { method: 'POST' })
      alert(r.message || 'Código enviado.')
    } catch (err) { alert(err.message || 'No se pudo enviar el correo.') }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <h1 className="text-2xl font-bold">Empresas registradas</h1>
        <input value={buscar} onChange={(ev) => setBuscar(ev.target.value)} placeholder="Buscar empresa, correo, NIT…"
          className="input !mt-0 w-64" />
      </div>
      <p className="text-slate-400 text-sm mb-6">
        Cada empresa es una cuenta aislada del SaaS: agrupa a su dueño y empleados, y define plan, membresía y módulos.
      </p>

      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {cargando ? <p className="text-slate-500">Cargando…</p> : (
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-sm min-w-[900px]">
            <thead className="bg-slate-800/60 text-slate-300">
              <tr>
                <th className="text-left p-3">Empresa</th>
                <th className="text-left p-3">Tipo de negocio</th>
                <th className="text-left p-3">Dueño</th>
                <th className="text-left p-3">Plan</th>
                <th className="text-left p-3">Membresía</th>
                <th className="text-left p-3">Clientes</th>
                <th className="text-left p-3">Actividad</th>
                <th className="text-left p-3">Estado</th>
                <th className="text-right p-3">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {empresas.map((e) => (
                <tr key={e.id} className="border-t border-slate-800 hover:bg-slate-800/30">
                  <td className="p-3">
                    <p className="font-medium">{e.nombre}</p>
                    <p className="text-xs text-slate-500">{e.usuarios} usuario(s){e.numero_documento ? ` · ${e.tipo_documento} ${e.numero_documento}` : ''}</p>
                    {e.negocios_vinculados > 1 && (
                      <p className="text-xs text-violet-400 mt-0.5" title="El plan y el consumo se comparten entre estos negocios vinculados">
                        🔗 Comparte plan con {e.negocios_vinculados - 1} negocio(s) más
                        {e.empresa_gobernante && <> · plan de "{e.empresa_gobernante}"</>}
                      </p>
                    )}
                  </td>
                  <td className="p-3 text-slate-400">{e.tipo_negocio?.nombre ?? '—'}</td>
                  <td className="p-3">
                    <p className="text-slate-300">{e.dueno?.name ?? '—'}</p>
                    <p className="text-xs text-slate-500">{e.dueno?.email}</p>
                  </td>
                  <td className="p-3">
                    <select value={e.plan?.id ?? ''} onChange={(ev) => cambiarPlan(e, ev.target.value)}
                      className="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs">
                      <option value="" disabled>—</option>
                      {planes.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
                    </select>
                    <select value={e.modo_cobro ?? 'membresia'} onChange={(ev) => cambiarModoCobro(e, ev.target.value)}
                      className="bg-transparent border-none text-xs text-slate-500 mt-1 p-0 focus:ring-0 cursor-pointer hover:text-slate-300">
                      <option value="prueba">🎁 prueba gratis (15 días)</option>
                      <option value="membresia">📅 membresía</option>
                      <option value="prepago">💰 pago por uso</option>
                    </select>
                  </td>
                  <td className="p-3">
                    {e.membresia_vence_at
                      ? <span className={e.membresia_vencida ? 'text-red-400 font-semibold' : 'text-slate-300'}>
                          {new Date(e.membresia_vence_at).toLocaleDateString('es-CO')}{e.membresia_vencida ? ' ⚠️ vencida' : ''}
                        </span>
                      : <span className="text-slate-500">sin control</span>}
                    <button onClick={() => cambiarMembresia(e)} className="ml-2 text-xs text-sky-400 hover:underline">editar</button>
                  </td>
                  <td className="p-3">
                    <span className={e.limite_clientes && e.clientes_usados >= e.limite_clientes ? 'text-red-400' : 'text-slate-300'}>
                      {e.clientes_usados} / {e.limite_clientes ?? '∞'}
                    </span>
                    <button onClick={() => cambiarLimite(e)} className="ml-2 text-xs text-sky-400 hover:underline">editar</button>
                  </td>
                  <td className="p-3 text-xs text-slate-400">
                    {e.dueno_veces_login ?? 0} inicio(s)
                    {e.dueno_ultimo_acceso && <><br />último: {new Date(e.dueno_ultimo_acceso).toLocaleDateString('es-CO')}</>}
                  </td>
                  <td className="p-3">
                    {e.dueno_estado === 'PENDIENTE_ACTIVACION' ? (
                      <div>
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${ESTADO_COLOR.PENDIENTE_ACTIVACION}`}>Pendiente de activación</span>
                        <p className="mt-1 text-xs text-slate-300">Código: <span className="font-mono font-bold tracking-widest">{e.codigo_activacion}</span></p>
                        <div className="mt-0.5 flex gap-3">
                          <button onClick={() => enviarCodigo(e)} className="text-xs text-emerald-400 hover:underline">Enviar por correo</button>
                          <button onClick={() => regenerarCodigo(e)} className="text-xs text-sky-400 hover:underline">Regenerar código</button>
                        </div>
                      </div>
                    ) : (
                      <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${ESTADO_COLOR[e.estado] ?? ''}`}>{e.estado}</span>
                    )}
                  </td>
                  <td className="p-3">
                    <div className="flex flex-wrap gap-1 justify-end">
                      <button onClick={() => navigate(`/funcionalidades?e=${e.id}`)}
                        className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">🧩 Módulos</button>
                      <button onClick={() => setEditando(e)}
                        className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">✏️ Editar</button>
                      {e.estado !== 'ACTIVO' && <button onClick={() => cambiarEstado(e, 'ACTIVO')} className="text-xs rounded bg-emerald-700 hover:bg-emerald-600 px-2 py-1">Activar</button>}
                      {e.estado !== 'SUSPENDIDO' && <button onClick={() => cambiarEstado(e, 'SUSPENDIDO')} className="text-xs rounded bg-amber-700 hover:bg-amber-600 px-2 py-1">Suspender</button>}
                      {e.estado !== 'DESACTIVADO' && <button onClick={() => cambiarEstado(e, 'DESACTIVADO')} className="text-xs rounded bg-red-800 hover:bg-red-700 px-2 py-1">Desactivar</button>}
                    </div>
                  </td>
                </tr>
              ))}
              {empresas.length === 0 && <tr><td colSpan="9" className="p-6 text-center text-slate-500">Sin empresas registradas.</td></tr>}
            </tbody>
          </table>
        </div>
      )}

      {editando && (
        <EditarEmpresaModal
          empresa={editando}
          onClose={() => setEditando(null)}
          onGuardada={() => { setEditando(null); cargar() }}
        />
      )}
    </div>
  )
}

/** Modal de edición de datos básicos de la empresa (incluye el remitente de facturación). */
function EditarEmpresaModal({ empresa, onClose, onGuardada }) {
  const [form, setForm] = useState({
    nombre: empresa.nombre ?? '',
    tipo_documento: empresa.tipo_documento ?? '',
    numero_documento: empresa.numero_documento ?? '',
    telefono: empresa.telefono ?? '',
    email: empresa.email ?? '',
    email_facturacion: empresa.email_facturacion ?? '',
    direccion: empresa.direccion ?? '',
    tipo_negocio_id: empresa.tipo_negocio?.id ?? '',
  })
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [tiposNegocio, setTiposNegocio] = useState([])

  useEffect(() => {
    api('/tipos-negocio').then(setTiposNegocio).catch(() => {})
  }, [])

  const set = (k) => (ev) => setForm({ ...form, [k]: ev.target.value })

  async function guardar(ev) {
    ev.preventDefault(); setError(''); setGuardando(true)
    try {
      await api(`/admin/empresas/${empresa.id}`, { method: 'PUT', body: form })
      onGuardada()
    } catch (err) {
      setError(err.message || 'No se pudo guardar.')
    } finally { setGuardando(false) }
  }

  return (
    <div onClick={onClose} className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
      <div onClick={(ev) => ev.stopPropagation()} className="bg-slate-800 rounded-2xl p-6 max-w-md w-full max-h-[90vh] overflow-y-auto">
        <h2 className="text-lg font-bold mb-4">Editar empresa</h2>
        <form onSubmit={guardar} className="space-y-3">
          {error && <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

          <label className="block text-sm text-slate-300">Nombre *
            <input required value={form.nombre} onChange={set('nombre')} className="input mt-1" />
          </label>

          <label className="block text-sm text-slate-300">Tipo de negocio *
            <select required value={form.tipo_negocio_id} onChange={set('tipo_negocio_id')} className="input mt-1">
              <option value="">Selecciona…</option>
              {tiposNegocio.map((t) => <option key={t.id} value={t.id}>{t.nombre}</option>)}
            </select>
          </label>

          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm text-slate-300">Tipo doc. *
              <select required value={form.tipo_documento} onChange={set('tipo_documento')} className="input mt-1">
                <option value="">—</option>
                <option value="CC">CC</option><option value="CE">CE</option><option value="NIT">NIT</option><option value="PAS">PAS</option>
              </select>
            </label>
            <label className="block text-sm text-slate-300">N° documento *
              <input required value={form.numero_documento} onChange={set('numero_documento')} className="input mt-1" />
            </label>
          </div>

          <label className="block text-sm text-slate-300">Teléfono *
            <input required value={form.telefono} onChange={set('telefono')} className="input mt-1" />
          </label>

          <label className="block text-sm text-slate-300">Correo de contacto *
            <input required type="email" value={form.email} onChange={set('email')} className="input mt-1" />
          </label>

          <label className="block text-sm text-slate-300">Correo remitente de facturación
            <input type="email" placeholder="facturacion@sunegocio.com" value={form.email_facturacion} onChange={set('email_facturacion')} className="input mt-1" />
          </label>
          <p className="text-xs text-amber-300/90 bg-amber-500/10 border border-amber-500/30 rounded-lg px-3 py-2">
            ⚠️ Nota técnica: para que este correo pueda enviar facturas con éxito, el dominio o la dirección específica debe estar verificada previamente como Remitente (Sender) en el dashboard de Brevo.
          </p>

          <label className="block text-sm text-slate-300">Dirección *
            <input required minLength={10} value={form.direccion} onChange={set('direccion')} className="input mt-1" />
          </label>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button type="submit" disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
              {guardando ? 'Guardando…' : 'Guardar cambios'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
