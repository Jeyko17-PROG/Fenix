import { useEffect, useRef, useState } from 'react'

/**
 * Pad de firma digital reutilizable.
 * - Dibujo con mouse / pantalla táctil / lápiz (Pointer Events).
 * - Subir imagen PNG / JPG / JPEG.
 * - Emite la firma como data URL (PNG) vía onChange; null al limpiar.
 *
 * Props: value (data URL controlado) · onChange(dataUrl|null)
 */
export default function FirmaPad({ value, onChange }) {
  const canvasRef = useRef(null)
  const dibujando = useRef(false)
  const ultimo = useRef(null)        // última firma emitida (evita redibujar en bucle)
  const [tieneFirma, setTieneFirma] = useState(false)

  function ctx() {
    const c = canvasRef.current
    const g = c.getContext('2d')
    g.lineWidth = 2.2
    g.lineCap = 'round'
    g.lineJoin = 'round'
    g.strokeStyle = '#0f172a'
    return g
  }

  function coords(e) {
    const c = canvasRef.current
    const r = c.getBoundingClientRect()
    return {
      x: (e.clientX - r.left) * (c.width / r.width),
      y: (e.clientY - r.top) * (c.height / r.height),
    }
  }

  function emitir() {
    const url = canvasRef.current.toDataURL('image/png')
    ultimo.current = url
    setTieneFirma(true)
    onChange?.(url)
  }

  function inicio(e) {
    e.preventDefault()
    dibujando.current = true
    const { x, y } = coords(e)
    const g = ctx()
    g.beginPath()
    g.moveTo(x, y)
  }
  function mover(e) {
    if (!dibujando.current) return
    e.preventDefault()
    const { x, y } = coords(e)
    const g = ctx()
    g.lineTo(x, y)
    g.stroke()
  }
  function fin() {
    if (!dibujando.current) return
    dibujando.current = false
    emitir()
  }

  function limpiar() {
    const c = canvasRef.current
    c.getContext('2d').clearRect(0, 0, c.width, c.height)
    ultimo.current = null
    setTieneFirma(false)
    onChange?.(null)
  }

  function subir(e) {
    const file = e.target.files?.[0]
    if (!file) return
    if (!/^image\/(png|jpe?g)$/.test(file.type)) {
      alert('Formato no válido. Usa PNG, JPG o JPEG.')
      return
    }
    const reader = new FileReader()
    reader.onload = () => dibujarImagen(reader.result, true)
    reader.readAsDataURL(file)
    e.target.value = ''
  }

  // Dibuja una imagen (data URL) ajustada dentro del canvas.
  function dibujarImagen(dataUrl, emitirDespues) {
    const c = canvasRef.current
    if (!c) return
    const img = new Image()
    img.onload = () => {
      const g = c.getContext('2d')
      g.clearRect(0, 0, c.width, c.height)
      const escala = Math.min(c.width / img.width, c.height / img.height)
      const w = img.width * escala
      const h = img.height * escala
      g.drawImage(img, (c.width - w) / 2, (c.height - h) / 2, w, h)
      setTieneFirma(true)
      if (emitirDespues) emitir()
      else ultimo.current = dataUrl
    }
    img.src = dataUrl
  }

  // Carga una firma controlada externa (ej. "mi firma" guardada) sin bucle.
  useEffect(() => {
    if (value && value !== ultimo.current) dibujarImagen(value, false)
    if (!value && ultimo.current) limpiar()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value])

  return (
    <div>
      <div className="rounded-xl border border-slate-700 bg-white p-2">
        <canvas
          ref={canvasRef}
          width={500}
          height={160}
          className="w-full cursor-crosshair rounded-lg"
          style={{ touchAction: 'none', height: '160px' }}
          onPointerDown={inicio}
          onPointerMove={mover}
          onPointerUp={fin}
          onPointerLeave={fin}
        />
      </div>
      <div className="mt-2 flex flex-wrap items-center gap-3 text-sm">
        <span className="text-slate-400">Dibuja con el mouse o el dedo</span>
        <label className="cursor-pointer text-sky-400 hover:text-sky-300">
          Subir imagen (PNG/JPG)
          <input type="file" accept="image/png,image/jpeg" onChange={subir} className="hidden" />
        </label>
        {tieneFirma && (
          <button type="button" onClick={limpiar} className="text-red-400 hover:text-red-300">Limpiar</button>
        )}
      </div>
    </div>
  )
}
