import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { aNumero } from '../utils/numero'

const COP = (n) => '$' + Number(n ?? 0).toLocaleString('es-CO')

const ESTADOS = {
  recibido: { label: 'Recibido', clase: 'bg-sky-500/15 text-sky-300' },
  en_proceso: { label: 'En Proceso', clase: 'bg-amber-500/15 text-amber-300' },
  secando: { label: 'Secando', clase: 'bg-orange-500/15 text-orange-300' },
  listo: { label: 'Listo', clase: 'bg-emerald-500/15 text-emerald-300' },
  facturado: { label: 'Facturado', clase: 'bg-violet-500/15 text-violet-300' },
  cancelado: { label: 'Cancelado', clase: 'bg-red-500/15 text-red-300' },
}

// Kanban del lavadero: En espera → Lavando → Secando → Listo.
const KANBAN_LAVADERO = [
  { valor: 'recibido', etiqueta: 'En espera', clase: 'border-sky-600/50 bg-sky-500/5' },
  { valor: 'en_proceso', etiqueta: 'Lavando', clase: 'border-amber-600/50 bg-amber-500/5' },
  { valor: 'secando', etiqueta: 'Secando', clase: 'border-orange-600/50 bg-orange-500/5' },
  { valor: 'listo', etiqueta: 'Listo', clase: 'border-emerald-600/50 bg-emerald-500/5' },
]

const Chip = ({ estado }) => (
  <span className={`text-xs rounded-full px-2 py-0.5 whitespace-nowrap ${ESTADOS[estado]?.clase ?? 'bg-slate-700'}`}>
    {ESTADOS[estado]?.label ?? estado}
  </span>
)

// Ícono según el tipo de vehículo del activo (🏍️ moto, 🚗 carro/otro).
const iconoVehiculo = (tipoActivo) => (tipoActivo === 'moto' ? '🏍️' : '🚗')

// Textos/labels que cambian según el tipo de negocio (misma pantalla, distinto rubro).
const CONFIG_POR_TIPO = {
  lavadero: {
    titulo: 'Servicios de Lavado', tabOrdenes: '🧼 Órdenes de Lavado', tabEmpleados: '🧼 Lavadores',
    subtitulo: 'Órdenes de lavado, planes contratados y equipo de lavadores.',
    mostrarVehiculos: true, iconoOperario: '🧼',
  },
  barberia: {
    titulo: 'Órdenes', tabOrdenes: '💈 Órdenes de Barbería', tabEmpleados: '💈 Barberos',
    subtitulo: 'Órdenes de servicio, cortes contratados y equipo de barberos.',
    mostrarVehiculos: false, iconoOperario: '💈',
  },
  tatuajes: {
    titulo: 'Sesiones', tabOrdenes: '🎨 Sesiones de Tatuaje', tabEmpleados: '🎨 Artistas',
    subtitulo: 'Sesiones agendadas, referencias de los clientes y portafolio de tus artistas.',
    mostrarVehiculos: false, iconoOperario: '🎨',
  },
  accesorios_motos: {
    titulo: 'Órdenes', tabOrdenes: '🔧 Órdenes de Instalación', tabEmpleados: '🔧 Instaladores',
    subtitulo: 'Órdenes de instalación de accesorios y equipo de instaladores.',
    mostrarVehiculos: true, iconoOperario: '🔧',
  },
}
const config = (tipoNegocio) => CONFIG_POR_TIPO[tipoNegocio] ?? {
  titulo: 'Taller', tabOrdenes: '🔧 Órdenes de Servicio', tabEmpleados: '👨‍🔧 Empleados del Taller',
  subtitulo: 'Órdenes de servicio, hoja de vida de vehículos y equipo de mecánicos.',
  mostrarVehiculos: true, iconoOperario: '👨‍🔧',
}

