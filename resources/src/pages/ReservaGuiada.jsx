import { useEffect, useState } from 'react'
import { api } from '../api/client'

const fmtHora = (iso) => new Date(iso).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })
const fmtFecha = (iso) => new Date(iso).toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' })
const COP = (n) => '$' + Number(n ?? 0).toLocaleString('es-CO')

function iconoCategoria(nombre) {
  const n = (nombre || '').toLowerCase()
  if (n.includes('barba')) return '🧔'
  if (n.includes('cabello') || n.includes('corte') || n.includes('pelo')) return '💇'
  if (n.includes('uña')) return '💅'
  if (n.includes('pestañ') || n.includes('ceja')) return '👁️'
  if (n.includes('facial') || n.includes('corporal')) return '🧖'
  if (n.includes('depila')) return '🪒'
  if (n.includes('masaje')) return '💆'
  return '✨'
}

const PASOS = ['Servicio', 'Especialista', 'Fecha y hora', 'Tus datos']

function Progreso({ paso }) {
  return (
    <div className="flex items-center gap-1.5 mb-6">
      {PASOS.map((label, i) => (
        <div key={label} className="flex-1">
          <div className={`h-1.5 rounded-full transition-colors ${i <= paso ? 'bg-emerald-500' : 'bg-slate-800'}`} />
          <p className={`text-[10px] mt-1 text-center ${i === paso ? 'text-emerald-400 font-semibold' : 'text-slate-600'}`}>{label}</p>
        </div>
      ))}
    </div>
  )
}

/**
 * Flujo público de reserva ULTRA simplificado para Barbería y Spa: guiado
 * paso a paso (servicio → especialista → fecha/hora → datos), pensado para
 * completarse desde el celular en menos de un minuto tras escanear el QR.
 */
