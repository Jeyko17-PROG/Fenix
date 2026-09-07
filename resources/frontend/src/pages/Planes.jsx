import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { useFeatures } from '../context/FeaturesContext'

const COP = (n) => '$' + Number(n).toLocaleString('es-CO')

const PLANTILLA = { id: null, nombre: '', precio_mensual: 0, limite_clientes: 200, limite_citas: 200, incluye: [], funcionalidades: [], activo: true, orden: 99 }

export default function Planes() {
  const { user } = useAuth()
  const { catalogo } = useFeatures()
  const esSuper = user?.es_super_admin
  const [planes, setPlanes] = useState([])
  const [editando, setEditando] = useState(null)
  const [cargando, setCargando] = useState(true)
  const [pagando, setPagando] = useState(null)
  const [paquetes, setPaquetes] = useState([])
  const [creditos, setCreditos] = useState({})
  const [searchParams] = useSearchParams()
  const vencida = searchParams.get('vencida') === '1' || user?.facturacion_saas?.membresia_vencida
  const limiteAlcanzado = searchParams.get('limite') === '1'
  const estadoPago = searchParams.get('status')
  const referenciaPago = searchParams.get('ref')

  async function cargar() {
    setCargando(true)
    try {
      setPlanes(await api('/planes'))
      // Billetera de pago por uso: paquetes de recarga y saldo actual.
      const [pkgs, creds] = await Promise.all([
        api('/credit-packages?module=facturacion').catch(() => []),
        api('/credits').catch(() => ({})),
      ])
      setPaquetes(pkgs || [])
      setCreditos(creds || {})
    } finally { setCargando(false) }
  }
  useEffect(() => { cargar() }, [])

  /** Abre el checkout de Wompi (PSE, Nequi, tarjeta) para pagar el plan. */
  async function pagarPlan(p) {
    setPagando(`plan-${p.id}`)
    try {
      const r = await api(`/planes/${p.id}/checkout`, { method: 'POST' })
      window.location.href = r.checkoutUrl
    } catch (err) {
      alert(err.message || 'No se pudo iniciar el pago.')
    } finally { setPagando(null) }
  }

  /** Abre el checkout de Wompi para recargar la billetera (pago por uso). */
  async function recargar(pkg) {
    setPagando(`pkg-${pkg.id}`)
    try {
      const r = await api('/credits/create-session', { method: 'POST', body: { package_id: pkg.id } })
      window.location.href = r.checkoutUrl
    } catch (err) {
      alert(err.message || 'No se pudo iniciar la recarga.')
    } finally { setPagando(null) }
  }

  async function guardar(p) {
    const body = {
      nombre: p.nombre, precio_mensual: Number(p.precio_mensual),
      limite_clientes: Number(p.limite_clientes), limite_citas: Number(p.limite_citas),
      incluye: p.incluye,
      funcionalidades: p.funcionalidades, activo: p.activo, orden: Number(p.orden) || 0,
    }
    try {
      if (p.id) await api(`/admin/planes/${p.id}`, { method: 'PUT', body })
      else await api('/admin/planes', { method: 'POST', body })
      setEditando(null); cargar()
    } catch (err) { alert(err.message || 'No se pudo guardar.') }
  }

  return (
    <div>
      {/* Siempre visible: evita que esta pantalla se sienta un callejón sin salida. */}
      <Link to="/dashboard" className="mb-4 inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-white">
        ← Volver al Dashboard
      </Link>

      {/* Aviso de membresía vencida: pantalla de pasarela de pago */}
      {estadoPago === 'error' && (
        <div className="mb-6 rounded-2xl border border-amber-500/40 bg-amber-500/10 p-5">
          <h2 className="text-lg font-bold text-amber-300">⚠️ El pago no pudo completarse</h2>
          <p className="text-sm text-amber-200/80 mt-1">
            No se confirmó el pago en la pasarela. Puedes volver al dashboard y reintentar más tarde.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <Link to="/dashboard" className="rounded-lg bg-slate-700 hover:bg-slate-600 px-3 py-2 text-sm font-semibold">← Volver al Dashboard</Link>
            <Link to="/planes" className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-3 py-2 text-sm font-semibold">Intentar de nuevo</Link>
          </div>
          {referenciaPago && <p className="text-xs text-amber-200/60 mt-2">Referencia: {referenciaPago}</p>}
        </div>
      )}

      {vencida && !esSuper && (
        <div className="mb-6 rounded-2xl border border-red-500/40 bg-red-500/10 p-5">
          <h2 className="text-lg font-bold text-red-300">⚠️ Tu membresía venció</h2>
          <p className="text-sm text-red-200/80 mt-1">
            Las funciones del POS están bloqueadas hasta que renueves tu plan.
            Elige un plan abajo y paga con PSE, Nequi o tarjeta — el acceso se
            reactiva automáticamente al confirmarse el pago.
          </p>
          {user?.facturacion_saas?.membresia_vence_at && (
            <p className="text-xs text-red-200/60 mt-2">
              Venció el {new Date(user.facturacion_saas.membresia_vence_at).toLocaleDateString('es-CO')}
            </p>
          )}
        </div>
      )}

      {limiteAlcanzado && !esSuper && (
        <div className="mb-6 rounded-2xl border border-amber-500/40 bg-amber-500/10 p-5">
          <h2 className="text-lg font-bold text-amber-300">🚧 Alcanzaste el límite de tu plan</h2>
          <p className="text-sm text-amber-200/80 mt-1">
            Ya usaste todo lo incluido en tu plan actual (clientes o citas). Para seguir
            registrando, elige un plan con más capacidad abajo y paga con PSE, Nequi o tarjeta.
          </p>
        </div>
      )}

      <div className="flex items-center justify-between mb-2">
        <h1 className="text-2xl font-bold">Planes de suscripción</h1>
        {esSuper && <button onClick={() => setEditando({ ...PLANTILLA, orden: planes.length })}
          className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Crear plan</button>}
      </div>
      <p className="text-slate-400 text-sm mb-6">{esSuper ? 'Edita precios, límites y funcionalidades de cada plan, o crea nuevos.' : 'Planes disponibles en la plataforma.'}</p>

      {cargando ? <p className="text-slate-500">Cargando…</p> : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {planes.map((p) => (
            <div key={p.id} className={`flex flex-col rounded-2xl border bg-slate-800/40 p-5 ${p.activo ? 'border-slate-800' : 'border-slate-800 opacity-60'}`}>
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-bold">{p.nombre}</h2>
                {user?.plan?.id === p.id
                  ? <span className="text-xs rounded-full bg-emerald-500/15 text-emerald-400 px-2 py-0.5">Tu plan</span>
                  : !p.activo && <span className="text-xs rounded-full bg-slate-600 px-2 py-0.5">Inactivo</span>}
              </div>
              <p className="text-3xl font-extrabold mt-2">{COP(p.precio_mensual)}<span className="text-sm font-normal text-slate-400">/mes</span></p>
              <p className="text-sm text-slate-400 mt-1">Hasta <span className="text-white font-semibold">{Number(p.limite_clientes).toLocaleString('es-CO')}</span> clientes</p>
              <ul className="mt-4 space-y-1 text-sm text-slate-300 flex-1">
                {(p.incluye ?? []).map((i, k) => <li key={k}>✓ {i}</li>)}
              </ul>
              {esSuper ? (
                <button onClick={() => setEditando(p)} className="mt-5 w-full rounded-lg bg-slate-700 hover:bg-slate-600 py-2 text-sm font-semibold">Editar plan</button>
              ) : p.activo && Number(p.precio_mensual) > 0 && (
                <button onClick={() => pagarPlan(p)} disabled={pagando === `plan-${p.id}`}
                  className="mt-5 w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 py-2 text-sm font-semibold">
                  {pagando === `plan-${p.id}` ? 'Abriendo pago…' : (user?.plan?.id === p.id ? 'Renovar (PSE / Nequi / Tarjeta)' : 'Pagar este plan')}
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Pago por uso: billetera y recargas ($500 COP por factura) */}
      {!esSuper && paquetes.length > 0 && (
        <div className="mt-10">
          <h2 className="text-xl font-bold mb-1">Pago por uso (billetera)</h2>
          <p className="text-slate-400 text-sm mb-4">
            ¿No quieres membresía mensual? Recarga saldo y paga <span className="text-white font-semibold">$500 COP por cada factura</span> que generes.
            Saldo actual: <span className="text-emerald-400 font-bold">{creditos.facturacion ?? 0} facturas disponibles</span>.
          </p>
          <div className="grid gap-4 md:grid-cols-3">
            {paquetes.map((pkg) => (
              <div key={pkg.id} className="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 flex flex-col">
                <h3 className="font-bold">{pkg.name}</h3>
                <p className="text-2xl font-extrabold mt-1">{COP(pkg.price_cop)}</p>
                <p className="text-sm text-slate-400 flex-1">{pkg.credits} facturas ({COP(Math.round(pkg.price_cop / pkg.credits))} c/u)</p>
                <button onClick={() => recargar(pkg)} disabled={pagando === `pkg-${pkg.id}`}
                  className="mt-4 rounded-lg bg-sky-600 hover:bg-sky-500 disabled:opacity-50 py-2 text-sm font-semibold">
                  {pagando === `pkg-${pkg.id}` ? 'Abriendo pago…' : 'Recargar'}
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {editando && <ModalPlan plan={editando} catalogo={catalogo} onClose={() => setEditando(null)} onGuardar={guardar} />}
    </div>
  )
}

function ModalPlan({ plan, catalogo, onClose, onGuardar }) {
  const [form, setForm] = useState({ ...plan, incluyeTexto: (plan.incluye ?? []).join('\n'), funcionalidades: plan.funcionalidades ?? [] })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const esNuevo = !plan.id

  function toggleFunc(clave) {
    setForm((f) => ({
      ...f,
      funcionalidades: f.funcionalidades.includes(clave)
        ? f.funcionalidades.filter((c) => c !== clave)
        : [...f.funcionalidades, clave],
    }))
  }

  function submit(e) {
    e.preventDefault()
    onGuardar({
      ...form,
      incluye: form.incluyeTexto.split('\n').map((s) => s.trim()).filter(Boolean),
    })
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold mb-4">{esNuevo ? 'Crear nuevo plan' : `Editar plan «${plan.nombre}»`}</h2>
        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm text-slate-300 col-span-2">Nombre
              <input value={form.nombre} onChange={set('nombre')} className="input mt-1" required placeholder="Ej: Premium" />
            </label>
            <label className="block text-sm text-slate-300">Precio mensual (COP)
              <input type="number" min="0" value={form.precio_mensual} onChange={set('precio_mensual')} className="input mt-1" required />
            </label>
            <label className="block text-sm text-slate-300">Límite de clientes
              <input type="number" min="1" value={form.limite_clientes} onChange={set('limite_clientes')} className="input mt-1" required />
            </label>
            <label className="block text-sm text-slate-300">Límite de citas
              <input type="number" min="1" value={form.limite_citas} onChange={set('limite_citas')} className="input mt-1" required />
            </label>
          </div>

          {/* Funcionalidades activadas por el plan */}
          <div>
            <p className="text-sm text-slate-300 mb-2">Funcionalidades activadas</p>
            <div className="grid grid-cols-2 gap-1.5 rounded-lg border border-slate-800 bg-slate-800/40 p-3 max-h-56 overflow-y-auto">
              {Object.entries(catalogo).map(([clave, label]) => (
                <label key={clave} className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                  <input type="checkbox" checked={form.funcionalidades.includes(clave)} onChange={() => toggleFunc(clave)}
                    className="accent-emerald-500" />
                  {label}
                </label>
              ))}
            </div>
          </div>

          <label className="block text-sm text-slate-300">Texto comercial (una línea por característica)
            <textarea value={form.incluyeTexto} onChange={set('incluyeTexto')} rows="4" className="input mt-1" placeholder="Facturación electrónica&#10;Inventario completo…" />
          </label>

          <div className="flex items-center gap-4">
            <label className="flex items-center gap-2 text-sm text-slate-300">
              <input type="checkbox" checked={form.activo} onChange={(e) => setForm({ ...form, activo: e.target.checked })} className="accent-emerald-500" />
              Plan activo (visible para usuarios)
            </label>
            <label className="text-sm text-slate-300 flex items-center gap-2">Orden
              <input type="number" value={form.orden} onChange={set('orden')} className="input mt-0 w-20" />
            </label>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button type="submit" className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">{esNuevo ? 'Crear plan' : 'Guardar'}</button>
          </div>
        </form>
      </div>
    </div>
  )
}
