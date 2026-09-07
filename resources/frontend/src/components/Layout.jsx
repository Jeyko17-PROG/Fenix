import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useFeatures } from '../context/FeaturesContext'
import { api } from '../api/client'

// Ícono propio del rubro de tatuajes (máquina de tatuar). Reemplaza el 🎨
// genérico en los lugares donde antes se usaba ese emoji.
const ICONO_MAQUINA_TATUAR = '/tatto.png'

/**
 * Ícono de un ítem del menú: casi siempre es un emoji de texto, pero admite
 * una imagen propia (ej. el logo de una máquina de tatuar) cuando el valor
 * es una ruta ("/icons/algo.png") en vez de un emoji.
 */
function IconoMenu({ valor, className = 'text-base' }) {
  if (typeof valor === 'string' && valor.startsWith('/')) {
    return <img src={valor} alt="" className={`${className} w-[1em] h-[1em] object-contain inline-block`}
      onError={(e) => { e.currentTarget.style.display = 'none' }} />
  }
  return <span className={className}>{valor}</span>
}

function Campana() {
  const [abierto, setAbierto] = useState(false)
  const [items, setItems] = useState([])
  const [noLeidas, setNoLeidas] = useState(0)
  const [banners, setBanners] = useState([])
  // Máximo id de notificación ya visto: null = todavía no cargó la primera
  // vez (no se debe mostrar banner de nada que ya existía al entrar).
  const maxIdVistoRef = useRef(null)

  async function cargar() {
    try {
      const [lista, count] = await Promise.all([api('/notificaciones'), api('/notificaciones/no-leidas')])
      setItems(lista); setNoLeidas(count.no_leidas)

      if (maxIdVistoRef.current !== null) {
        const nuevas = lista.filter((n) => n.tipo === 'RESERVA' && n.id > maxIdVistoRef.current)
        if (nuevas.length > 0) {
          setBanners((b) => [...nuevas, ...b])
          nuevas.forEach((n) => {
            setTimeout(() => setBanners((b) => b.filter((x) => x.id !== n.id)), 10000)
          })
        }
      }
      if (lista.length > 0) {
        maxIdVistoRef.current = Math.max(lista[0].id, maxIdVistoRef.current ?? 0)
      }
    } catch { /* ignore */ }
  }
  useEffect(() => {
    cargar()
    const t = setInterval(cargar, 30000) // refresca cada 30s
    return () => clearInterval(t)
  }, [])

  async function abrir() {
    setAbierto(!abierto)
    if (!abierto && noLeidas > 0) {
      await api('/notificaciones/marcar-leidas', { method: 'POST' })
      setNoLeidas(0)
    }
  }

  return (
    <>
      <div className="relative">
        <button onClick={abrir} className="relative p-2 rounded-lg hover:bg-slate-800 text-lg">🔔
          {noLeidas > 0 && <span className="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-xs rounded-full px-1.5">{noLeidas}</span>}
        </button>
        {abierto && (
          <div className="absolute right-0 mt-2 w-80 max-h-96 overflow-y-auto bg-slate-800 border border-slate-700 rounded-xl shadow-xl z-50">
            {items.length === 0 && <p className="p-4 text-slate-500 text-sm">Sin notificaciones.</p>}
            {items.map((n) => (
              <div key={n.id} className="p-3 border-b border-slate-700 text-sm">
                <p className="font-medium">{n.titulo}</p>
                {n.mensaje && <p className="text-slate-400 text-xs">{n.mensaje}</p>}
                <p className="text-slate-600 text-xs mt-1">{new Date(n.created_at).toLocaleString('es')}</p>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Banner de reserva nueva: aparece solo, sin necesidad de abrir la campana */}
      {banners.length > 0 && (
        <div className="fixed top-20 right-4 z-[60] flex flex-col gap-2 w-80 max-w-[90vw]">
          {banners.map((n) => (
            <div key={n.id} className="rounded-xl bg-emerald-600 text-white shadow-xl p-3 text-sm flex items-start gap-2">
              <span className="text-lg leading-none">📅</span>
              <div className="flex-1 min-w-0">
                <p className="font-semibold">Nueva reserva</p>
                {n.mensaje && <p className="text-emerald-50 text-xs mt-0.5">{n.mensaje}</p>}
              </div>
              <button onClick={() => setBanners((b) => b.filter((x) => x.id !== n.id))} className="text-emerald-100 hover:text-white leading-none">✕</button>
            </div>
          ))}
        </div>
      )}
    </>
  )
}

// Menú organizado por secciones (sectorizado) para una navegación más clara.
const MENU = [
  {
    grupo: 'Principal',
    items: [
      { to: '/', label: 'Dashboard', icon: '📊', end: true },
    ],
  },
  {
    grupo: 'Agenda',
    items: [
      { to: '/agenda', label: 'Agenda y Citas', icon: '📅', feat: 'agenda' },
      { to: '/qr', label: 'QR Reservas', icon: '🔲', feat: 'qr' },
    ],
  },
  {
    grupo: 'Servicios',
    items: [
      { to: '/taller', label: 'Taller / Órdenes', icon: '🔧', feat: 'servicios', featPorTipo: { lavadero: 'lavadero', barberia: 'barberia', tatuajes: 'tatuajes' } },
      { to: '/planes-lavado', label: 'Planes de Lavado', icon: '🧼', feat: 'lavadero', soloTipo: 'lavadero' },
      { to: '/servicios', label: 'Servicios', icon: '💈', feat: 'agenda' },
      // Mismo módulo que "Productos" en Inventario: el catálogo con fotos y
      // disponible/agotado que el cliente ve al agendar su cita en el portal.
      { to: '/productos', label: 'Catálogo', icon: '🛍️', feat: 'productos' },
      { to: '/restaurante', label: 'Mesas y Comandas', icon: '🍽️', feat: 'mesas' },
      { to: '/caja', label: 'Caja y Gastos', icon: '💵', feat: 'caja' },
    ],
  },
  {
    grupo: 'Clientes y Ventas',
    items: [
      { to: '/clientes', label: 'Clientes', icon: '👥', feat: 'clientes' },
      { to: '/facturacion', label: 'Facturación', icon: '🧾', feat: 'facturacion' },
    ],
  },
  {
    grupo: 'Inventario',
    items: [
      { to: '/productos', label: 'Productos', icon: '📦', feat: 'productos' },
      { to: '/inventario', label: 'Inventario', icon: '🏷️', feat: 'inventario' },
      { to: '/bodegas', label: 'Bodegas', icon: '🏭', feat: 'inventario' },
      { to: '/proveedores', label: 'Proveedores', icon: '🚚', feat: 'proveedores' },
      { to: '/compras', label: 'Compras', icon: '🛒', feat: 'proveedores' },
    ],
  },
  {
    grupo: 'Documentos',
    items: [
      { to: '/documentos', label: 'Documentos', icon: '📁', feat: 'documental' },
      { to: '/reportes', label: 'Reportes', icon: '📈', feat: 'reportes' },
    ],
  },
  {
    grupo: 'Herramientas',
    items: [
      { to: '/notas', label: 'Bloc de Notas', icon: '📝', feat: 'notas' },
      { to: '/calculadora', label: 'Calculadora', icon: '🧮', feat: 'calculadora' },
      { to: '/notificaciones', label: 'Notificaciones', icon: '🔔', feat: 'notificaciones' },
    ],
  },
  {
    grupo: 'Cuenta',
    items: [
      { to: '/perfil', label: 'Mi Perfil', icon: '🙍' },
      { to: '/mis-negocios', label: 'Mis Negocios', icon: '🏪', soloDueno: true },
      { to: '/equipo', label: 'Mi Equipo', icon: '🧑‍🤝‍🧑', soloDueno: true },
      { to: '/planes', label: 'Planes', icon: '💳' },
      { to: '/configuracion', label: 'Configuración', icon: '⚙️' },
    ],
  },
]

// Rutas permitidas para los roles operativos restringidos (Mecanico, Lavador,
// Instalador...): solo su trabajo, sin dinero ni facturación.
const RUTAS_MECANICO = ['/', '/taller', '/productos', '/notificaciones', '/perfil']

// Sección visible únicamente para el Super Administrador.
const MENU_SUPER_ADMIN = {
  grupo: 'Plataforma (Super Admin)',
  items: [
    { to: '/empresas', label: 'Empresas', icon: '🏢' },
    { to: '/usuarios', label: 'Usuarios Registrados', icon: '👤' },
    { to: '/licencias', label: 'Administración de Licencias', icon: '🔑' },
    { to: '/funcionalidades', label: 'Control de Funcionalidades', icon: '🧩' },
    { to: '/auditoria', label: 'Auditoría', icon: '📜' },
  ],
}

export default function Layout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [abierto, setAbierto] = useState(false)
  // Sidebar colapsable en escritorio (más espacio para tablas de Inventario/Agenda). Se recuerda entre sesiones.
  const [isCollapsed, setIsCollapsed] = useState(() => localStorage.getItem('logix_sidebar_collapsed') === '1')
  useEffect(() => {
    localStorage.setItem('logix_sidebar_collapsed', isCollapsed ? '1' : '0')
  }, [isCollapsed])

  useEffect(() => {
    const onResize = () => {
      if (window.innerWidth >= 768) {
        setAbierto(false)
      }
    }
    onResize()
    window.addEventListener('resize', onResize)
    return () => window.removeEventListener('resize', onResize)
  }, [])

  useEffect(() => {
    document.body.style.overflow = abierto ? 'hidden' : ''
    return () => {
      document.body.style.overflow = ''
    }
  }, [abierto])

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  const linkClass = ({ isActive }) =>
    `flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition ${
      isActive ? 'bg-emerald-600 text-white' : 'text-slate-300 hover:bg-slate-800'
    }`

  const { visible } = useFeatures()

  // El Super Administrador ve además la sección de plataforma.
  const base = user?.es_super_admin ? [...MENU, MENU_SUPER_ADMIN] : MENU
  const tipoNegocio = user?.empresa_info?.tipo_negocio?.clave
  const esMecanico = user?.rol?.nombre === 'Mecanico'
  const esLavadorRol = user?.rol?.nombre === 'Lavador'
  const esInstaladorRol = user?.rol?.nombre === 'Instalador'
  const esOperarioRol = esMecanico || esLavadorRol || esInstaladorRol
  // Dueño del negocio: quien puede gestionar el equipo (crear empleados y asignarles rol).
  const esDueno = !!user?.es_admin_empresa || ['Administrador', 'Usuario'].includes(user?.rol?.nombre)
  // "Taller / Órdenes" cambia de nombre según el tipo de negocio (misma ruta /taller).
  // OJO: no puede llamarse "Servicios" en ningún tipo — esa palabra ya la usa
  // el ítem de /servicios (el catálogo); si coinciden, se ven como dos enlaces
  // idénticos en el menú y parece que uno de los dos "no aparece".
  const LABEL_POR_TIPO = {
    lavadero: { label: 'Servicios de Lavado', icon: '🧼' },
    barberia: { label: 'Órdenes', icon: '💈' },
    tatuajes: { label: 'Sesiones', icon: ICONO_MAQUINA_TATUAR },
    accesorios_motos: { label: 'Órdenes', icon: '🔧' },
  }
  // El ícono de "Servicios" (catálogo de estilos/tratamientos) también cambia
  // según el rubro — antes siempre mostraba 💈 (barbería) sin importar el tipo.
  const ICONO_SERVICIOS_POR_TIPO = { barberia: '💈', tatuajes: ICONO_MAQUINA_TATUAR, spa: '💆' }
  const OVERRIDES_POR_RUTA = {
    '/taller': LABEL_POR_TIPO[tipoNegocio],
    '/servicios': ICONO_SERVICIOS_POR_TIPO[tipoNegocio] ? { icon: ICONO_SERVICIOS_POR_TIPO[tipoNegocio] } : null,
  }
  // Oculta del menú las funcionalidades DESACTIVADAS para el usuario.
  // El Mecánico/Lavador solo ve sus órdenes y catálogo: nada de facturación ni dinero.
  const secciones = base
    .map((s) => ({
      ...s,
      items: s.items
        .filter((m) => {
          // Negocios lavadero/barbería: el módulo relevante es el propio, independiente de 'servicios' (talleres).
          const feat = (tipoNegocio && m.featPorTipo?.[tipoNegocio]) ? m.featPorTipo[tipoNegocio] : m.feat
          const permitidoPorTipo = !m.soloTipo || (Array.isArray(m.soloTipo) ? m.soloTipo.includes(tipoNegocio) : m.soloTipo === tipoNegocio)
          return (!feat || visible(feat)) && (!esOperarioRol || RUTAS_MECANICO.includes(m.to)) && permitidoPorTipo && (!m.soloDueno || esDueno)
        })
        .map((m) => (OVERRIDES_POR_RUTA[m.to] ? { ...m, ...OVERRIDES_POR_RUTA[m.to] } : m)),
    }))
    .filter((s) => s.items.length > 0)

  // Estado de cobro SaaS (viene en /me): saldo prepago o vencimiento de membresía.
  const saas = user?.facturacion_saas

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100">
      {/* Barra superior */}
      {/* pt usa env(safe-area-inset-top) además del padding normal: en un
          celular con notch/cámara punch-hole, instalado como PWA standalone
          (sin barra del navegador), el header fijo si no reserva ese espacio
          queda tapado por el notch. main/aside abajo compensan con el mismo
          extra para que el contenido no arranque debajo del header. */}
      <header className="fixed top-0 inset-x-0 z-50 bg-slate-950 border-b border-slate-800 px-3 sm:px-4 pb-3 pt-[calc(0.75rem+env(safe-area-inset-top))] overflow-hidden">
        <div className="flex items-center gap-1.5 sm:gap-3 min-w-0">
          {/* Colapsar/expandir el sidebar en escritorio: más ancho para tablas de Inventario/Agenda. */}
          <button onClick={() => setIsCollapsed(!isCollapsed)} aria-label={isCollapsed ? 'Expandir menú' : 'Colapsar menú'}
            className="hidden md:flex p-2 rounded-lg text-slate-300 hover:bg-slate-800 hover:text-white text-lg leading-none shrink-0">
            ☰
          </button>
          <img src="/logo-fenix.png" alt="" className="h-7 w-7 object-contain shrink-0"
            onError={(e) => { e.currentTarget.style.display = 'none' }} />
          <span className="font-bold text-lg shrink-0">Fénix</span>
          <div className="ml-auto flex items-center gap-1 sm:gap-2 min-w-0 shrink overflow-hidden">
            {/* Saldo de la billetera (modo prepago) o aviso de membresía vencida.
                Texto corto en celular: en pantallas angostas esto era lo que
                más fácil empujaba el botón "Salir" fuera de la pantalla. */}
            {saas?.modo_cobro === 'prepago' && !user?.es_super_admin && (
              <NavLink to="/planes" className="hidden sm:flex items-center gap-1 text-xs rounded-full bg-sky-500/15 text-sky-300 px-3 py-1.5 hover:bg-sky-500/25 shrink-0">
                💰 {saas.creditos_facturacion ?? 0} facturas
              </NavLink>
            )}
            {saas?.membresia_vencida && !user?.es_super_admin && (
              <NavLink to="/planes?vencida=1" className="flex items-center gap-1 text-xs rounded-full bg-red-500/20 text-red-300 px-2 sm:px-3 py-1.5 shrink-0 whitespace-nowrap hover:bg-red-500/30">
                ⚠️ <span className="hidden sm:inline">Membresía vencida</span><span className="sm:hidden">Vencida</span>
              </NavLink>
            )}
            <Campana />
            <span className="text-sm text-slate-400 hidden sm:block truncate max-w-[10rem]">{user?.name} · {user?.rol?.nombre ?? 'Sin rol'}</span>
            <button onClick={handleLogout} className="text-sm rounded-lg bg-slate-800 hover:bg-slate-700 px-2 sm:px-3 py-1.5 shrink-0 whitespace-nowrap" aria-label="Cerrar sesión">
              <span className="sm:hidden">🚪</span><span className="hidden sm:inline">Salir</span>
            </button>
          </div>
        </div>

        <div className="mt-3 flex items-center gap-2 md:hidden">
          <button onClick={() => setAbierto(!abierto)} aria-label="Menú" aria-expanded={abierto} aria-controls="mobile-sidebar"
            className="flex items-center gap-2 p-2 rounded-lg bg-slate-800/80 text-white hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-base leading-none">
            <span className="text-xl">☰</span>
            Menú
          </button>
          <button onClick={() => navigate('/dashboard')} aria-label="Volver al Dashboard" className="flex items-center gap-2 p-2 rounded-lg bg-slate-800/80 text-slate-200 hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 text-base leading-none">
            <span className="text-lg">←</span>
            Atrás
          </button>
        </div>
      </header>

      {/* Fondo oscuro al abrir el menú en móvil */}
      {abierto && <div onClick={() => setAbierto(false)} className="fixed inset-0 z-30 bg-black/50 md:hidden" />}

      {/* Menú lateral deslizante (oculto por defecto, se abre con el botón ☰) */}
      <aside id="mobile-sidebar" className={`fixed top-0 left-0 z-40 h-full w-72 max-w-[85vw] bg-slate-950 border-r border-slate-800 pt-[calc(7rem+env(safe-area-inset-top))] md:pt-16 px-3 pb-4 overflow-y-auto transition-all duration-200 shadow-2xl
        ${abierto ? 'translate-x-0' : '-translate-x-full'}
        ${isCollapsed ? 'md:-translate-x-full md:w-0 md:px-0 md:border-0 md:overflow-hidden' : 'md:translate-x-0 md:w-64'} md:max-w-none`}>
        <div className="mb-3 flex items-center justify-between px-1 md:hidden">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Navegación</p>
          <button onClick={() => setAbierto(false)} className="rounded-lg p-2 text-slate-400 hover:bg-slate-800 hover:text-white" aria-label="Cerrar menú">✕</button>
        </div>
        <nav className="flex flex-col gap-1">
          {secciones.map((seccion) => (
            <div key={seccion.grupo} className="mb-2">
              <p className="px-4 pt-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{seccion.grupo}</p>
              {seccion.items.map((m) => (
                <NavLink key={m.to} to={m.to} end={m.end} onClick={() => setAbierto(false)} className={linkClass}>
                  <IconoMenu valor={m.icon} />{m.label}
                </NavLink>
              ))}
            </div>
          ))}

          {/* Cerrar sesión */}
          <button onClick={handleLogout}
            className="flex items-center gap-3 px-4 py-2.5 mt-2 rounded-lg text-sm font-medium text-red-300 hover:bg-red-500/10 transition">
            <span className="text-base">🚪</span>Cerrar Sesión
          </button>
        </nav>
      </aside>

      {/* Contenido: en escritorio deja espacio al sidebar fijo (o lo reclama si está colapsado); en móvil baja bajo el header de dos filas */}
      <main className={`pt-[calc(7rem+env(safe-area-inset-top))] md:pt-[calc(3.5rem+env(safe-area-inset-top))] transition-[padding] duration-200 ${isCollapsed ? 'md:pl-0' : 'md:pl-64'}`}>
        <div className={`mx-auto px-4 py-8 transition-[max-width] duration-200 ${isCollapsed ? 'max-w-full' : 'max-w-6xl'}`}>
          <Outlet />
        </div>
      </main>
    </div>
  )
}