export default function Taller() {
  const { user } = useAuth()
  const esMecanico = user?.rol?.nombre === 'Mecanico'
  const esLavadorRol = user?.rol?.nombre === 'Lavador'
  const esInstaladorRol = user?.rol?.nombre === 'Instalador'
  const esOperario = esMecanico || esLavadorRol || esInstaladorRol
  const tipoNegocio = user?.empresa_info?.tipo_negocio?.clave
  const esLavadero = tipoNegocio === 'lavadero'
  const esBarberia = tipoNegocio === 'barberia'
  const esTatuajes = tipoNegocio === 'tatuajes'
  const esAccesoriosMotos = tipoNegocio === 'accesorios_motos'
  const cfg = config(tipoNegocio)
  const [tab, setTab] = useState('ordenes')

  const tabs = [
    { id: 'ordenes', label: cfg.tabOrdenes },
    ...(!esOperario ? [
      ...(cfg.mostrarVehiculos ? [{ id: 'vehiculos', label: '🏍️ Vehículos / Activos' }] : []),
      { id: 'empleados', label: cfg.tabEmpleados },
    ] : []),
  ]

  return (
    <div>
      <h1 className="text-2xl font-bold mb-1">{cfg.titulo}</h1>
      <p className="text-slate-400 text-sm mb-5">
        {esOperario ? 'Tus órdenes asignadas: registra el trabajo realizado y los repuestos usados.' : cfg.subtitulo}
      </p>

      <div className="flex gap-2 mb-6 flex-wrap">
        {tabs.map((t) => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`rounded-lg px-4 py-2 text-sm font-semibold transition ${tab === t.id ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>
            {t.label}
          </button>
        ))}
        {!esOperario && <BuscadorHistorial mostrarVehiculos={cfg.mostrarVehiculos} />}
      </div>

      {tab === 'ordenes' && <Ordenes esMecanico={esOperario} esLavadero={esLavadero} esBarberia={esBarberia || esTatuajes} iconoOperario={cfg.iconoOperario} />}
      {tab === 'vehiculos' && !esOperario && cfg.mostrarVehiculos && <Vehiculos />}
      {tab === 'empleados' && !esOperario && <Empleados esLavadero={esLavadero} esBarberia={esBarberia} esTatuajes={esTatuajes} esAccesoriosMotos={esAccesoriosMotos} />}
    </div>
  )
}

/* ============ Fidelización: historial por placa o cédula (o solo cédula si el negocio no maneja vehículos) ============ */
function BuscadorHistorial({ mostrarVehiculos }) {
  const [q, setQ] = useState('')
  const [datos, setDatos] = useState(null)
  const [error, setError] = useState('')

  async function buscar(e) {
    e.preventDefault()
    if (!q.trim()) return
    setError('')
    try { setDatos(await api(`/pos/historial-cliente?q=${encodeURIComponent(q.trim())}`)) }
    catch (err) { setError(err.message || 'No encontrado.') }
  }

  return (
    <>
      <form onSubmit={buscar} className="ml-auto flex gap-2">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={mostrarVehiculos ? 'Historial: placa o cédula…' : 'Historial: cédula…'}
          className="input !mt-0 w-48 sm:w-56" />
        <button className="rounded-lg bg-slate-700 hover:bg-slate-600 px-3 py-2 text-sm">🔎</button>
      </form>
      {error && <p className="w-full text-red-400 text-sm">{error}</p>}
      {datos && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={() => setDatos(null)}>
          <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-start justify-between">
              <div>
                <h2 className="text-lg font-bold">{datos.cliente.nombre_completo}</h2>
                <p className="text-sm text-slate-400">
                  {datos.cliente.tipo_documento} {datos.cliente.numero_documento} · {datos.cliente.telefono}
                  {datos.vehiculo && <> · {datos.vehiculo.marca} {datos.vehiculo.modelo} ({datos.vehiculo.placa_identificador})</>}
                </p>
              </div>
              <button onClick={() => setDatos(null)} className="text-slate-400 hover:text-white text-xl">✕</button>
            </div>

            <div className="grid grid-cols-3 gap-3 my-4">
              <div className="rounded-xl bg-slate-800/60 p-3 text-center">
                <p className="text-2xl font-extrabold">{datos.visitas}</p><p className="text-xs text-slate-400">Visitas al taller</p>
              </div>
              <div className="rounded-xl bg-slate-800/60 p-3 text-center">
                <p className="text-2xl font-extrabold">{COP(datos.total_comprado)}</p><p className="text-xs text-slate-400">Total comprado</p>
              </div>
              <div className="rounded-xl bg-slate-800/60 p-3 text-center">
                <p className="text-sm font-semibold mt-1">{datos.mecanicos.map((m) => m.nombre).join(', ') || '—'}</p>
                <p className="text-xs text-slate-400">Mecánicos que lo atendieron</p>
              </div>
            </div>

            <h3 className="font-semibold text-sm mb-2">Últimas órdenes</h3>
            {datos.ordenes.length === 0 && <p className="text-slate-500 text-sm">Sin órdenes registradas.</p>}
            {datos.ordenes.map((o) => (
              <div key={o.id} className="flex items-center justify-between border-b border-slate-800 py-2 text-sm">
                <span>{o.numero_orden} · {o.asset_vehicle?.placa_identificador ?? ''}</span>
                <span className="text-slate-400">{new Date(o.created_at).toLocaleDateString('es-CO')}</span>
                <Chip estado={o.estado} />
                <span className="font-semibold">{COP(o.total)}</span>
              </div>
            ))}

            <h3 className="font-semibold text-sm mt-4 mb-2">Últimas facturas</h3>
            {datos.facturas.length === 0 && <p className="text-slate-500 text-sm">Sin facturas.</p>}
            {datos.facturas.map((f) => (
              <div key={f.id} className="flex items-center justify-between border-b border-slate-800 py-2 text-sm">
                <span>{f.numero}</span>
                <span className="text-slate-400">{new Date(f.fecha).toLocaleDateString('es-CO')}</span>
                <span className="font-semibold">{COP(f.total)}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </>
  )
}

/* ============ Órdenes de servicio ============ */
// esMecanico aquí significa "operario limitado" (rol Mecanico o Lavador): solo ve lo suyo, sin precios.
function Ordenes({ esMecanico, esLavadero, esBarberia, iconoOperario = '👨‍🔧' }) {
  const [ordenes, setOrdenes] = useState([])
  const [estado, setEstado] = useState('')
  const [buscar, setBuscar] = useState('')
  const [cargando, setCargando] = useState(true)
  const [creando, setCreando] = useState(false)
  const [abierta, setAbierta] = useState(null) // orden en detalle
  // El lavadero ve un Kanban por defecto; puede alternar a la lista clásica.
  const [vista, setVista] = useState(esLavadero ? 'kanban' : 'lista')

  const cargar = useCallback(async () => {
    setCargando(true)
    try {
      const p = new URLSearchParams()
      if (estado) p.set('estado', estado)
      if (buscar) p.set('buscar', buscar)
      const r = await api(`/ordenes-servicio?${p}`)
      setOrdenes(r.data ?? [])
    } finally { setCargando(false) }
  }, [estado, buscar])

  useEffect(() => { cargar() }, [cargar])

  async function avanzarEstado(orden, nuevoEstado) {
    try {
      await api(`/ordenes-servicio/${orden.id}`, { method: 'PUT', body: { estado: nuevoEstado } })
      cargar()
    } catch (err) { alert(err.message || 'No se pudo actualizar el estado.') }
  }

  return (
    <div>
      <div className="flex flex-wrap gap-2 mb-4">
        {esLavadero && (
          <div className="flex bg-slate-800 rounded-lg p-1">
            {[['kanban', '📋 Tablero'], ['lista', '📄 Lista']].map(([v, label]) => (
              <button key={v} onClick={() => setVista(v)}
                className={`px-3 py-1.5 rounded text-sm font-medium transition ${vista === v ? 'bg-emerald-600 text-white' : 'text-slate-300'}`}>
                {label}
              </button>
            ))}
          </div>
        )}
        {vista === 'lista' && (
          <select value={estado} onChange={(e) => setEstado(e.target.value)} className="input !mt-0 w-44">
            <option value="">Todos los estados</option>
            {Object.entries(ESTADOS).map(([v, e]) => <option key={v} value={v}>{e.label}</option>)}
          </select>
        )}
        <input value={buscar} onChange={(e) => setBuscar(e.target.value)} placeholder={esBarberia ? 'Buscar orden, cliente…' : 'Buscar orden, cliente, placa…'} className="input !mt-0 w-56" />
        {!esMecanico && (
          <button onClick={() => setCreando(true)}
            className="ml-auto rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nueva orden</button>
        )}
      </div>

      {cargando ? <p className="text-slate-500">Cargando…</p> : ordenes.length === 0 ? (
        <p className="text-slate-500 py-8 text-center">No hay órdenes de servicio{esMecanico ? ' asignadas a ti' : ''}.</p>
      ) : vista === 'kanban' ? (
        <KanbanLavadero ordenes={ordenes} onAvanzar={avanzarEstado} onAbrir={setAbierta} />
      ) : (
        <div className="grid gap-3">
          {ordenes.map((o) => (
            <button key={o.id} onClick={() => setAbierta(o.id)}
              className="text-left rounded-xl border border-slate-800 bg-slate-800/40 hover:bg-slate-800/70 p-4 transition">
              <div className="flex flex-wrap items-center gap-3">
                <span className="font-bold">{o.numero_orden}</span>
                <Chip estado={o.estado} />
                <span className="text-sm text-slate-300">{o.cliente?.nombre_completo}</span>
                {o.asset_vehicle && (
                  <span className="text-sm text-slate-400">{iconoVehiculo(o.asset_vehicle.tipo_activo)} {o.asset_vehicle.marca} {o.asset_vehicle.modelo} · {o.asset_vehicle.placa_identificador}</span>
                )}
                {o.plan_lavado && <span className="text-sm text-slate-400">🧼 {o.plan_lavado.nombre}</span>}
                {o.servicio && <span className="text-sm text-slate-400">💈 {o.servicio.nombre}</span>}
                {o.mecanico_asignado && (
                  <span className="text-sm text-slate-400">{iconoOperario} {o.mecanico_asignado.nombre} {o.mecanico_asignado.apellido}</span>
                )}
                {!esMecanico && <span className="ml-auto font-semibold">{COP(o.total)}</span>}
              </div>
              {o.descripcion_trabajo && <p className="text-sm text-slate-400 mt-1 line-clamp-1">{o.descripcion_trabajo}</p>}
            </button>
          ))}
        </div>
      )}

      {creando && <ModalCrearOrden onClose={() => setCreando(false)} onCreada={(id) => { setCreando(false); cargar(); setAbierta(id) }} />}
      {abierta && <ModalOrden id={abierta} esMecanico={esMecanico} onClose={() => { setAbierta(null); cargar() }} />}
    </div>
  )
}

/** Tablero Kanban del lavadero: En espera → Lavando → Secando → Listo. */
function KanbanLavadero({ ordenes, onAvanzar, onAbrir }) {
  const columnas = KANBAN_LAVADERO.map((col) => ({
    ...col,
    ordenes: ordenes.filter((o) => o.estado === col.valor),
  }))

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      {columnas.map((col, ci) => (
        <div key={col.valor} className={`rounded-2xl border p-3 ${col.clase}`}>
          <h3 className="font-bold text-sm mb-3 flex items-center justify-between">
            {col.etiqueta} <span className="text-xs text-slate-500 font-normal">{col.ordenes.length}</span>
          </h3>
          <div className="space-y-2 min-h-[80px]">
            {col.ordenes.map((o) => (
              <div key={o.id} className="rounded-lg bg-slate-900/70 border border-slate-800 p-3">
                <button onClick={() => onAbrir(o.id)} className="text-left w-full">
                  <p className="font-semibold text-sm">{o.numero_orden}</p>
                  <p className="text-xs text-slate-400">{o.cliente?.nombre_completo}</p>
                  {o.asset_vehicle && (
                    <p className="text-xs text-slate-400">{iconoVehiculo(o.asset_vehicle.tipo_activo)} {o.asset_vehicle.placa_identificador ?? '—'}</p>
                  )}
                  {o.plan_lavado && <p className="text-xs text-slate-400">🧼 {o.plan_lavado.nombre}</p>}
                </button>
                {KANBAN_LAVADERO[ci + 1] && (
                  <button onClick={() => onAvanzar(o, KANBAN_LAVADERO[ci + 1].valor)}
                    className="mt-2 w-full text-xs rounded-lg bg-slate-800 hover:bg-emerald-700 py-1.5 font-medium transition">
                    → {KANBAN_LAVADERO[ci + 1].etiqueta}
                  </button>
                )}
              </div>
            ))}
            {col.ordenes.length === 0 && <p className="text-xs text-slate-600 text-center py-4">Vacío</p>}
          </div>
        </div>
      ))}
    </div>
  )
}

function ModalCrearOrden({ onClose, onCreada }) {
  const { user } = useAuth()
  // El formulario se adapta al tipo de negocio de la empresa.
  const tipoNegocio = user?.empresa_info?.tipo_negocio?.clave ?? ''
  // Accesorios de motos: mismo tratamiento que taller_motos (vehículo
  // obligatorio + checklist de entrada), la moto es la del cliente que
  // recibe la instalación.
  const esTallerVehiculos = ['taller_motos', 'taller_carros', 'accesorios_motos'].includes(tipoNegocio)
  const esServicioTecnico = ['taller_general', 'otro'].includes(tipoNegocio)
  const esLavadero = tipoNegocio === 'lavadero'
  const esBarberia = tipoNegocio === 'barberia'
  const esTatuajes = tipoNegocio === 'tatuajes'
  // El servicio se elige del catálogo (no de un dropdown de "servicio de barbería"
  // codificado a mano): aplica a cualquier negocio guiado por catálogo, no solo barbería.
  const usaCatalogoServicios = esBarberia || esTatuajes
  // El vehículo es obligatorio en talleres y lavadero; barbería/tatuajes no manejan vehículos.
  const vehiculoObligatorio = esTallerVehiculos || esServicioTecnico || esLavadero
  const mostrarVehiculo = !usaCatalogoServicios

  const [resultados, setResultados] = useState([])
  const [buscarCliente, setBuscarCliente] = useState('')
  const [clienteSel, setClienteSel] = useState(null) // cliente elegido (objeto completo, no depende de la lista)
  const [vehiculos, setVehiculos] = useState([])
  const [empleados, setEmpleados] = useState([])
  const [planes, setPlanes] = useState([])
  const [servicios, setServicios] = useState([])
  const [form, setForm] = useState({ asset_vehicle_id: '', operables_employee_id: '', plan_lavado_id: '', servicio_id: '', descripcion_trabajo: '', fecha_entrega_estimada: '', km_entrada: '', nivel_gasolina: '', accesorios: '' })
  const [nuevoVehiculo, setNuevoVehiculo] = useState(null) // {placa, marca, modelo}
  // Checklist de entrada (talleres y lavadero): estado visual del vehículo al recibirlo.
  const [checklist, setChecklist] = useState(
    (esTallerVehiculos || esLavadero)
      ? ['Espejos', 'Rines / llantas', 'Luces', 'Rayones visibles', 'Papeles del vehículo', 'Objetos de valor'].map((item) => ({ item, ok: true }))
      : []
  )
  const [guardando, setGuardando] = useState(false)
  const [errorCarga, setErrorCarga] = useState('')

  useEffect(() => {
    api('/empleados').then((r) => setEmpleados(r.data ?? [])).catch(() => {})
    if (esLavadero) api('/planes-lavado').then(setPlanes).catch(() => {})
    if (usaCatalogoServicios) api('/servicios').then(setServicios).catch(() => {})
  }, [esLavadero, usaCatalogoServicios])

  // Búsqueda reactiva: los resultados son una lista clicable, y el cliente
  // elegido queda fijado aparte (no se pierde al seguir escribiendo).
  useEffect(() => {
    const t = setTimeout(() => {
      api(`/clientes?buscar=${encodeURIComponent(buscarCliente)}`)
        .then((r) => { setResultados(r.data ?? r ?? []); setErrorCarga('') })
        .catch((e) => setErrorCarga(e.message || 'No se pudieron cargar los clientes.'))
    }, 300)
    return () => clearTimeout(t)
  }, [buscarCliente])

  useEffect(() => {
    if (!mostrarVehiculo || !clienteSel?.id) { setVehiculos([]); return }
    api(`/activos?cliente_id=${clienteSel.id}`)
      .then((r) => setVehiculos(r.data ?? []))
      .catch((e) => setErrorCarga(e.message || 'No se pudieron cargar los vehículos.'))
  }, [clienteSel, mostrarVehiculo])

  async function crear(e) {
    e.preventDefault()
    if (!clienteSel?.id) return alert('Selecciona el cliente.')
    if (vehiculoObligatorio && !form.asset_vehicle_id && !nuevoVehiculo) {
      return alert('Este tipo de negocio requiere el vehículo/equipo del cliente.')
    }
    setGuardando(true)
    try {
      let vehiculoId = form.asset_vehicle_id || null

      // Registrar el vehículo nuevo sobre la marcha si el usuario llenó el mini-formulario.
      if (nuevoVehiculo?.marca && nuevoVehiculo?.modelo) {
        const v = await api('/activos', { method: 'POST', body: {
          cliente_id: Number(clienteSel.id), tipo_activo: nuevoVehiculo.tipo || 'moto',
          placa_identificador: nuevoVehiculo.placa || null, marca: nuevoVehiculo.marca, modelo: nuevoVehiculo.modelo,
        } })
        vehiculoId = v.id
      }

      const orden = await api('/ordenes-servicio', { method: 'POST', body: {
        cliente_id: Number(clienteSel.id),
        asset_vehicle_id: vehiculoId ? Number(vehiculoId) : null,
        operables_employee_id: form.operables_employee_id ? Number(form.operables_employee_id) : null,
        plan_lavado_id: form.plan_lavado_id ? Number(form.plan_lavado_id) : null,
        servicio_id: form.servicio_id ? Number(form.servicio_id) : null,
        descripcion_trabajo: form.descripcion_trabajo || null,
        fecha_entrega_estimada: form.fecha_entrega_estimada || null,
        km_entrada: form.km_entrada ? aNumero(form.km_entrada) : null,
        nivel_gasolina: form.nivel_gasolina !== '' ? Number(form.nivel_gasolina) : null,
        accesorios: form.accesorios || null,
        checklist_entrada: checklist.length > 0 ? checklist : null,
      } })
      onCreada(orden.id)
    } catch (err) {
      alert(err.message || 'No se pudo crear la orden.')
    } finally { setGuardando(false) }
  }

  const toggleChecklist = (idx) => setChecklist((c) => c.map((it, i) => (i === idx ? { ...it, ok: !it.ok } : it)))

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold mb-4">{esBarberia ? 'Nueva orden de barbería' : esTatuajes ? 'Nueva sesión de tatuaje' : 'Nueva orden de servicio'}</h2>
        <form onSubmit={crear} className="space-y-3">
          {errorCarga && <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{errorCarga}</div>}

          {/* Cliente: buscador con resultados clicables; el elegido queda fijado */}
          {clienteSel ? (
            <div className="flex items-center justify-between rounded-lg border border-emerald-600/50 bg-emerald-500/10 px-3 py-2.5">
              <div className="text-sm">
                <p className="font-semibold">👤 {clienteSel.nombre_completo}</p>
                <p className="text-xs text-slate-400">{clienteSel.numero_documento ?? ''} {clienteSel.telefono ? `· ${clienteSel.telefono}` : ''}</p>
              </div>
              <button type="button" onClick={() => { setClienteSel(null); setForm({ ...form, asset_vehicle_id: '' }); setNuevoVehiculo(null) }}
                className="text-xs text-slate-400 hover:text-white">Cambiar</button>
            </div>
          ) : (
            <label className="block text-sm text-slate-300">Cliente * (escribe para buscar)
              <input value={buscarCliente} onChange={(e) => setBuscarCliente(e.target.value)} placeholder="Nombre, cédula, teléfono…" className="input mt-1" autoFocus />
              <div className="mt-1 max-h-40 overflow-y-auto rounded-lg border border-slate-700 divide-y divide-slate-800">
                {resultados.length === 0 && <p className="px-3 py-2 text-xs text-slate-500">Sin resultados. Crea el cliente primero en el módulo Clientes.</p>}
                {resultados.map((c) => (
                  <button type="button" key={c.id} onClick={() => { setClienteSel(c); setBuscarCliente('') }}
                    className="w-full text-left px-3 py-2 text-sm hover:bg-slate-800">
                    {c.nombre_completo} <span className="text-xs text-slate-500">{c.numero_documento ?? ''}</span>
                  </button>
                ))}
              </div>
            </label>
          )}

          {mostrarVehiculo && (
            <>
              <label className="block text-sm text-slate-300">Vehículo / equipo del cliente{vehiculoObligatorio && ' *'}
                <select value={form.asset_vehicle_id} onChange={set('asset_vehicle_id')} className="input mt-1"
                  disabled={!!nuevoVehiculo || !clienteSel} required={vehiculoObligatorio && !nuevoVehiculo}>
                  <option value="">{clienteSel ? (vehiculos.length ? '— Sin vehículo —' : 'Este cliente no tiene vehículos registrados') : 'Primero elige el cliente'}</option>
                  {vehiculos.map((v) => <option key={v.id} value={v.id}>{v.marca} {v.modelo} · {v.placa_identificador ?? 's/placa'}</option>)}
                </select>
              </label>

              {nuevoVehiculo ? (
                <div className="rounded-lg border border-slate-700 p-3 space-y-2">
                  <div className="flex items-center justify-between">
                    <p className="text-sm font-semibold">Nuevo vehículo</p>
                    <button type="button" onClick={() => setNuevoVehiculo(null)} className="text-xs text-slate-400 hover:text-white">Cancelar</button>
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <input placeholder="Placa" value={nuevoVehiculo.placa ?? ''} onChange={(e) => setNuevoVehiculo({ ...nuevoVehiculo, placa: e.target.value.toUpperCase() })} className="input !mt-0" />
                    <select value={nuevoVehiculo.tipo ?? 'moto'} onChange={(e) => setNuevoVehiculo({ ...nuevoVehiculo, tipo: e.target.value })} className="input !mt-0">
                      <option value="moto">Moto</option><option value="auto">Auto</option><option value="celular">Celular</option><option value="otro">Otro</option>
                    </select>
                    <input placeholder="Marca *" value={nuevoVehiculo.marca ?? ''} onChange={(e) => setNuevoVehiculo({ ...nuevoVehiculo, marca: e.target.value })} className="input !mt-0" />
                    <input placeholder="Modelo *" value={nuevoVehiculo.modelo ?? ''} onChange={(e) => setNuevoVehiculo({ ...nuevoVehiculo, modelo: e.target.value })} className="input !mt-0" />
                  </div>
                </div>
              ) : (
                <button type="button" onClick={() => setNuevoVehiculo({ tipo: 'moto' })} disabled={!clienteSel}
                  className="text-sm text-emerald-400 hover:text-emerald-300 disabled:opacity-40">+ Registrar vehículo nuevo</button>
              )}
            </>
          )}

          {esLavadero && (
            <label className="block text-sm text-slate-300">Plan de lavado
              <select value={form.plan_lavado_id} onChange={set('plan_lavado_id')} className="input mt-1">
                <option value="">— Sin plan —</option>
                {planes.filter((p) => p.activo).map((p) => (
                  <option key={p.id} value={p.id}>{p.icono ? `${p.icono} ` : ''}{p.nombre} · {p.duracion_min} min · ${Number(p.precio).toLocaleString()}</option>
                ))}
              </select>
            </label>
          )}

          {usaCatalogoServicios && (
            <label className="block text-sm text-slate-300">{esTatuajes ? 'Servicio del catálogo' : 'Tipo de corte / servicio'}
              <select value={form.servicio_id} onChange={set('servicio_id')} className="input mt-1">
                <option value="">— Sin servicio —</option>
                {servicios.filter((s) => s.activo).map((s) => (
                  <option key={s.id} value={s.id}>{s.nombre} · ${Number(s.precio).toLocaleString()}</option>
                ))}
              </select>
            </label>
          )}

          <label className="block text-sm text-slate-300">{esLavadero ? 'Lavador asignado' : esBarberia ? 'Barbero / estilista asignado' : esTatuajes ? 'Artista asignado' : 'Mecánico / técnico asignado'}
            <select value={form.operables_employee_id} onChange={set('operables_employee_id')} className="input mt-1">
              <option value="">— Sin asignar —</option>
              {empleados.map((m) => <option key={m.id} value={m.id}>{m.nombre} {m.apellido}</option>)}
            </select>
          </label>

          {/* Campos según el tipo de negocio */}
          {esTallerVehiculos && (
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm text-slate-300">Kilometraje actual
                <input type="text" inputMode="numeric" value={form.km_entrada} onChange={set('km_entrada')} className="input mt-1" placeholder="Ej: 45.300" />
              </label>
              <label className="block text-sm text-slate-300">Nivel de gasolina: <b>{form.nivel_gasolina === '' ? '—' : `${form.nivel_gasolina}%`}</b>
                <input type="range" min="0" max="100" step="5" value={form.nivel_gasolina === '' ? 50 : form.nivel_gasolina}
                  onChange={set('nivel_gasolina')} className="mt-3 w-full accent-emerald-500" />
              </label>
            </div>
          )}
          {esServicioTecnico && (
            <label className="block text-sm text-slate-300">Accesorios con los que se recibe
              <input value={form.accesorios} onChange={set('accesorios')} className="input mt-1" placeholder="Ej: cargador, estuche, cable USB…" />
            </label>
          )}

          {/* Checklist de entrada: estado visual del vehículo al recibirlo (talleres/lavadero) */}
          {checklist.length > 0 && (
            <div>
              <p className="text-sm text-slate-300 mb-1.5">Checklist de entrada</p>
              <div className="grid grid-cols-2 gap-1.5 rounded-lg border border-slate-700 bg-slate-800/30 p-3">
                {checklist.map((it, i) => (
                  <label key={it.item} className="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                    <input type="checkbox" checked={it.ok} onChange={() => toggleChecklist(i)} className="accent-emerald-500" />
                    {it.item}
                  </label>
                ))}
              </div>
            </div>
          )}

          <label className="block text-sm text-slate-300">{esLavadero || usaCatalogoServicios ? 'Notas adicionales (opcional)' : esServicioTecnico ? 'Problema reportado / estado visual del equipo' : 'Diagnóstico / falla reportada por el cliente'}
            <textarea value={form.descripcion_trabajo} onChange={set('descripcion_trabajo')} rows="3" className="input mt-1" placeholder="Ej: cambio de aceite, revisión de frenos…" />
          </label>

          <label className="block text-sm text-slate-300">Fecha estimada de entrega
            <input type="datetime-local" value={form.fecha_entrega_estimada} onChange={set('fecha_entrega_estimada')} className="input mt-1" />
          </label>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button type="submit" disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-4 py-2 text-sm font-semibold">
              {guardando ? 'Creando…' : 'Crear orden'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

function ModalOrden({ id, esMecanico, onClose }) {
  const { user } = useAuth()
  const tipoNegocio = user?.empresa_info?.tipo_negocio?.clave
  const esLavadero = tipoNegocio === 'lavadero'
  const esBarberia = tipoNegocio === 'barberia'
  const esTatuajes = tipoNegocio === 'tatuajes'
  const esAccesoriosMotos = tipoNegocio === 'accesorios_motos'
  // Solo estos 3 rubros: aquí sí tiene sentido avisar "ven a retirar tu
  // vehículo" (taller_general incluye a "otro" en otras partes de este
  // archivo, pero aquí se pidió específicamente sin ese genérico).
  const esTallerGenerico = ['taller_motos', 'taller_carros', 'taller_general'].includes(tipoNegocio)
  const iconoOperario = esLavadero ? '🧼' : esBarberia ? '💈' : esTatuajes ? '🎨' : esAccesoriosMotos ? '🔧' : '👨‍🔧'
  const [orden, setOrden] = useState(null)
  const [productos, setProductos] = useState([])
  const [empleados, setEmpleados] = useState([])
  const [detalle, setDetalle] = useState({ producto_id: '', cantidad: 1, precio_unitario: '', operables_employee_id: '' })
  const [guardando, setGuardando] = useState(false)

  const cargar = useCallback(() => api(`/ordenes-servicio/${id}`).then(setOrden).catch((e) => { alert(e.message); onClose() }), [id, onClose])
  useEffect(() => { cargar() }, [cargar])
  useEffect(() => {
    api('/productos').then((r) => setProductos(r.data ?? [])).catch(() => {})
    api('/empleados').then((r) => setEmpleados(r.data ?? [])).catch(() => {})
  }, [])

  if (!orden) return null
  const editable = !['facturado', 'cancelado'].includes(orden.estado)

  async function cambiarEstado(estado) {
    try { await api(`/ordenes-servicio/${id}`, { method: 'PUT', body: { estado } }); cargar() }
    catch (err) { alert(err.message) }
  }

  async function completar() {
    try { await api(`/ordenes-servicio/${id}/completar`, { method: 'POST', body: {} }); cargar() }
    catch (err) { alert(err.message) }
  }

  function alElegirProducto(pid) {
    const p = productos.find((x) => String(x.id) === String(pid))
    setDetalle({ ...detalle, producto_id: pid, precio_unitario: p ? p.precio_venta : '' })
  }

  async function agregarDetalle(e) {
    e.preventDefault()
    if (!detalle.producto_id) return
    setGuardando(true)
    try {
      await api(`/ordenes-servicio/${id}/detalles`, { method: 'POST', body: {
        producto_id: Number(detalle.producto_id),
        cantidad: aNumero(detalle.cantidad) || 1,
        // El backend ignora el precio para el rol Mecanico y usa el de lista.
        precio_unitario: aNumero(detalle.precio_unitario) || 0,
        operables_employee_id: detalle.operables_employee_id ? Number(detalle.operables_employee_id) : null,
      } })
      setDetalle({ producto_id: '', cantidad: 1, precio_unitario: '', operables_employee_id: '' })
      cargar()
    } catch (err) { alert(err.message) } finally { setGuardando(false) }
  }

  async function quitarDetalle(did) {
    if (!confirm('¿Quitar este ítem y devolver el stock?')) return
    try { await api(`/ordenes-servicio/${id}/detalles/${did}`, { method: 'DELETE' }); cargar() }
    catch (err) { alert(err.message) }
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl max-h-[92vh] overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between mb-3">
          <div>
            <h2 className="text-lg font-bold flex items-center gap-2">{orden.numero_orden} <Chip estado={orden.estado} /></h2>
            <p className="text-sm text-slate-400">
              {orden.cliente?.nombre_completo} · {orden.cliente?.telefono ?? ''}
              {orden.asset_vehicle && <> · {iconoVehiculo(orden.asset_vehicle.tipo_activo)} {orden.asset_vehicle.marca} {orden.asset_vehicle.modelo} ({orden.asset_vehicle.placa_identificador ?? 's/placa'})</>}
            </p>
            {orden.plan_lavado && <p className="text-sm text-slate-400">🧼 Plan: {orden.plan_lavado.nombre}</p>}
            {orden.servicio && <p className="text-sm text-slate-400">💈 Servicio: {orden.servicio.nombre}</p>}
            {orden.mecanico_asignado && <p className="text-sm text-slate-400">{iconoOperario} {orden.mecanico_asignado.nombre} {orden.mecanico_asignado.apellido}</p>}
            {(orden.km_entrada || orden.nivel_gasolina != null || orden.accesorios) && (
              <p className="text-sm text-slate-400">
                {orden.km_entrada ? `📏 ${Number(orden.km_entrada).toLocaleString('es-CO')} km` : ''}
                {orden.nivel_gasolina != null ? ` · ⛽ ${orden.nivel_gasolina}%` : ''}
                {orden.accesorios ? ` · 🎒 ${orden.accesorios}` : ''}
              </p>
            )}
            {orden.checklist_entrada?.length > 0 && (
              <div className="flex flex-wrap gap-1.5 mt-1.5">
                {orden.checklist_entrada.map((it) => (
                  <span key={it.item} className={`text-xs rounded-full px-2 py-0.5 ${it.ok ? 'bg-emerald-500/15 text-emerald-300' : 'bg-red-500/15 text-red-300'}`}>
                    {it.ok ? '✓' : '✕'} {it.item}
                  </span>
                ))}
              </div>
            )}
          </div>
          <button onClick={onClose} className="text-slate-400 hover:text-white text-xl">✕</button>
        </div>

        {orden.descripcion_trabajo && (
          <p className="text-sm bg-slate-800/60 rounded-lg p-3 mb-4">{orden.descripcion_trabajo}</p>
        )}

        {/* Cambiar estado */}
        {editable && (
          <div className="flex flex-wrap items-center gap-2 mb-4">
            <span className="text-xs text-slate-500 uppercase">Estado:</span>
            {[
              'recibido', 'en_proceso',
              ...(esLavadero ? ['secando'] : []),
              'listo',
              ...(esMecanico ? [] : ['cancelado']),
            ].map((e) => (
              <button key={e} onClick={() => cambiarEstado(e)} disabled={orden.estado === e}
                className={`text-xs rounded-full px-3 py-1 transition ${orden.estado === e ? ESTADOS[e].clase + ' font-bold' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>
                {ESTADOS[e].label}
              </button>
            ))}
            {esTallerGenerico && orden.cliente?.telefono && (
              <a
                href={`https://wa.me/${orden.cliente.telefono.replace(/\D+/g, '')}?text=${encodeURIComponent(
                  `Hola ${orden.cliente?.nombre_completo ?? ''}, tu ${orden.asset_vehicle ? `${orden.asset_vehicle.marca} ${orden.asset_vehicle.modelo}${orden.asset_vehicle.placa_identificador ? ` (${orden.asset_vehicle.placa_identificador})` : ''}` : 'vehículo'} ya quedó listo, puedes venir a retirarlo. 🔧`
                )}`}
                target="_blank" rel="noreferrer"
                className="ml-auto text-xs rounded-lg bg-green-700 hover:bg-green-600 px-3 py-1.5 font-semibold"
              >
                💬 Avisar por WhatsApp
              </a>
            )}
            <button onClick={completar} className={`text-xs rounded-lg bg-emerald-600 hover:bg-emerald-500 px-3 py-1.5 font-semibold ${esTallerGenerico && orden.cliente?.telefono ? '' : 'ml-auto'}`}>
              ✓ Completar (registra hoja de vida)
            </button>
          </div>
        )}

        {/* Detalles: repuestos y mano de obra */}
        <h3 className="font-semibold text-sm mb-2">Repuestos y trabajos</h3>
        <div className="rounded-xl border border-slate-800 overflow-hidden mb-3">
          <table className="w-full text-sm">
            <thead className="bg-slate-800/60 text-slate-400 text-xs">
              <tr>
                <th className="text-left px-3 py-2">Ítem</th>
                <th className="text-center px-2 py-2">Cant.</th>
                {!esMecanico && <th className="text-right px-2 py-2">Precio</th>}
                {!esMecanico && <th className="text-right px-2 py-2">Subtotal</th>}
                <th className="text-left px-2 py-2">Realizado por</th>
                {editable && <th className="px-2 py-2"></th>}
              </tr>
            </thead>
            <tbody>
              {(orden.details ?? []).length === 0 && (
                <tr><td colSpan="6" className="px-3 py-4 text-center text-slate-500">Aún no hay repuestos ni trabajos registrados.</td></tr>
              )}
              {(orden.details ?? []).map((d) => (
                <tr key={d.id} className="border-t border-slate-800">
                  <td className="px-3 py-2">{d.producto?.nombre ?? '—'}</td>
                  <td className="text-center px-2 py-2">{d.cantidad}</td>
                  {!esMecanico && <td className="text-right px-2 py-2">{COP(d.precio_unitario)}</td>}
                  {!esMecanico && <td className="text-right px-2 py-2 font-semibold">{COP(d.subtotal)}</td>}
                  <td className="px-2 py-2 text-slate-400">{d.operables_employee ? `${d.operables_employee.nombre} ${d.operables_employee.apellido}` : '—'}</td>
                  {editable && (
                    <td className="px-2 py-2 text-right">
                      <button onClick={() => quitarDetalle(d.id)} className="text-red-400 hover:text-red-300">🗑️</button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
            {!esMecanico && (orden.details ?? []).length > 0 && (
              <tfoot>
                <tr className="border-t border-slate-700 bg-slate-800/40">
                  <td colSpan="3" className="px-3 py-2 text-right text-slate-400">Total</td>
                  <td className="text-right px-2 py-2 font-bold">{COP(orden.total)}</td>
                  <td colSpan="2"></td>
                </tr>
              </tfoot>
            )}
          </table>
        </div>

        {/* Agregar detalle */}
        {editable && (
          <form onSubmit={agregarDetalle} className="grid grid-cols-2 md:grid-cols-5 gap-2 items-end rounded-xl border border-slate-800 bg-slate-800/30 p-3">
            <label className="block text-xs text-slate-400 col-span-2">Producto / servicio
              <select value={detalle.producto_id} onChange={(e) => alElegirProducto(e.target.value)} className="input !mt-1" required>
                <option value="">— Selecciona —</option>
                {productos.map((p) => <option key={p.id} value={p.id}>{p.nombre}{p.is_service ? ' (servicio)' : ` · stock ${p.stock_total ?? 0}`}</option>)}
              </select>
            </label>
            <label className="block text-xs text-slate-400">Cantidad
              <input type="number" min="1" value={detalle.cantidad} onChange={(e) => setDetalle({ ...detalle, cantidad: e.target.value })} className="input !mt-1" required />
            </label>
            {!esMecanico && (
              <label className="block text-xs text-slate-400">Precio unitario
                <input type="text" inputMode="decimal" value={detalle.precio_unitario} onChange={(e) => setDetalle({ ...detalle, precio_unitario: e.target.value })} className="input !mt-1" required />
              </label>
            )}
            <label className="block text-xs text-slate-400">Realizado por
              <select value={detalle.operables_employee_id} onChange={(e) => setDetalle({ ...detalle, operables_employee_id: e.target.value })} className="input !mt-1">
                <option value="">—</option>
                {empleados.map((m) => <option key={m.id} value={m.id}>{m.nombre} {m.apellido}</option>)}
              </select>
            </label>
            <button disabled={guardando} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 px-3 py-2 text-sm font-semibold col-span-2 md:col-span-5">
              {guardando ? 'Agregando…' : '+ Agregar (descuenta inventario)'}
            </button>
          </form>
        )}
      </div>
    </div>
  )
}

/* ============ Vehículos / Activos ============ */
function Vehiculos() {
  const [lista, setLista] = useState([])
  const [buscar, setBuscar] = useState('')
  const [creando, setCreando] = useState(false)

  const cargar = useCallback(() => {
    api(`/activos?buscar=${encodeURIComponent(buscar)}`).then((r) => setLista(r.data ?? [])).catch(() => {})
  }, [buscar])
  useEffect(() => { const t = setTimeout(cargar, 300); return () => clearTimeout(t) }, [cargar])

  return (
    <div>
      <div className="flex gap-2 mb-4">
        <input value={buscar} onChange={(e) => setBuscar(e.target.value)} placeholder="Buscar placa, marca, modelo…" className="input !mt-0 w-64" />
        <button onClick={() => setCreando(true)} className="ml-auto rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Registrar vehículo</button>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {lista.map((v) => (
          <div key={v.id} className="rounded-xl border border-slate-800 bg-slate-800/40 p-4">
            <div className="flex items-center justify-between">
              <span className="font-bold">{v.marca} {v.modelo}</span>
              <span className="text-xs rounded-full bg-slate-700 px-2 py-0.5">{v.tipo_activo}</span>
            </div>
            <p className="text-sm text-slate-400 mt-1">
              Placa: <span className="text-white font-mono">{v.placa_identificador ?? '—'}</span>
              {v.anio ? ` · ${v.anio}` : ''}{v.color ? ` · ${v.color}` : ''}
            </p>
            <p className="text-sm text-slate-400">Dueño: {v.cliente?.nombre_completo ?? '—'}</p>
          </div>
        ))}
        {lista.length === 0 && <p className="text-slate-500 col-span-2 text-center py-6">No hay vehículos registrados.</p>}
      </div>
      {creando && <ModalVehiculo onClose={() => setCreando(false)} onGuardado={() => { setCreando(false); cargar() }} />}
    </div>
  )
}

