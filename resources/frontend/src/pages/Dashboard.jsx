import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { QRCodeCanvas } from 'qrcode.react'
import { BarChart, Bar, PieChart, Pie, Cell, XAxis, YAxis, Tooltip, ResponsiveContainer, Legend } from 'recharts'
import { api, descargarArchivo } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { getReservasUrl, esUrlLocal } from '../utils/publicUrl'

const COLORES = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6']

// Panel permanente de cuenta: plan, consumo de clientes, QR y enlace de reservas.
function PanelCuenta({ cuenta, slug }) {
  const [copiado, setCopiado] = useState(false)
  const ref = useRef(null)
  const url = getReservasUrl(slug)
  const local = esUrlLocal()
  const mensaje = `¡Reserva tu cita en línea! 👉 ${url}`

  async function copiar() {
    await navigator.clipboard.writeText(url)
    setCopiado(true); setTimeout(() => setCopiado(false), 2000)
  }

  function descargarQR() {
    const canvas = ref.current?.querySelector('canvas')
    if (!canvas) return
    const a = document.createElement('a')
    a.href = canvas.toDataURL('image/png'); a.download = 'qr-reservas-fenix.png'; a.click()
  }

  function imprimirQR() {
    const canvas = ref.current?.querySelector('canvas')
    if (!canvas) return
    const img = canvas.toDataURL('image/png')
    const w = window.open('', '_blank')
    w.document.write(`<html><head><title>QR de reservas</title></head><body style="text-align:center;font-family:sans-serif;padding:40px"><h2>Reserva tu cita</h2><img src="${img}" style="width:320px;height:320px"/><p style="font-size:14px">${url}</p><script>window.onload=()=>window.print()</script></body></html>`)
    w.document.close()
  }

  function compartir() {
    if (navigator.share) navigator.share({ title: 'Reserva tu cita', text: mensaje, url }).catch(() => {})
    else window.open(`https://wa.me/?text=${encodeURIComponent(mensaje)}`, '_blank')
  }

  const ilimitado = cuenta?.clientes_limite == null
  const pct = cuenta?.porcentaje_uso ?? 0
  const colorBarra = pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500'

  return (
    <div className="grid gap-3 lg:grid-cols-3 mb-6">
      {/* Plan y consumo */}
      <div className="lg:col-span-2 rounded-xl border border-slate-800 bg-slate-800/50 p-4">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-slate-400 text-xs uppercase tracking-wide">Plan actual</p>
            <p className="text-xl font-bold">{cuenta?.plan ?? '—'} {cuenta?.es_super_admin && <span className="text-amber-400 text-sm">★ Super Admin</span>}</p>
          </div>
          <Link to="/planes" className="text-xs rounded-lg bg-blue-600 hover:bg-blue-500 px-3 py-1.5 font-semibold">Ver planes</Link>
        </div>
        {ilimitado ? (
          <p className="text-sm text-slate-400 mt-3">Clientes: <span className="text-white font-semibold">{cuenta?.clientes_usados ?? 0}</span> (sin límite)</p>
        ) : (
          <div className="mt-3">
            <div className="flex justify-between text-sm mb-1">
              <span className="text-slate-300">Clientes utilizados: <span className="font-semibold">{cuenta.clientes_usados} / {cuenta.clientes_limite}</span></span>
              <span className="text-slate-400">Disponibles: {cuenta.clientes_disponibles}</span>
            </div>
            <div className="h-2.5 w-full rounded-full bg-slate-700 overflow-hidden">
              <div className={`h-full ${colorBarra}`} style={{ width: `${Math.min(100, pct)}%` }} />
            </div>
            {pct >= 90 && <p className="text-xs text-red-400 mt-1">Estás cerca del límite. <Link to="/planes" className="underline">Actualiza tu plan</Link>.</p>}
          </div>
        )}
      </div>

      {/* Mi enlace de reservas (QR + acciones) */}
      <div className="rounded-xl border border-slate-800 bg-slate-800/50 p-4">
        <p className="text-slate-400 text-xs uppercase tracking-wide mb-2">Mi enlace de reservas</p>
        <div className="flex items-start gap-3">
          <div ref={ref} className="bg-white rounded-lg p-2 shrink-0">
            <QRCodeCanvas value={url} size={84} level="M" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-xs text-slate-400 break-all">{url}</p>
            {local && <p className="text-[11px] text-amber-400 mt-1">⚠️ Es una dirección local. Configúrala en <Link to="/qr" className="underline">QR Reservas</Link> para que funcione fuera del PC.</p>}
            <div className="flex flex-wrap gap-1.5 mt-2">
              <button onClick={copiar} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">{copiado ? '¡Copiado!' : 'Copiar'}</button>
              <button onClick={compartir} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Compartir</button>
              <button onClick={descargarQR} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Descargar</button>
              <button onClick={imprimirQR} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-2 py-1">Imprimir</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

// Banner para descargar el APK de Android directo desde el dashboard (sin
// pasar por la Play Store). Se oculta solo si el archivo aún no existe en
// el servidor (por ejemplo, antes de subir el primer build) para no dejar
// un botón roto a la vista.
const APK_URL = '/descargas/fenix.apk'
function AppMovilBanner() {
  const [disponible, setDisponible] = useState(false)

  useEffect(() => {
    fetch(APK_URL, { method: 'HEAD' })
      // Si el .apk todavía no existe, Laravel lo intercepta con la ruta de
      // respaldo del SPA y devuelve 200 con el HTML de la app — por eso no
      // basta con mirar el status, hay que descartar esa respuesta explícita.
      .then((r) => setDisponible(r.ok && !(r.headers.get('content-type') || '').includes('text/html')))
      .catch(() => setDisponible(false))
  }, [])

  if (!disponible) return null

  return (
    <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-orange-800/40 bg-orange-500/5 p-4">
      <div className="flex items-center gap-3">
        <span className="text-2xl">📱</span>
        <div>
          <p className="font-semibold">App de Fénix para Android</p>
          <p className="text-sm text-slate-400">Instálala en tu celular para trabajar desde ahí.</p>
        </div>
      </div>
      <a href={APK_URL} download="Fenix.apk"
        className="rounded-lg bg-gradient-to-r from-red-600 via-orange-600 to-amber-500 hover:opacity-90 px-4 py-2 text-sm font-semibold whitespace-nowrap">
        ⬇ Descargar APK
      </a>
    </div>
  )
}

const TONOS = {
  slate: 'bg-slate-500/10 text-slate-300',
  sky: 'bg-sky-500/10 text-sky-400',
  emerald: 'bg-emerald-500/10 text-emerald-400',
  amber: 'bg-amber-500/10 text-amber-400',
  violet: 'bg-violet-500/10 text-violet-400',
  red: 'bg-red-500/10 text-red-400',
}

const money = (n) => '$' + Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 })

