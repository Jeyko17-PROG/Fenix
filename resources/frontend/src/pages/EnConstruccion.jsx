export default function EnConstruccion({ titulo, bloque }) {
  return (
    <div className="flex flex-col items-center justify-center text-center py-20">
      <div className="text-5xl mb-4">🚧</div>
      <h1 className="text-2xl font-bold mb-2">{titulo}</h1>
      <p className="text-slate-400">Este módulo se construye en el <span className="text-emerald-400">{bloque}</span>.</p>
    </div>
  )
}
