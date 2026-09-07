import { useState, useEffect } from 'react'

export default function Calculadora() {
  const [expr, setExpr] = useState('')
  const [resultado, setResultado] = useState('')

  function pulsar(v) { setExpr(expr + v) }
  function limpiar() { setExpr(''); setResultado('') }
  function borrar() { setExpr(expr.slice(0, -1)) }

  function calcular() {
    try {
      // Sólo permite dígitos y operadores básicos (evita evaluar código arbitrario).
      if (!/^[0-9+\-*/.()%\s]*$/.test(expr)) throw new Error()
      const limpio = expr.replace(/%/g, '/100')
      // eslint-disable-next-line no-new-func
      const r = Function(`"use strict";return (${limpio})`)()
      setResultado(String(r))
    } catch {
      setResultado('Error')
    }
  }

  useEffect(() => {
    const onKey = (e) => {
      if (/[0-9+\-*/.()%]/.test(e.key)) pulsar(e.key)
      else if (e.key === 'Enter' || e.key === '=') calcular()
      else if (e.key === 'Backspace') borrar()
      else if (e.key === 'Escape') limpiar()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }) // eslint-disable-line

  const botones = ['7', '8', '9', '/', '4', '5', '6', '*', '1', '2', '3', '-', '0', '.', '%', '+']

  return (
    <div className="max-w-xs mx-auto">
      <h1 className="text-2xl font-bold mb-4">Calculadora</h1>
      <div className="rounded-xl bg-slate-800 p-4">
        <div className="text-right text-slate-400 text-sm h-5 break-all">{expr || '0'}</div>
        <div className="text-right text-3xl font-bold mb-3 break-all">{resultado || '0'}</div>
        <div className="grid grid-cols-4 gap-2">
          <button onClick={limpiar} className="col-span-2 py-3 rounded-lg bg-red-600 hover:bg-red-500 font-semibold">C</button>
          <button onClick={borrar} className="py-3 rounded-lg bg-slate-600 hover:bg-slate-500">⌫</button>
          <button onClick={calcular} className="py-3 rounded-lg bg-emerald-600 hover:bg-emerald-500 font-semibold">=</button>
          {botones.map((b) => (
            <button key={b} onClick={() => pulsar(b)}
              className={`py-3 rounded-lg font-medium ${/[+\-*/%]/.test(b) ? 'bg-sky-700 hover:bg-sky-600' : 'bg-slate-700 hover:bg-slate-600'}`}>{b}</button>
          ))}
        </div>
      </div>
      <p className="text-xs text-slate-500 mt-3">Tip: también puedes escribir con el teclado. % divide entre 100.</p>
    </div>
  )
}
