import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'

const DialogoContext = createContext(null)

// Reemplaza confirm()/prompt()/alert() del navegador por ventanas propias,
// consistentes con el resto de la app (en vez del cuadro gris del sistema
// operativo). La API es intencionalmente parecida a la nativa (basada en
// promesas) para que el resto del código cambie lo mínimo posible:
//   confirm(msg)      -> await confirmar({ mensaje: msg })
//   prompt(msg, def)   -> await preguntar({ mensaje: msg, valorInicial: def })
//   alert(msg)         -> await avisar(msg)
export function DialogoProvider({ children }) {
  const [estado, setEstado] = useState(null) // null | { tipo, ...opciones, resolve }
  const [valor, setValor] = useState('')
  const inputRef = useRef(null)

  const cerrar = useCallback((resultado) => {
    setEstado((actual) => {
      actual?.resolve(resultado)
      return null
    })
  }, [])

  const confirmar = useCallback((opciones) => {
    if (typeof opciones === 'string') opciones = { mensaje: opciones }
    return new Promise((resolve) => setEstado({ tipo: 'confirmar', resolve, ...opciones }))
  }, [])

  const preguntar = useCallback((opciones) => {
    if (typeof opciones === 'string') opciones = { mensaje: opciones }
    setValor(opciones.valorInicial ?? '')
    return new Promise((resolve) => {
      setEstado({ tipo: 'preguntar', resolve, ...opciones })
      setTimeout(() => inputRef.current?.focus(), 50)
    })
  }, [])

  const avisar = useCallback((opciones) => {
    if (typeof opciones === 'string') opciones = { mensaje: opciones }
    return new Promise((resolve) => setEstado({ tipo: 'avisar', resolve, ...opciones }))
  }, [])

  function onSubmitPreguntar(e) {
    e.preventDefault()
    cerrar(valor)
  }

  // Escape cierra como cancelar (igual que cualquier modal): en "avisar" no
  // hay nada que cancelar, así que Escape confirma el único botón que hay.
  useEffect(() => {
    if (!estado) return
    function onKeyDown(e) {
      if (e.key === 'Escape') cerrar(estado.tipo === 'confirmar' ? false : estado.tipo === 'preguntar' ? null : true)
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [estado, cerrar])

  return (
    <DialogoContext.Provider value={{ confirmar, preguntar, avisar }}>
      {children}

      {estado && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 p-4"
          onClick={() => estado.tipo !== 'avisar' && cerrar(estado.tipo === 'confirmar' ? false : null)}>
          <div className="w-full max-w-sm rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <div className="px-5 pt-5 pb-2">
              {estado.titulo && <h2 className="text-base font-semibold text-white">{estado.titulo}</h2>}
              <p className="mt-1 text-sm text-slate-300 whitespace-pre-line">{estado.mensaje}</p>
            </div>

            {estado.tipo === 'preguntar' && (
              <form onSubmit={onSubmitPreguntar} className="px-5 pb-5 pt-2">
                <input
                  ref={inputRef}
                  type={estado.tipoInput ?? (estado.numerico ? 'number' : 'text')}
                  value={valor}
                  onChange={(e) => setValor(e.target.value)}
                  placeholder={estado.placeholder}
                  className="input"
                />
                <div className="mt-4 flex justify-end gap-2">
                  <button type="button" onClick={() => cerrar(null)} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">
                    {estado.cancelarTexto ?? 'Cancelar'}
                  </button>
                  <button type="submit" className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">
                    {estado.confirmarTexto ?? 'Guardar'}
                  </button>
                </div>
              </form>
            )}

            {estado.tipo === 'confirmar' && (
              <div className="flex justify-end gap-2 px-5 pb-5 pt-3">
                <button type="button" onClick={() => cerrar(false)} className="rounded-lg bg-slate-700 hover:bg-slate-600 px-4 py-2 text-sm">
                  {estado.cancelarTexto ?? 'Cancelar'}
                </button>
                <button type="button" onClick={() => cerrar(true)}
                  className={`rounded-lg px-4 py-2 text-sm font-semibold ${estado.peligroso ? 'bg-red-600 hover:bg-red-500' : 'bg-emerald-600 hover:bg-emerald-500'}`}>
                  {estado.confirmarTexto ?? 'Confirmar'}
                </button>
              </div>
            )}

            {estado.tipo === 'avisar' && (
              <div className="flex justify-end px-5 pb-5 pt-3">
                <button type="button" onClick={() => cerrar(true)} className="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-semibold">
                  {estado.confirmarTexto ?? 'Entendido'}
                </button>
              </div>
            )}
          </div>
        </div>
      )}
    </DialogoContext.Provider>
  )
}

export function useDialogo() {
  const ctx = useContext(DialogoContext)
  if (!ctx) throw new Error('useDialogo debe usarse dentro de <DialogoProvider>')
  return ctx
}
