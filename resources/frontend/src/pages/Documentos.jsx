import { useEffect, useState } from 'react'
import { api } from '../api/client'

const COLORES = { PENDIENTE: 'bg-amber-600', FIRMADO: 'bg-emerald-600', RECHAZADO: 'bg-red-600' }

export default function Documentos() {
  const [docs, setDocs] = useState([])

  async function cargar() {
    const data = await api('/documentos')
    setDocs(data.data ?? data)
  }
  useEffect(() => { cargar() }, [])

  async function iniciarFirma(docId) {
    await api(`/documentos/${docId}/firma`, { method: 'POST' })
    cargar()
  }
  async function cambiarEstado(firmaId, estado) {
    await api(`/firmas/${firmaId}`, { method: 'PATCH', body: { estado } })
    cargar()
  }

  return (
    <div>
      <h1 className="text-2xl font-bold mb-2">Documentos</h1>
      <p className="text-slate-400 text-sm mb-6">Repositorio digital y firma electrónica (estructura lista para integración con la DIAN).</p>

      <div className="overflow-x-auto rounded-xl border border-slate-800">
        <table className="w-full text-sm">
          <thead className="bg-slate-800 text-slate-300">
            <tr><th className="text-left p-3">Tipo</th><th className="text-left p-3">Archivo</th><th className="text-left p-3">Firma</th><th className="p-3"></th></tr>
          </thead>
          <tbody>
            {docs.map((d) => (
              <tr key={d.id} className="border-t border-slate-800">
                <td className="p-3">{d.tipo}</td>
                <td className="p-3">
                  {d.archivo_url
                    ? <a href={d.archivo_url} target="_blank" rel="noreferrer" className="text-sky-400 hover:underline">Ver PDF</a>
                    : <span className="text-slate-500">—</span>}
                </td>
                <td className="p-3">
                  {d.firma
                    ? <span className={`text-xs rounded-full px-2 py-0.5 ${COLORES[d.firma.estado] ?? 'bg-slate-600'}`}>{d.firma.estado}</span>
                    : <span className="text-slate-500 text-xs">Sin iniciar</span>}
                </td>
                <td className="p-3 text-right whitespace-nowrap">
                  {!d.firma && <button onClick={() => iniciarFirma(d.id)} className="text-emerald-400 hover:underline">Iniciar firma</button>}
                  {d.firma?.estado === 'PENDIENTE' && (
                    <>
                      <button onClick={() => cambiarEstado(d.firma.id, 'FIRMADO')} className="text-emerald-400 hover:underline mr-3">Firmar</button>
                      <button onClick={() => cambiarEstado(d.firma.id, 'RECHAZADO')} className="text-red-400 hover:underline">Rechazar</button>
                    </>
                  )}
                </td>
              </tr>
            ))}
            {docs.length === 0 && <tr><td colSpan="4" className="p-6 text-center text-slate-500">Sin documentos. Genera el PDF de una orden de compra.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