function ModalVehiculo({ onClose, onGuardado }) {
  const [clientes, setClientes] = useState([])
  const [tipos, setTipos] = useState([])
  const [form, setForm] = useState({ cliente_id: '', tipo_activo: 'moto', placa_identificador: '', marca: '', modelo: '', anio: '', color: '' })

  useEffect(() => {
    api('/clientes').then((r) => setClientes(r.data ?? [])).catch(() => {})
    api('/activos/tipos').then((r) => setTipos(r.tipos ?? [])).catch(() => {})
  }, [])

  async function guardar(e) {
    e.preventDefault()
    try {
      await api('/activos', { method: 'POST', body: {
        ...form,
        cliente_id: form.cliente_id ? Number(form.cliente_id) : null,
        placa_identificador: form.placa_identificador || null,
        anio: form.anio ? Number(form.anio) : null,
        color: form.color || null,
      } })
      onGuardado()
    } catch (err) { alert(err.message || 'No se pudo guardar.') }
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold mb-4">Registrar vehículo / activo</h2>
        <form onSubmit={guardar} className="space-y-3">
          <label className="block text-sm text-slate-300">Cliente (dueño)
            <select value={form.cliente_id} onChange={set('cliente_id')} className="input mt-1">
              <option value="">— Sin cliente —</option>
              {clientes.map((c) => <option key={c.id} value={c.id}>{c.nombre_completo}</option>)}
            </select>
          </label>
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm text-slate-300">Tipo
              <select value={form.tipo_activo} onChange={set('tipo_activo')} className="input mt-1">
                {tipos.map((t) => <option key={t.valor} value={t.valor}>{t.etiqueta}</option>)}
              </select>
            </label>
            <label className="block text-sm text-slate-300">Placa / identificador
              <input value={form.placa_identificador} onChange={(e) => setForm({ ...form, placa_identificador: e.target.value.toUpperCase() })} className="input mt-1" />
            </label>
            <label className="block text-sm text-slate-300">Marca *
              <input value={form.marca} onChange={set('marca')} className="input mt-1" required />
            </label>
            <label className="block text-sm text-slate-300">Modelo *
              <input value={form.modelo} onChange={set('modelo')} className="input mt-1" required />
            </label>
            <label className="block text-sm text-slate-300">Año
              <input type="number" min="1900" max={new Date().getFullYear()} value={form.anio} onChange={set('anio')} className="input mt-1" />
            </label>
            <label className="block text-sm text-slate-300">Color
              <input value={form.color} onChange={set('color')} className="input mt-1" />
            </label>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button type="submit" className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  )
}

