import { useRef, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'

// Propietario del negocio (dueño de la cuenta SaaS): puede cambiar el logo del negocio.
const esPropietario = (user) =>
  !!user?.es_super_admin || !!user?.empresa_info?.es_admin_empresa ||
  ['Administrador', 'Usuario'].includes(user?.rol?.nombre)

// Comprime la imagen en el navegador antes de subirla: mantiene el ancho/alto original
// (misma resolución) y solo reduce la calidad JPEG/WebP hasta acercarse al peso objetivo.
// Así una foto de cámara de 6-8 MB llega al servidor pesando unos cientos de KB.
async function comprimirImagen(file, { objetivoKB = 417, calidadMinima = 0.5 } = {}) {
  if (!file.type?.startsWith('image/')) return file
  const objetivoBytes = objetivoKB * 1024
  if (file.size <= objetivoBytes) return file

  try {
    const bitmap = await createImageBitmap(file)
    const canvas = document.createElement('canvas')
    canvas.width = bitmap.width
    canvas.height = bitmap.height
    canvas.getContext('2d').drawImage(bitmap, 0, 0)

    const aCalidad = (calidad) => new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', calidad))

    let calidad = 0.9
    let blob = await aCalidad(calidad)
    while (blob && blob.size > objetivoBytes && calidad > calidadMinima) {
      calidad -= 0.1
      blob = await aCalidad(calidad)
    }

    if (!blob || blob.size >= file.size) return file
    const nombre = file.name.replace(/\.[^.]+$/, '') + '.webp'
    return new File([blob], nombre, { type: 'image/webp' })
  } catch {
    return file // si el navegador no soporta la compresión, se sube el original (el backend valida el peso máximo)
  }
}

export default function Perfil() {
  const { user, setUser } = useAuth()
  const fileInput = useRef(null)
  const logoInput = useRef(null)
  const [name, setName] = useState(user?.name ?? '')
  const [telefono, setTelefono] = useState(user?.telefono ?? '')
  const [tipoDoc, setTipoDoc] = useState(user?.tipo_documento ?? 'CC')
  const [numDoc, setNumDoc] = useState(user?.numero_documento ?? '')
  const [msg, setMsg] = useState('')
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)
  const [subiendo, setSubiendo] = useState(false)
  const [subiendoLogo, setSubiendoLogo] = useState(false)
  const [logoEmoji, setLogoEmoji] = useState(user?.empresa_info?.logo_emoji ?? '')
  const [guardandoEmoji, setGuardandoEmoji] = useState(false)

  // Cambio de contraseña
  const [pwd, setPwd] = useState({ actual: '', nueva: '', confirmar: '' })
  const [pwdMsg, setPwdMsg] = useState('')
  const [pwdError, setPwdError] = useState('')
  const [cambiandoPwd, setCambiandoPwd] = useState(false)

  async function guardarDatos(e) {
    e.preventDefault()
    setMsg(''); setError(''); setGuardando(true)
    try {
      const actualizado = await api('/perfil', { method: 'PUT', body: { name, telefono, tipo_documento: tipoDoc, numero_documento: numDoc } })
      setUser(actualizado)
      setMsg('Datos actualizados.')
    } catch (err) {
      setError(err.message || 'No se pudo guardar.')
    } finally {
      setGuardando(false)
    }
  }

  async function cambiarPassword(e) {
    e.preventDefault()
    setPwdMsg(''); setPwdError('')
    if (pwd.nueva !== pwd.confirmar) { setPwdError('Las contraseñas nuevas no coinciden.'); return }
    setCambiandoPwd(true)
    try {
      await api('/perfil/password', { method: 'PUT', body: {
        password_actual: pwd.actual, password: pwd.nueva, password_confirmation: pwd.confirmar,
      } })
      setPwd({ actual: '', nueva: '', confirmar: '' })
      setPwdMsg('Contraseña actualizada correctamente.')
    } catch (err) {
      setPwdError(err.message || 'No se pudo cambiar la contraseña.')
    } finally {
      setCambiandoPwd(false)
    }
  }

  async function subirFoto(e) {
    const file = e.target.files?.[0]
    if (!file) return
    setMsg(''); setError(''); setSubiendo(true)
    try {
      const comprimido = await comprimirImagen(file)
      const form = new FormData()
      form.append('foto', comprimido)
      const data = await api('/perfil/foto', { method: 'POST', body: form, isForm: true })
      setUser(data.user)
      setMsg('Foto actualizada.')
    } catch (err) {
      setError(err.message || 'No se pudo subir la foto.')
    } finally {
      setSubiendo(false)
    }
  }

  async function subirLogo(e) {
    const file = e.target.files?.[0]
    if (!file) return
    setMsg(''); setError(''); setSubiendoLogo(true)
    try {
      const comprimido = await comprimirImagen(file)
      const form = new FormData()
      form.append('logo', comprimido)
      const data = await api('/perfil/logo-empresa', { method: 'POST', body: form, isForm: true })
      setUser({ ...user, empresa_info: { ...user.empresa_info, logo_url: data.logo_url } })
      setMsg('Logo del negocio actualizado.')
    } catch (err) {
      setError(err.message || 'No se pudo subir el logo.')
    } finally {
      setSubiendoLogo(false)
    }
  }

  async function guardarLogoEmoji() {
    setMsg(''); setError(''); setGuardandoEmoji(true)
    try {
      const data = await api('/perfil/logo-emoji', { method: 'PUT', body: { logo_emoji: logoEmoji || null } })
      setUser({ ...user, empresa_info: { ...user.empresa_info, logo_emoji: data.logo_emoji } })
      setMsg(data.logo_emoji ? 'Emoji del negocio guardado.' : 'Emoji quitado.')
    } catch (err) {
      setError(err.message || 'No se pudo guardar el emoji.')
    } finally {
      setGuardandoEmoji(false)
    }
  }

  return (
    <div className="max-w-lg mx-auto">
      <h1 className="text-2xl font-bold text-white mb-6">Mi perfil</h1>

      {msg && <div className="mb-4 rounded-lg bg-emerald-500/10 border border-emerald-500/40 px-3 py-2 text-sm text-emerald-300">{msg}</div>}
      {error && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{error}</div>}

      {/* Foto de perfil */}
      <div className="flex items-center gap-4 mb-8">
        <div className="h-20 w-20 rounded-full bg-slate-700 overflow-hidden flex items-center justify-center text-2xl text-slate-300">
          {user?.foto_perfil_url
            ? <img src={user.foto_perfil_url} alt="Foto de perfil" className="h-full w-full object-cover" />
            : (user?.name?.[0] ?? '?')}
        </div>
        <div>
          {/* capture="user" abre la cámara frontal en móviles (PWA) */}
          <input
            ref={fileInput}
            type="file"
            accept="image/*"
            capture="user"
            onChange={subirFoto}
            className="hidden"
          />
          <button
            type="button"
            onClick={() => fileInput.current?.click()}
            disabled={subiendo}
            className="rounded-lg bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-white text-sm px-4 py-2"
          >
            {subiendo ? 'Subiendo…' : 'Cambiar foto'}
          </button>
          <p className="text-xs text-slate-500 mt-1">Archivo o cámara · se optimiza automáticamente a ~400 KB</p>
        </div>
      </div>

      {/* Logo del negocio: solo el propietario, se muestra en el portal público y el QR de reserva */}
      {esPropietario(user) && (
        <div className="mb-8 pb-8 border-b border-slate-800">
          <div className="flex items-center gap-4">
            <div className="h-20 w-20 rounded-lg bg-slate-700 overflow-hidden flex items-center justify-center text-3xl text-slate-300">
              {user?.empresa_info?.logo_url
                ? <img src={user.empresa_info.logo_url} alt="Logo del negocio" className="h-full w-full object-cover" />
                : (user?.empresa_info?.logo_emoji || '🏢')}
            </div>
            <div>
              <input ref={logoInput} type="file" accept="image/*" onChange={subirLogo} className="hidden" />
              <button
                type="button"
                onClick={() => logoInput.current?.click()}
                disabled={subiendoLogo}
                className="rounded-lg bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-white text-sm px-4 py-2"
              >
                {subiendoLogo ? 'Subiendo…' : 'Cambiar logo del negocio'}
              </button>
              <p className="text-xs text-slate-500 mt-1">Se muestra en tu portal de reservas y en el QR · se optimiza automáticamente a ~400 KB</p>
            </div>
          </div>

          <div className="mt-4 flex items-center gap-2">
            <input value={logoEmoji} onChange={(e) => setLogoEmoji(e.target.value)} placeholder="🧖 o 💅 (opcional)"
              maxLength={20} className="w-40 rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white text-lg focus:outline-none focus:border-emerald-500" />
            <button type="button" onClick={guardarLogoEmoji} disabled={guardandoEmoji}
              className="rounded-lg bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-white text-sm px-4 py-2 whitespace-nowrap">
              {guardandoEmoji ? 'Guardando…' : 'Guardar emoji'}
            </button>
          </div>
          <p className="text-xs text-slate-500 mt-1">¿No tienes un logo? Elige un emoji que represente tu negocio; se usa mientras no subas una imagen.</p>
        </div>
      )}

      {/* Datos básicos */}
      <form onSubmit={guardarDatos} className="space-y-4">
        <div>
          <label className="block text-sm text-slate-300 mb-1">Nombre</label>
          <input value={name} onChange={(e) => setName(e.target.value)}
            className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" />
        </div>
        <div>
          <label className="block text-sm text-slate-300 mb-1">Celular</label>
          <input value={telefono} onChange={(e) => setTelefono(e.target.value)}
            className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" />
        </div>
        <div className="grid grid-cols-3 gap-2">
          <div>
            <label className="block text-sm text-slate-300 mb-1">Tipo doc.</label>
            <select value={tipoDoc} onChange={(e) => setTipoDoc(e.target.value)}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
              <option value="CC">CC</option><option value="CE">CE</option><option value="NIT">NIT</option><option value="PAS">PAS</option>
            </select>
          </div>
          <div className="col-span-2">
            <label className="block text-sm text-slate-300 mb-1">N° documento</label>
            <input value={numDoc} onChange={(e) => setNumDoc(e.target.value)}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" />
          </div>
        </div>
        <div>
          <label className="block text-sm text-slate-300 mb-1">Correo</label>
          <input value={user?.email ?? ''} disabled
            className="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-slate-400" />
        </div>
        <div>
          <label className="block text-sm text-slate-300 mb-1">Rol</label>
          <input value={user?.rol?.nombre ?? 'Sin rol'} disabled
            className="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-slate-400" />
        </div>
        <button type="submit" disabled={guardando}
          className="rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold px-5 py-2">
          {guardando ? 'Guardando…' : 'Guardar cambios'}
        </button>
      </form>

      {/* Cambio de contraseña */}
      <div className="mt-10 pt-8 border-t border-slate-800">
        <h2 className="text-lg font-bold text-white mb-4">Cambiar contraseña</h2>
        {pwdMsg && <div className="mb-4 rounded-lg bg-emerald-500/10 border border-emerald-500/40 px-3 py-2 text-sm text-emerald-300">{pwdMsg}</div>}
        {pwdError && <div className="mb-4 rounded-lg bg-red-500/10 border border-red-500/40 px-3 py-2 text-sm text-red-300">{pwdError}</div>}
        <form onSubmit={cambiarPassword} className="space-y-4">
          <div>
            <label className="block text-sm text-slate-300 mb-1">Contraseña actual</label>
            <input type="password" value={pwd.actual} onChange={(e) => setPwd({ ...pwd, actual: e.target.value })}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" required />
          </div>
          <div>
            <label className="block text-sm text-slate-300 mb-1">Nueva contraseña</label>
            <input type="password" value={pwd.nueva} onChange={(e) => setPwd({ ...pwd, nueva: e.target.value })}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" required minLength={8} />
          </div>
          <div>
            <label className="block text-sm text-slate-300 mb-1">Repite la nueva contraseña</label>
            <input type="password" value={pwd.confirmar} onChange={(e) => setPwd({ ...pwd, confirmar: e.target.value })}
              className="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-white focus:outline-none focus:border-emerald-500" required minLength={8} />
          </div>
          <button type="submit" disabled={cambiandoPwd}
            className="rounded-lg bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white font-semibold px-5 py-2">
            {cambiandoPwd ? 'Actualizando…' : 'Cambiar contraseña'}
          </button>
        </form>
      </div>
    </div>
  )
}