// Tarjeta KPI con icono, acento de color y enlace opcional (estilo software empresarial).
function Kpi({ icon, titulo, valor, sub, tono = 'slate', to }) {
  const cuerpo = (
    <div className="flex items-center gap-3 rounded-2xl border border-slate-800 bg-slate-900/60 p-4 transition hover:border-slate-700">
      <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-xl ${TONOS[tono]}`}>{icon}</div>
      <div className="min-w-0">
        <p className="text-[11px] uppercase tracking-wide text-slate-400">{titulo}</p>
        <p className="truncate text-xl font-bold leading-tight">{valor}</p>
        {sub && <p className="text-[11px] text-slate-500">{sub}</p>}
      </div>
    </div>
  )
  return to ? <Link to={to} className="block">{cuerpo}</Link> : cuerpo
}

function Seccion({ titulo, children }) {
  return (
    <div className="mb-6">
      <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">{titulo}</h2>
      {children}
    </div>
  )
}

export default function Dashboard() {
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [descargando, setDescargando] = useState(false)

  useEffect(() => {
    api('/reportes/dashboard').then(setData).catch(() => {})
  }, [])

  async function exportar() {
    setDescargando(true)
    try {
      await descargarArchivo('/reportes/inventario/excel', 'inventario.xlsx')
    } finally {
      setDescargando(false)
    }
  }

  const r = data?.resumen
  const stockBodega = (data?.stock_por_bodega ?? []).map((s) => ({ nombre: s.nombre, cantidad: Number(s.cantidad) }))
  const rotacion = (data?.top_rotacion ?? []).map((t) => ({ nombre: t.nombre, total: Number(t.total) }))

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold">Hola, {user?.name} 👋</h1>
          <p className="text-slate-400 text-sm">Rol: <span className="text-emerald-400">{user?.rol?.nombre ?? 'Sin rol'}</span></p>
        </div>
        <button onClick={exportar} disabled={descargando}
          className="rounded-lg bg-sky-600 hover:bg-sky-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
          {descargando ? 'Generando…' : '⬇ Exportar Excel'}
        </button>
      </div>

      {!data ? (
        <p className="text-slate-500">Cargando indicadores…</p>
      ) : (
        <>
          <AppMovilBanner />
          {data.cuenta && <PanelCuenta cuenta={data.cuenta} slug={user?.reservas_slug} />}
          <Seccion titulo="Resumen de hoy">
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
              <Kpi icon="📅" tono="sky" titulo="Citas hoy" valor={r.citas_hoy ?? 0} to="/agenda" />
              <Kpi icon="⏳" tono="amber" titulo="Citas pendientes" valor={r.citas_pendientes ?? 0} to="/agenda" />
              <Kpi icon="📊" tono="violet" titulo="Ocupación agenda" valor={`${r.ocupacion_pct ?? 0}%`} sub="del horario laboral de hoy" />
              <Kpi icon="🧾" tono="emerald" titulo="Facturación hoy" valor={money(r.facturacion_hoy)} to="/facturacion" />
            </div>
          </Seccion>

          <Seccion titulo="Clientes y ventas">
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
              <Kpi icon="👥" tono="sky" titulo="Clientes activos" valor={r.clientes_activos ?? 0} to="/clientes" />
              <Kpi icon="✨" tono="violet" titulo="Nuevos este mes" valor={r.clientes_nuevos_mes ?? 0} to="/clientes" />
              <Kpi icon="💰" tono="emerald" titulo="Facturación del mes" valor={money(r.facturacion_mes)} to="/facturacion" />
              <Kpi icon="🚚" tono="slate" titulo="Proveedores" valor={r.proveedores} to="/proveedores" />
            </div>
          </Seccion>

          <Seccion titulo="Inventario">
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
              <Kpi icon="📦" tono="sky" titulo="Productos" valor={r.productos} to="/productos" />
              <Kpi icon="🏭" tono="slate" titulo="Bodegas" valor={r.bodegas} to="/bodegas" />
              <Kpi icon="🏷️" tono="emerald" titulo="Valor inventario" valor={money(r.valor_inventario)} to="/inventario" />
              <Kpi icon={r.alertas > 0 ? '⚠️' : '✅'} tono={r.alertas > 0 ? 'red' : 'emerald'} titulo="Alertas de stock"
                valor={r.alertas} sub={r.alertas > 0 ? 'productos por reponer' : 'todo en orden'} to="/inventario" />
            </div>
          </Seccion>

          <div className="grid lg:grid-cols-2 gap-6">
            <div className="rounded-xl border border-slate-800 bg-slate-800/50 p-4">
              <h2 className="font-semibold mb-4">Stock por bodega</h2>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={stockBodega}>
                  <XAxis dataKey="nombre" stroke="#94a3b8" fontSize={12} />
                  <YAxis stroke="#94a3b8" fontSize={12} />
                  <Tooltip contentStyle={{ background: '#1e293b', border: 'none', borderRadius: 8 }} />
                  <Bar dataKey="cantidad" fill="#10b981" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-800/50 p-4">
              <h2 className="font-semibold mb-4">Productos con mayor rotación</h2>
              {rotacion.length === 0 ? (
                <p className="text-slate-500 text-sm">Aún no hay salidas registradas.</p>
              ) : (
                <ResponsiveContainer width="100%" height={260}>
                  <PieChart>
                    <Pie data={rotacion} dataKey="total" nameKey="nombre" cx="50%" cy="50%" outerRadius={90} label>
                      {rotacion.map((_, i) => <Cell key={i} fill={COLORES[i % COLORES.length]} />)}
                    </Pie>
                    <Legend />
                    <Tooltip contentStyle={{ background: '#1e293b', border: 'none', borderRadius: 8 }} />
                  </PieChart>
                </ResponsiveContainer>
              )}
            </div>
          </div>

          {(data.mas_vendidos?.length ?? 0) > 0 && (
            <div className="mt-6 rounded-2xl border border-slate-800 bg-slate-900/60 p-4">
              <h2 className="font-semibold mb-4">Productos y servicios más vendidos</h2>
              <ul className="space-y-2">
                {data.mas_vendidos.map((m, i) => {
                  const max = Number(data.mas_vendidos[0].total) || 1
                  const pct = (Number(m.total) / max) * 100
                  return (
                    <li key={i} className="flex items-center gap-3 text-sm">
                      <span className="w-5 shrink-0 text-slate-500">{i + 1}.</span>
                      <span className="w-40 shrink-0 truncate sm:w-56">{m.descripcion}</span>
                      <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-800">
                        <div className="h-full rounded-full bg-emerald-500" style={{ width: `${pct}%` }} />
                      </div>
                      <span className="w-16 shrink-0 text-right font-medium">{Number(m.total).toLocaleString('es-CO')}</span>
                    </li>
                  )
                })}
              </ul>
            </div>
          )}
        </>
      )}
    </div>
  )
}