/* ============ Empleados del taller ============ */
function Empleados({ esLavadero, esBarberia, esTatuajes, esAccesoriosMotos }) {
  const [lista, setLista] = useState([])
  const [editando, setEditando] = useState(null) // null | 'nuevo' | empleado
  const icono = esLavadero ? '🧼' : esBarberia ? '💈' : esTatuajes ? '🎨' : esAccesoriosMotos ? '🔧' : '👨‍🔧'

  const cargar = useCallback(() => api('/empleados').then((r) => setLista(r.data ?? [])).catch(() => {}), [])
  useEffect(() => { cargar() }, [cargar])

  async function eliminar(m) {
    if (!confirm(`¿Eliminar a ${m.nombre} ${m.apellido}?`)) return
    try { await api(`/empleados/${m.id}`, { method: 'DELETE' }); cargar() }
    catch (err) { alert(err.message) }
  }

  return (
    <div>
      <div className="flex mb-4">
        <button onClick={() => setEditando('nuevo')} className="ml-auto rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">+ Nuevo empleado</button>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {lista.map((m) => (
          <div key={m.id} className="rounded-xl border border-slate-800 bg-slate-800/40 p-4">
            <div className="flex items-center justify-between">
              <span className="font-bold">{icono} {m.nombre} {m.apellido}</span>
              <span className="text-xs rounded-full bg-slate-700 px-2 py-0.5">{m.tipo_operario}</span>
            </div>
            <p className="text-sm text-slate-400 mt-1">CC {m.ci_cedula}{m.telefono ? ` · ${m.telefono}` : ''}</p>
            {m.especialidad && <p className="text-sm text-slate-400">Especialidad: {m.especialidad}</p>}
            {m.comision_default > 0 && (
              <p className="text-sm text-slate-400">Comisión: {m.tipo_comision_default === 'fixed' ? COP(m.comision_default) : `${Number(m.comision_default)}%`}</p>
            )}
            <div className="flex gap-2 mt-3">
              <button onClick={() => setEditando(m)} className="text-xs rounded bg-slate-700 hover:bg-slate-600 px-3 py-1.5">✏️ Editar</button>
              <button onClick={() => eliminar(m)} className="text-xs rounded bg-red-900/60 hover:bg-red-800 px-3 py-1.5">🗑️ Eliminar</button>
            </div>
          </div>
        ))}
        {lista.length === 0 && (
          <p className="text-slate-500 col-span-2 text-center py-6">
            {esLavadero ? 'Sin lavadores. Crea tu equipo de lavado.' : esBarberia ? 'Sin barberos. Crea tu equipo de estilistas.' : esTatuajes ? 'Sin artistas. Crea tu equipo de tatuadores.' : esAccesoriosMotos ? 'Sin instaladores. Crea tu equipo de instaladores.' : 'Sin empleados. Crea tu equipo de mecánicos/técnicos.'}
          </p>
        )}
      </div>
      {editando && (
        <ModalEmpleado empleado={editando === 'nuevo' ? null : editando} esLavadero={esLavadero} esBarberia={esBarberia} esTatuajes={esTatuajes} esAccesoriosMotos={esAccesoriosMotos}
          onClose={() => setEditando(null)} onGuardado={() => { setEditando(null); cargar() }} />
      )}
      {!esBarberia && !esTatuajes && (
        <p className="text-xs text-slate-500 mt-4">
          💡 Para que {esLavadero ? 'un lavador entre' : esAccesoriosMotos ? 'un instalador entre' : 'un mecánico entre'} al sistema con su propio usuario (y solo vea sus órdenes), créale una cuenta con rol
          <span className="font-semibold"> {esLavadero ? 'Lavador' : esAccesoriosMotos ? 'Instalador' : 'Mecanico'}</span> desde Configuración → Equipo, vinculándola a su ficha de empleado.
        </p>
      )}
    </div>
  )
}

