import { useRef, useState } from 'react'
import { QRCodeCanvas } from 'qrcode.react'
import { useAuth } from '../context/AuthContext'
import { getPublicBaseUrl, setPublicBaseUrl, getReservasUrl, esUrlLocal } from '../utils/publicUrl'

export default function QR() {
  const { user } = useAuth()
  const ref = useRef(null)
  const [copiado, setCopiado] = useState(false)
  const [base, setBase] = useState(getPublicBaseUrl())
  const [editando, setEditando] = useState('')

  const url = getReservasUrl(user?.reservas_slug)
  const local = esUrlLocal(base)
  const mensaje = `¡Reserva tu cita en línea! 👉 ${url}`
  // Prioridad: logo real subido > emoji propio elegido en Perfil > ícono genérico según el rubro.
  const logoUrl = user?.empresa_info?.logo_url
  const logoEmoji = user?.empresa_info?.logo_emoji
  const iconoTipoNegocio = { lavadero: '🧼', barberia: '💈', spa: '💆', tatuajes: '/tatto.png' }[user?.empresa_info?.tipo_negocio?.clave]

  function guardarBase() {
    setPublicBaseUrl(editando)
    setBase(getPublicBaseUrl())
    setEditando('')
  }

  function descargar() {
    const canvas = ref.current?.querySelector('canvas')
    if (!canvas) return
    const a = document.createElement('a')
    a.href = canvas.toDataURL('image/png')
    a.download = 'qr-reservas-logix.png'
    a.click()
  }

  function imprimir() {
    const canvas = ref.current?.querySelector('canvas')
    if (!canvas) return
    const img = canvas.toDataURL('image/png')
    const w = window.open('', '_blank')
    w.document.write(`<html><head><title>QR de reservas — Fénix</title></head>
      <body style="text-align:center;font-family:sans-serif;padding:40px">
      <h2>Reserva tu cita</h2>
      <img src="${img}" style="width:320px;height:320px" />
      <p style="font-size:14px;color:#333">${url}</p>
      <script>window.onload=()=>{window.print()}</script>
      </body></html>`)
    w.document.close()
  }

  async function copiar() {
    await navigator.clipboard.writeText(url)
    setCopiado(true)
    setTimeout(() => setCopiado(false), 2000)
  }

  const compartir = [
    { label: 'WhatsApp', color: 'bg-green-600', href: `https://wa.me/?text=${encodeURIComponent(mensaje)}` },
    { label: 'Correo', color: 'bg-sky-600', href: `mailto:?subject=${encodeURIComponent('Reserva tu cita')}&body=${encodeURIComponent(mensaje)}` },
    { label: 'Facebook', color: 'bg-blue-700', href: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}` },
    { label: 'Web/Telegram', color: 'bg-cyan-600', href: `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent('Reserva tu cita')}` },
  ]

  return (
    <div className="max-w-lg mx-auto">
      <h1 className="text-2xl font-bold mb-2">QR y enlace de reservas</h1>
      <p className="text-slate-400 text-sm mb-6">Comparte este código o enlace. Tus clientes podrán reservar en tiempo real desde su celular.</p>

      {local && (
        <div className="mb-6 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-200">
          <p className="font-semibold mb-1">⚠️ Tu enlace apunta a una dirección local ({base}).</p>
          <p className="text-amber-200/80">Un cliente externo no podrá abrirlo. Configura abajo tu <b>URL pública</b>:</p>
          <ul className="list-disc list-inside mt-2 text-amber-200/80 text-xs space-y-0.5">
            <li>Misma red WiFi: usa la IP de tu PC, p. ej. <code>http://192.168.1.50:5173</code> (ejecuta Vite con <code>--host</code>).</li>
            <li>Desde Internet: usa un túnel (Cloudflare Tunnel / ngrok) o el dominio donde despliegues.</li>
          </ul>
        </div>
      )}

      {/* Configurar URL pública */}
      <div className="mb-6 rounded-xl border border-slate-800 bg-slate-800/40 p-4">
        <label className="text-sm text-slate-300">URL pública del sistema</label>
        <div className="flex gap-2 mt-1">
          <input value={editando !== '' ? editando : base} onChange={(e) => setEditando(e.target.value)}
            placeholder="https://tu-dominio.com" className="input" />
          <button onClick={guardarBase} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 text-sm font-semibold whitespace-nowrap">Guardar</button>
        </div>
        <p className="text-xs text-slate-500 mt-1">El QR y el enlace usarán esta dirección. Déjala vacía para usar la dirección actual del navegador.</p>
      </div>

      <div ref={ref} className="bg-white rounded-2xl p-6 w-fit mx-auto">
        {(logoUrl || logoEmoji || iconoTipoNegocio) && (
          <div className="mb-3 flex justify-center">
            {logoUrl
              ? <img src={logoUrl} alt="" className="h-12 w-12 object-contain rounded" />
              : logoEmoji
                ? <span className="text-4xl">{logoEmoji}</span>
                : iconoTipoNegocio?.startsWith('/')
                  ? <img src={iconoTipoNegocio} alt="" className="h-12 w-12 object-contain rounded" />
                  : <span className="text-4xl">{iconoTipoNegocio}</span>}
          </div>
        )}
        <QRCodeCanvas value={url} size={220} level="M" includeMargin />
      </div>

      <div className="flex flex-wrap justify-center gap-2 mt-4">
        <button onClick={descargar} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">⬇ Descargar QR</button>
        <button onClick={imprimir} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">🖨 Imprimir QR</button>
        <button onClick={() => window.open(url, '_blank')} className="rounded-lg bg-slate-700 px-4 py-2 text-sm">Abrir portal</button>
      </div>

      <div className="mt-6">
        <label className="text-sm text-slate-400">Enlace público</label>
        <div className="flex gap-2 mt-1">
          <input readOnly value={url} className="input" />
          <button onClick={copiar} className="rounded-lg bg-slate-700 px-4 text-sm whitespace-nowrap">{copiado ? '¡Copiado!' : 'Copiar'}</button>
        </div>
      </div>

      <div className="mt-6">
        <p className="text-sm text-slate-400 mb-2">Compartir por:</p>
        <div className="grid grid-cols-2 gap-2">
          {compartir.map((c) => (
            <a key={c.label} href={c.href} target="_blank" rel="noreferrer"
              className={`text-center rounded-lg ${c.color} hover:opacity-90 px-4 py-2 text-sm font-medium`}>{c.label}</a>
          ))}
        </div>
        <p className="text-xs text-slate-500 mt-3">Para Instagram, pega el enlace en tu biografía o historia (Instagram no permite compartir por URL directa).</p>
      </div>
    </div>
  )
}
