// Parseo de números escritos en formato colombiano (es-CO).
//
//   aNumero('400.000')      -> 400000   (punto = miles)
//   aNumero('1.200.000')    -> 1200000
//   aNumero('1.200.000,50') -> 1200000.5
//   aNumero('400,5')        -> 400.5    (coma = decimal)
//   aNumero('400.50')       -> 400.5    (1-2 dígitos tras el punto: decimal)
//   aNumero('0.500')        -> 0.5      (parte entera 0: decimal)
//   aNumero(1500)           -> 1500
//
// Úsala SIEMPRE antes de calcular o enviar precios, costos, cantidades y montos.
export function aNumero(valor) {
  if (typeof valor === 'number') return Number.isFinite(valor) ? valor : 0
  let s = String(valor ?? '').trim()
  if (!s) return 0

  s = s.replace(/[^\d.,-]/g, '') // quita $, espacios y otros símbolos

  const puntos = (s.match(/\./g) || []).length
  const comas = (s.match(/,/g) || []).length

  if (puntos && comas) {
    // 1.200.000,50 → puntos miles, coma decimal
    s = s.replace(/\./g, '').replace(',', '.')
  } else if (comas) {
    const partes = s.split(',')
    // una coma con 1-2 decimales → decimal; si no, eran miles
    s = comas === 1 && partes[1].length <= 2 ? s.replace(',', '.') : s.replace(/,/g, '')
  } else if (puntos) {
    const partes = s.split('.')
    const entera = partes[0].replace('-', '')
    // varios puntos (1.200.000), o UN punto con 3 dígitos y parte entera ≠ 0
    // (400.000 = cuatrocientos mil en COP) → los puntos son miles
    if (puntos > 1 || (partes[1].length === 3 && entera !== '0' && entera !== '')) {
      s = s.replace(/\./g, '')
    }
  }

  const n = parseFloat(s)
  return Number.isFinite(n) ? n : 0
}

// Formatea a pesos colombianos para mostrar: 400000 -> "$400.000"
export function aPesos(n) {
  return '$' + aNumero(n).toLocaleString('es-CO', { maximumFractionDigits: 2 })
}

// Formatea EN VIVO mientras el usuario escribe en un <input> (punto de miles
// cada 3 dígitos, coma decimal), sin esperar a enviar el formulario. Úsala en
// el onChange de cantidades/costos/precios: guarda en el estado el resultado
// de esta función (ya formateado con puntos), y sigue pasando ese mismo
// string por aNumero() al enviar — aNumero() ya sabe leer "1.200.000,50".
export function formatearEnVivo(valor) {
  let s = String(valor ?? '')
  const negativo = s.trim().startsWith('-')
  s = s.replace(/[^\d,]/g, '') // el usuario solo teclea dígitos y la coma decimal; los puntos de miles se agregan solos
  const [entera, ...resto] = s.split(',')
  const enteraFormateada = entera.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  const decimal = resto.length ? ',' + resto.join('').slice(0, 2) : (s.includes(',') ? ',' : '')
  return (negativo ? '-' : '') + enteraFormateada + decimal
}