function ModalEmpleado({ empleado, esLavadero, esBarberia, esTatuajes, esAccesoriosMotos, onClose, onGuardado }) {
  const [tipos, setTipos] = useState([])
  const [form, setForm] = useState({
    nombre: empleado?.nombre ?? '', apellido: empleado?.apellido ?? '',
    ci_cedula: empleado?.ci_cedula ?? '', telefono: empleado?.telefono ?? '',
    tipo_operario: empleado?.tipo_operario ?? (esLavadero ? 'lavador' : esBarberia ? 'barbero' : esTatuajes ? 'tatuador' : esAccesoriosMotos ? 'instalador' : 'mecanico'),
    especialidad: empleado?.especialidad ?? '',
    comision_default: empleado?.comision_default ?? '',
    tipo_comision_default: empleado?.tipo_comision_default ?? 'percentage',
  })
  const [galeria, setGaleria] = useState(empleado?.galeria ?? [])
  const [subiendoFoto, setSubiendoFoto] = useState(false)

  useEffect(() => { api('/empleados/tipos').then((r) => setTipos(r.tipos ?? [])).catch(() => {}) }, [])

  async function agregarFotoPortafolio(file) {
    if (!file || !empleado?.id) return
    setSubiendoFoto(true)
    try {
      const fd = new FormData()
      fd.append('imagen', file)
      const item = await api(`/empleados/${empleado.id}/galeria`, { method: 'POST', body: fd, isForm: true })
      setGaleria((g) => [...g, item])
    } catch (err) {
      alert(err.message || 'No se pudo subir la foto.')
    } finally {
      setSubiendoFoto(false)
    }
  }

  async function quitarFotoPortafolio(imagenId) {
    if (!empleado?.id) return
    try {
      await api(`/empleados/${empleado.id}/galeria/${imagenId}`, { method: 'DELETE' })
      setGaleria((g) => g.filter((f) => f.id !== imagenId))
    } catch (err) { alert(err.message || 'No se pudo quitar la foto.') }
  }

  async function guardar(e) {
    e.preventDefault()
    try {
      const body = {
        ...form,
        telefono: form.telefono || null,
        comision_default: form.comision_default ? aNumero(form.comision_default) : null,
      }
      if (empleado?.id) await api(`/empleados/${empleado.id}`, { method: 'PUT', body })
      else await api('/empleados', { method: 'POST', body })
      onGuardado()
    } catch (err) { alert(err.message || 'No se pudo guardar.') }
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold mb-4">{empleado ? `Editar a ${empleado.nombre} ${empleado.apellido}` : esLavadero ? 'Nuevo lavador' : esBarberia ? 'Nuevo barbero' : esAccesoriosMotos ? 'Nuevo instalador' : 'Nuevo empleado del taller'}</h2>
        <form onSubmit={guardar} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm text-slate-300">Nombre *
              <input value={form.nombre} onChange={set('nombre')} className="input mt-1" required />
            </label>
            <label className="block text-sm text-slate-300">Apellido *
              <input value={form.apellido} onChange={set('apellido')} className="input mt-1" required />
            </label>
            <label className="block text-sm text-slate-300">Cédula *
              <input value={form.ci_cedula} onChange={set('ci_cedula')} className="input mt-1" required />
            </label>
            <label className="block text-sm text-slate-300">Teléfono
              <input value={form.telefono} onChange={set('telefono')} className="input mt-1" />
            </label>
            <label className="block text-sm text-slate-300">Oficio
              <select value={form.tipo_operario} onChange={set('tipo_operario')} className="input mt-1">
                {tipos.map((t) => <option key={t.valor} value={t.valor}>{t.etiqueta}</option>)}
              </select>
            </label>
            {form.tipo_operario === 'tatuador' && (
              <label className="block text-sm text-slate-300">Especialidad
                <input value={form.especialidad} onChange={set('especialidad')} placeholder="Ej. Línea fina, Realismo, Blackwork…" className="input mt-1" />
              </label>
            )}
            {form.tipo_operario === 'instalador' && (
              <label className="block text-sm text-slate-300">¿Qué instala? (personalizado)
                <input value={form.especialidad} onChange={set('especialidad')} placeholder="Ej. Instalación de luces, alarmas, escapes…" className="input mt-1" />
              </label>
            )}
            {esAccesoriosMotos && form.tipo_operario === 'otro' && (
              <label className="block text-sm text-slate-300">¿Cuál es el oficio?
                <input value={form.especialidad} onChange={set('especialidad')} placeholder="Ej. Soldador, pintor, decorador…" className="input mt-1" />
              </label>
            )}
            <label className="block text-sm text-slate-300">Comisión por defecto
              <div className="flex gap-1 mt-1">
                <input type="number" min="0" step="any" value={form.comision_default} onChange={set('comision_default')} className="input !mt-0" placeholder="0" />
                <select value={form.tipo_comision_default} onChange={set('tipo_comision_default')} className="input !mt-0 w-20">
                  <option value="percentage">%</option>
                  <option value="fixed">$</option>
                </select>
              </div>
            </label>
          </div>

          {empleado?.id && form.tipo_operario === 'tatuador' && (
            <div>
              <p className="text-sm text-slate-300 mb-1">Portafolio (trabajos previos)</p>
              <div className="flex flex-wrap gap-2">
                {galeria.map((f) => (
                  <div key={f.id} className="relative group">
                    <img src={f.url} alt="" className="h-16 w-16 rounded-lg object-cover border border-slate-700" />
                    <button type="button" onClick={() => quitarFotoPortafolio(f.id)}
                      className="absolute -top-1.5 -right-1.5 h-5 w-5 rounded-full bg-red-600 hover:bg-red-500 text-white text-xs leading-5">✕</button>
                  </div>
                ))}
                <label className="h-16 w-16 rounded-lg border border-dashed border-slate-600 flex items-center justify-center text-slate-500 hover:text-slate-300 hover:border-slate-500 cursor-pointer text-xs text-center">
                  {subiendoFoto ? '…' : '+ Foto'}
                  <input type="file" accept="image/*" className="hidden" disabled={subiendoFoto}
                    onChange={(e) => { const f = e.target.files?.[0]; if (f) agregarFotoPortafolio(f); e.target.value = '' }} />
                </label>
              </div>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">Cancelar</button>
            <button type="submit" className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  )
}