export default function ReservaGuiada({ base, negocio, esTatuaje }) {
  const [paso, setPaso] = useState(0)
  const [servicios, setServicios] = useState([])
  const [serviciosSel, setServiciosSel] = useState([]) // array de objetos servicio elegidos
  const [profesionales, setProfesionales] = useState([])
  const [profesionalSel, setProfesionalSel] = useState(undefined) // undefined = aún no visto; null = "cualquiera"
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10))
  const [slots, setSlots] = useState(null)
  const [horaSel, setHoraSel] = useState(null)
  const [form, setForm] = useState({ nombre_completo: '', telefono: '', email: '', nota: '', zona_cuerpo: '', tamano_tatuaje: '' })
  const [imagenRefSel, setImagenRefSel] = useState(null) // url de la foto de referencia elegida (ej. corte deseado, de la galería)
  const [imagenPropia, setImagenPropia] = useState(null) // archivo que el cliente sube (ej. la idea de tatuaje que quiere)
  const [confirmada, setConfirmada] = useState(null)
  const [error, setError] = useState('')
  const [cargando, setCargando] = useState(false)

  useEffect(() => { api(`${base}/servicios`).then(setServicios).catch(() => {}) }, [base])
  useEffect(() => { api(`${base}/profesionales`).then(setProfesionales).catch(() => {}) }, [base])

  const duracionTotal = serviciosSel.reduce((s, x) => s + (x.duracion_min || 30), 0) || 30
  const totalPrecio = serviciosSel.reduce((s, x) => s + Number(x.precio || 0), 0)

  // Cualquier tipo de negocio guiado (barbería, spa, tatuajes) permite elegir
  // uno o varios servicios disponibles antes de continuar.
  function elegirServicio(s) {
    setServiciosSel((prev) => (prev.some((x) => x.id === s.id) ? prev.filter((x) => x.id !== s.id) : [...prev, s]))
  }

  async function buscarHorarios() {
    setCargando(true); setError(''); setSlots(null); setHoraSel(null)
    try {
      const params = new URLSearchParams({ fecha, duracion_min: String(duracionTotal) })
      if (profesionalSel) params.set('operables_employee_id', profesionalSel.id)
      const data = await api(`${base}/disponibilidad?${params}`)
      setSlots(data.slots ?? [])
    } catch (err) { setError(err.message || 'No se pudo cargar la disponibilidad.') }
    finally { setCargando(false) }
  }
  useEffect(() => { if (paso === 2) buscarHorarios() }, [paso, fecha, profesionalSel]) // eslint-disable-line react-hooks/exhaustive-deps

  async function confirmar(e) {
    e.preventDefault()
    setError(''); setCargando(true)
    try {
      const campos = {
        nombre_completo: form.nombre_completo,
        telefono: form.telefono,
        email: form.email || null,
        nota: form.nota || null,
        operables_employee_id: profesionalSel?.id || null,
        imagen_referencia_url: imagenPropia ? null : (imagenRefSel || null), // si sube su propia foto, esa manda
        zona_cuerpo: esTatuaje ? form.zona_cuerpo : null,
        tamano_tatuaje: esTatuaje ? form.tamano_tatuaje || null : null,
        inicio: horaSel,
      }
      if (serviciosSel.length > 1) {
        campos.servicios = serviciosSel.map((s) => ({ servicio_id: s.id, precio_unitario: s.precio, duracion_min: s.duracion_min }))
        campos.servicio_id = serviciosSel[0].id
      } else {
        campos.servicio_id = serviciosSel[0]?.id ?? null
      }

      let r
      if (imagenPropia) {
        // multipart: la foto propia del cliente viaja como archivo real, no como URL.
        const fd = new FormData()
        Object.entries(campos).forEach(([k, v]) => {
          if (v === null || v === undefined) return
          if (k === 'servicios') fd.append(k, JSON.stringify(v))
          else fd.append(k, v)
        })
        fd.append('imagen_referencia', imagenPropia)
        r = await api(`${base}/reservar`, { method: 'POST', body: fd, isForm: true })
      } else {
        r = await api(`${base}/reservar`, { method: 'POST', body: campos })
      }
      setConfirmada(r.cita)
    } catch (err) { setError(err.message || 'No se pudo confirmar la reserva.') }
    finally { setCargando(false) }
  }

  // --- Pantalla final: ticket digital ---
  if (confirmada) {
    const mensajeWa = `Hola, agendé una cita en ${negocio?.nombre ?? ''} para el ${fmtFecha(confirmada.inicio)} a las ${fmtHora(confirmada.inicio)}. ¡Confirmo mi asistencia!`
    const telNegocio = (negocio?.telefono || '').replace(/\D+/g, '')
    const ics = `BEGIN:VCALENDAR%0AVERSION:2.0%0ABEGIN:VEVENT%0ASUMMARY:${encodeURIComponent(serviciosSel.map((s) => s.nombre).join(' + ') || 'Cita')} - ${encodeURIComponent(negocio?.nombre ?? '')}%0ADTSTART:${new Date(confirmada.inicio).toISOString().replace(/[-:]/g, '').split('.')[0]}Z%0AEND:VEVENT%0AEND:VCALENDAR`

    return (
      <div className="rounded-2xl bg-gradient-to-b from-emerald-600/20 to-slate-900 border border-emerald-500/40 p-6 text-center">
        <div className="text-5xl mb-2">🎫</div>
        <h2 className="text-xl font-bold">¡Cita confirmada!</h2>
        <div className="mt-4 rounded-xl bg-slate-900/60 border border-dashed border-slate-700 p-4 text-left space-y-1.5">
          <p className="text-sm text-slate-400">Negocio</p>
          <p className="font-semibold mb-2">{negocio?.nombre}</p>
          <p className="text-sm text-slate-400">Servicio(s)</p>
          <p className="font-semibold mb-2">{serviciosSel.map((s) => s.nombre).join(' + ') || 'Servicio'}</p>
          {profesionalSel && (<><p className="text-sm text-slate-400">Especialista</p><p className="font-semibold mb-2">{profesionalSel.nombre} {profesionalSel.apellido}</p></>)}
          {confirmada.zona_cuerpo && (<><p className="text-sm text-slate-400">Zona / tamaño</p><p className="font-semibold mb-2">{confirmada.zona_cuerpo}{confirmada.tamano_tatuaje ? ` · ${confirmada.tamano_tatuaje}` : ''}</p></>)}
          <p className="text-sm text-slate-400">Fecha y hora</p>
          <p className="font-semibold capitalize">{fmtFecha(confirmada.inicio)} · {fmtHora(confirmada.inicio)}</p>
          {totalPrecio > 0 && <p className="text-emerald-400 font-bold mt-2">{COP(totalPrecio)}</p>}
          {confirmada.imagen_referencia_url && (
            <div className="mt-3">
              <p className="text-sm text-slate-400 mb-1">Tu foto de referencia</p>
              <img src={confirmada.imagen_referencia_url} alt="" className="h-20 w-20 rounded-lg object-cover" />
            </div>
          )}
        </div>
        <div className="grid grid-cols-1 gap-2 mt-4">
          <a href={`data:text/calendar;charset=utf8,${ics}`} download="cita.ics"
            className="rounded-lg bg-slate-700 hover:bg-slate-600 py-2.5 text-sm font-semibold">📅 Guardar en mi calendario</a>
          {telNegocio && (
            <a href={`https://wa.me/${telNegocio}?text=${encodeURIComponent(mensajeWa)}`} target="_blank" rel="noreferrer"
              className="rounded-lg bg-green-600 hover:bg-green-500 py-2.5 text-sm font-semibold">💬 Escribir al local por WhatsApp</a>
          )}
        </div>
        <button onClick={() => {
          setConfirmada(null); setPaso(0); setServiciosSel([]); setProfesionalSel(undefined)
          setSlots(null); setHoraSel(null); setForm({ nombre_completo: '', telefono: '', email: '', nota: '', zona_cuerpo: '', tamano_tatuaje: '' })
          setImagenRefSel(null); setImagenPropia(null)
        }} className="mt-4 text-sm text-slate-400 hover:text-white">Hacer otra reserva</button>
      </div>
    )
  }

  return (
    <div>
      <Progreso paso={paso} />
      {error && <div className="rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300 mb-3">{error}</div>}

      {/* Paso 1: Servicio */}
      {paso === 0 && (
        <div>
          <h2 className="text-lg font-semibold mb-1">✂️ Elige tu servicio</h2>
          <p className="text-xs text-slate-500 mb-3">Puedes elegir uno o varios.</p>
          <div className="grid grid-cols-2 gap-2 mb-4">
            {servicios.map((s) => {
              const elegido = serviciosSel.some((x) => x.id === s.id)
              return (
                <button key={s.id} onClick={() => elegirServicio(s)}
                  className={`text-left rounded-xl border overflow-hidden transition ${elegido ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-700 bg-slate-800/50 hover:bg-slate-800'}`}>
                  {s.imagen ? (
                    <img src={s.imagen} alt="" className="h-20 w-full object-cover" onError={(e) => { e.currentTarget.style.display = 'none' }} />
                  ) : (
                    <div className="h-20 w-full flex items-center justify-center text-2xl bg-slate-900/50">{s.icono || iconoCategoria(s.categoria?.nombre || s.nombre)}</div>
                  )}
                  <div className="p-2.5">
                    <p className="text-sm font-semibold leading-tight">{s.nombre}</p>
                    <p className="text-xs text-slate-400 mt-1">{COP(s.precio)}</p>
                    {elegido && <p className="text-xs text-emerald-400 mt-1 font-semibold">✓ Elegido</p>}
                  </div>
                </button>
              )
            })}
            {servicios.length === 0 && <p className="col-span-2 text-slate-500 text-sm text-center py-6">Aún no hay servicios disponibles.</p>}
          </div>
          {serviciosSel.length > 0 && (
            <button onClick={() => setPaso(1)} className="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 py-2.5 text-sm font-semibold">
              Continuar con {serviciosSel.length} servicio(s) →
            </button>
          )}
        </div>
      )}

      {/* Paso 2: Especialista */}
      {paso === 1 && (
        <div>
          <button onClick={() => setPaso(0)} className="text-xs text-sky-400 hover:underline mb-3">← Cambiar servicio</button>
          <h2 className="text-lg font-semibold mb-3">💈 Elige tu especialista</h2>
          <div className="grid gap-2 mb-4">
            <button onClick={() => { setProfesionalSel(null); setPaso(2) }}
              className="flex items-center gap-3 text-left rounded-xl border border-slate-700 bg-slate-800/50 hover:bg-slate-800 p-3 transition">
              <div className="h-11 w-11 rounded-full bg-slate-700 flex items-center justify-center text-xl shrink-0">🤝</div>
              <div>
                <p className="font-semibold text-sm">Cualquier profesional disponible</p>
                <p className="text-xs text-slate-400">Te asignamos al primero libre</p>
              </div>
            </button>
            {profesionales.map((p) => (
              <button key={p.id} onClick={() => { setProfesionalSel(p); setPaso(2) }}
                className="flex items-center gap-3 text-left rounded-xl border border-slate-700 bg-slate-800/50 hover:bg-slate-800 p-3 transition">
                <div className="h-11 w-11 rounded-full bg-emerald-700 flex items-center justify-center text-sm font-bold shrink-0">
                  {(p.nombre?.[0] ?? '').toUpperCase()}{(p.apellido?.[0] ?? '').toUpperCase()}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="font-semibold text-sm">{p.nombre} {p.apellido}</p>
                  {p.especialidad && <p className="text-xs text-slate-400">{p.especialidad}</p>}
                  {p.galeria?.length > 0 && (
                    <div className="flex gap-1 mt-1">
                      {p.galeria.slice(0, 4).map((f) => (
                        <img key={f.id} src={f.url} alt="" className="h-8 w-8 rounded object-cover" />
                      ))}
                    </div>
                  )}
                </div>
              </button>
            ))}
            {profesionales.length === 0 && <p className="text-slate-500 text-sm">Este negocio aún no registró especialistas; te asignaremos uno automáticamente.</p>}
          </div>
        </div>
      )}

      {/* Paso 3: Fecha y hora */}
      {paso === 2 && (
        <div>
          <button onClick={() => setPaso(1)} className="text-xs text-sky-400 hover:underline mb-3">← Cambiar especialista</button>
          <h2 className="text-lg font-semibold mb-3">📅 Elige fecha y hora</h2>
          <input type="date" value={fecha} min={new Date().toISOString().slice(0, 10)}
            onChange={(e) => setFecha(e.target.value)} className="input mb-3" />

          {cargando && <p className="text-slate-500 text-sm">Buscando horarios…</p>}
          {slots && slots.length > 0 && (
            <div className="grid grid-cols-3 gap-2 mb-4">
              {slots.map((s, i) => (
                <button key={i} disabled={!s.disponible} onClick={() => setHoraSel(s.inicio)}
                  className={`text-sm rounded-lg py-2 transition ${
                    horaSel === s.inicio ? 'bg-emerald-500 ring-2 ring-white'
                    : s.disponible ? 'bg-emerald-700 hover:bg-emerald-600' : 'bg-slate-700 opacity-40 line-through cursor-not-allowed'}`}>
                  {fmtHora(s.inicio)}
                </button>
              ))}
            </div>
          )}
          {slots && slots.length === 0 && !cargando && <p className="text-slate-500 text-sm mb-4">No hay horarios ese día. Prueba otra fecha.</p>}

          {horaSel && (
            <button onClick={() => setPaso(3)} className="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 py-2.5 text-sm font-semibold">
              Continuar →
            </button>
          )}
        </div>
      )}

      {/* Paso 4: Datos de contacto */}
      {paso === 3 && (
        <form onSubmit={confirmar} className="space-y-3">
          <button type="button" onClick={() => setPaso(2)} className="text-xs text-sky-400 hover:underline">← Cambiar hora</button>
          <h2 className="text-lg font-semibold">📝 Tus datos</h2>

          <div className="rounded-lg border border-slate-700 bg-slate-800/40 px-3 py-2 text-sm text-slate-300">
            {serviciosSel.map((s) => s.nombre).join(' + ')} · {profesionalSel ? `${profesionalSel.nombre} ${profesionalSel.apellido}` : 'Cualquier profesional'} · {horaSel && `${fmtFecha(horaSel)} ${fmtHora(horaSel)}`}
          </div>

          {/* Foto de referencia (ej. el corte de cabello que le gustó): solo aplica
              cuando hay un único servicio y ese servicio tiene fotos en su galería. */}
          {serviciosSel.length === 1 && serviciosSel[0]?.galeria?.length > 0 && (
            <div>
              <p className="text-sm font-medium mb-1">📸 ¿Viste algo que te gustó? Elige una referencia (opcional)</p>
              <div className="flex flex-wrap gap-2">
                {serviciosSel[0].galeria.map((f) => (
                  <button type="button" key={f.id} onClick={() => { setImagenRefSel(imagenRefSel === f.url ? null : f.url); setImagenPropia(null) }}
                    className={`rounded-lg overflow-hidden border-2 transition ${imagenRefSel === f.url ? 'border-emerald-500' : 'border-transparent'}`}>
                    <img src={f.url} alt="" className="h-16 w-16 object-cover" />
                  </button>
                ))}
              </div>
            </div>
          )}

          {esTatuaje && (
            <>
              <input required placeholder="Zona del cuerpo (ej. Antebrazo)" value={form.zona_cuerpo}
                onChange={(e) => setForm({ ...form, zona_cuerpo: e.target.value })} className="input" />
              <input placeholder="Tamaño aproximado (ej. 10x10 cm)" value={form.tamano_tatuaje}
                onChange={(e) => setForm({ ...form, tamano_tatuaje: e.target.value })} className="input" />
              <label className="block">
                <span className="text-sm font-medium mb-1 block">📸 Sube la idea/foto de tu tatuaje (opcional)</span>
                <input type="file" accept="image/*" className="input"
                  onChange={(e) => { setImagenPropia(e.target.files?.[0] ?? null); setImagenRefSel(null) }} />
                {imagenPropia && <span className="text-xs text-emerald-400 mt-1 block">✓ {imagenPropia.name}</span>}
              </label>
            </>
          )}

          <input required placeholder="Tu nombre completo" value={form.nombre_completo}
            onChange={(e) => setForm({ ...form, nombre_completo: e.target.value })} className="input" />
          <input required type="tel" placeholder="WhatsApp / Teléfono" value={form.telefono}
            onChange={(e) => setForm({ ...form, telefono: e.target.value })} className="input" />
          <input type="email" placeholder="Correo (opcional)" value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })} className="input" />
          <textarea placeholder="Nota para el especialista (opcional)" rows="2" value={form.nota}
            onChange={(e) => setForm({ ...form, nota: e.target.value })} className="input" />

          <button disabled={cargando} className="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 py-2.5 text-sm font-semibold">
            {cargando ? 'Confirmando…' : '✅ Confirmar cita'}
          </button>
        </form>
      )}
    </div>
  )
}
