import { createContext, useCallback, useContext, useState } from 'react'

const ToastContext = createContext(null)

const ESTILOS = {
  exito: 'bg-emerald-600',
  error: 'bg-red-600',
  info: 'bg-slate-700',
}
const ICONOS = { exito: '✓', error: '⚠️', info: 'ℹ️' }

// Reemplaza los alert() de "listo/error" por una notificación que desaparece
// sola, en vez de un cuadro que bloquea la pantalla hasta que el usuario
// haga clic en "Aceptar".
export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const mostrar = useCallback((mensaje, tipo = 'info', duracionMs = 4000) => {
    const id = Date.now() + Math.random()
    setToasts((t) => [...t, { id, mensaje, tipo }])
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), duracionMs)
  }, [])

  const exito = useCallback((mensaje) => mostrar(mensaje, 'exito'), [mostrar])
  const error = useCallback((mensaje) => mostrar(mensaje, 'error', 6000), [mostrar])

  return (
    <ToastContext.Provider value={{ mostrar, exito, error }}>
      {children}
      <div className="fixed bottom-4 right-4 z-[110] flex flex-col gap-2 w-80 max-w-[90vw]">
        {toasts.map((t) => (
          <div key={t.id} className={`flex items-start gap-2 rounded-xl ${ESTILOS[t.tipo]} text-white shadow-xl p-3 text-sm animate-[fadeIn_0.15s_ease-out]`}>
            <span className="leading-none">{ICONOS[t.tipo]}</span>
            <span className="flex-1 whitespace-pre-line">{t.mensaje}</span>
            <button onClick={() => setToasts((cur) => cur.filter((x) => x.id !== t.id))} className="leading-none opacity-70 hover:opacity-100">✕</button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error('useToast debe usarse dentro de <ToastProvider>')
  return ctx
}
