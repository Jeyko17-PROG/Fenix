/**
 * Controles de paginación para listas que vienen del backend ya paginadas
 * (Laravel paginate(): { data, current_page, last_page, total, per_page }).
 * Antes de esto, varias páginas tomaban solo data.data e ignoraban el resto
 * — un negocio con más de 20 registros nunca veía los siguientes.
 */
export default function Paginacion({ meta, onCambiarPagina }) {
  if (!meta || meta.last_page <= 1) return null

  const { current_page: actual, last_page: ultima, total, per_page: porPagina } = meta
  const desde = (actual - 1) * porPagina + 1
  const hasta = Math.min(actual * porPagina, total)

  return (
    <div className="flex items-center justify-between gap-3 mt-4 text-sm text-slate-400">
      <span>Mostrando {desde}–{hasta} de {total}</span>
      <div className="flex gap-2">
        <button type="button" disabled={actual <= 1} onClick={() => onCambiarPagina(actual - 1)}
          className="rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed px-3 py-1.5">
          ← Anterior
        </button>
        <span className="px-2 py-1.5">Página {actual} de {ultima}</span>
        <button type="button" disabled={actual >= ultima} onClick={() => onCambiarPagina(actual + 1)}
          className="rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed px-3 py-1.5">
          Siguiente →
        </button>
      </div>
    </div>
  )
}
