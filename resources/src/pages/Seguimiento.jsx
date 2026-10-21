import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api/client'

const fmtFecha = (iso) => iso ? new Date(iso).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' }) : null

// Estado visual: color y mensaje pensados para que el cliente entienda de un
// vistazo si ya puede ir a recoger su vehículo o no, sin tener que leer nada más.
const ESTADO_INFO = {
  recibido: { emoji: '📥', color: 'bg-slate-700', mensaje: 'Recibimos tu vehículo y ya está en cola.' },
  en_proceso: { emoji: '🔧', color: 'bg-amber-600', mensaje: 'Estamos trabajando en tu vehículo.' },
  secando: { emoji: '💧', color: 'bg-cyan-600', mensaje: 'Está en el último paso: secando.' },
  listo: { emoji: '✅', color: 'bg-emerald-600', mensaje: '¡Ya está listo! Puedes pasar a recogerlo.' },
  facturado: { emoji: '🧾', color: 'bg-emerald-700', mensaje: 'Entregado y facturado. ¡Gracias por tu visita!' },
  cancelado: { emoji: '✕', color: 'bg-red-800', mensaje: 'Esta orden fue cancelada.' },
}

// Página PÚBLICA de seguimiento (destino del QR que se entrega con el
// vehículo). No requiere cuenta ni login: solo el token propio de la orden.
export default function Seguimiento() {
  const { slug, token } = useParams()
  const [orden, setOrden] = useState(null)
  const [noExiste, setNoExiste] = useState(false)

  useEffect(() => {
    api(`/publico/${slug}/orden/${token}`).then(setOrden).catch(() => setNoExiste(true))
  }, [slug, token])

  if (noExiste) {
    return (
      <div className="min-h-screen bg-slate-900 text-slate-100 flex items-center justify-center px-4">
        <p className="text-center text-slate-400">No encontramos esa orden. Verifica el código QR o el enlace.</p>
      </div>
    )
  }

  if (!orden) {
    return <div className="min-h-screen bg-slate-900 text-slate-100 flex items-center justify-center">Cargando...</div>
  }

  const info = ESTADO_INFO[orden.estado] ?? ESTADO_INFO.recibido
  const v = orden.vehiculo

  return (
    <div className="min-h-screen bg-slate-900 text-slate-100 px-4 py-10">
      <div className="max-w-sm mx-auto text-center">
        <p className="text-sm text-slate-400 mb-1">Orden {orden.numero_orden}</p>
        {orden.cliente && <p className="text-lg font-semibold mb-4">Hola, {orden.cliente.split(' ')[0]}</p>}

        <div className={`rounded-2xl ${info.color} p-6 mb-4`}>
          <div className="text-5xl mb-2">{info.emoji}</div>
          <p className="text-xl font-bold">{orden.estado_label}</p>
          <p className="text-sm mt-1 opacity-90">{info.mensaje}</p>
        </div>

        {v && (
          <p className="text-slate-300 mb-1">
            {v.marca} {v.modelo}{v.placa_identificador ? ` · ${v.placa_identificador}` : ''}
          </p>
        )}

        <div className="text-xs text-slate-500 mt-4 space-y-0.5">
          {orden.fecha_recepcion && <p>Recibido: {fmtFecha(orden.fecha_recepcion)}</p>}
          {orden.fecha_entrega_estimada && !orden.fecha_entrega_real && <p>Entrega estimada: {fmtFecha(orden.fecha_entrega_estimada)}</p>}
          {orden.fecha_entrega_real && <p>Listo desde: {fmtFecha(orden.fecha_entrega_real)}</p>}
        </div>
      </div>
    </div>
  )
}
