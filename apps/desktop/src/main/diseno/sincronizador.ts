import type { Metodo, Respuesta } from '../../shared/tipos'
import type { ElementoRemoto, GrupoCurso, ResumenSync } from '../../shared/diseno'
import type { Almacen } from './almacen'

type Pedir = <T>(metodo: Metodo, ruta: string, cuerpo?: unknown) => Promise<Respuesta<T>>

interface ResultadoSubida {
  uid: string
  estado: 'ok' | 'conflicto' | 'invalido'
  version?: number
  servidor?: ElementoRemoto
  errores?: Record<string, string[]>
}

const LOTE = 100

/** Sube los cambios pendientes y baja los del servidor. Primero sube: así no se pisan cambios locales. */
export async function sincronizar(almacen: Almacen, cursoId: number, pedir: Pedir): Promise<ResumenSync> {
  const resumen: ResumenSync = { sinConexion: false, subidos: 0, bajados: 0, conflictos: 0, invalidos: 0, pendientes: 0, ultima: null }

  // 1) Subir, en lotes
  const pendientes = almacen.pendientes(cursoId)
  for (let i = 0; i < pendientes.length; i += LOTE) {
    const lote = pendientes.slice(i, i + LOTE).map((p) => ({
      uid: p.uid,
      tipo: p.tipo,
      base_version: p.version,
      eliminar: p.eliminado,
      ...(p.eliminado ? {} : { contenido: p.contenido }),
      ...(p.agent_run_id ? { agent_run_id: p.agent_run_id } : {}) // procedencia: propuesta del agente
    }))
    const r = await pedir<{ resultados: ResultadoSubida[]; cursor: number }>('POST', `/courses/${cursoId}/design/sync`, { cambios: lote })
    if (!r.ok) return terminar(almacen, cursoId, { ...resumen, sinConexion: r.status === 0 }, r)

    for (const res of r.data.resultados) {
      if (res.estado === 'ok') {
        almacen.confirmar(cursoId, res.uid, res.version ?? 0)
        resumen.subidos++
      } else if (res.estado === 'conflicto' && res.servidor) {
        almacen.registrarConflicto(cursoId, res.servidor)
        resumen.conflictos++
      } else {
        almacen.rechazar(cursoId, res.uid, res.errores ?? { '/': ['Rechazado por el servidor.'] })
        resumen.invalidos++
      }
    }
  }

  // 2) Bajar desde el último cursor, página por página
  let cursor = almacen.cursor(cursoId)
  let grupos: GrupoCurso[] = almacen.infoCurso(cursoId).grupos
  for (;;) {
    const r = await pedir<{ cursor: number; cambios: ElementoRemoto[]; hay_mas: boolean; grupos: GrupoCurso[] }>(
      'GET',
      `/courses/${cursoId}/design?desde=${cursor}`
    )
    if (!r.ok) return terminar(almacen, cursoId, { ...resumen, sinConexion: r.status === 0 }, r)

    for (const remoto of r.data.cambios) {
      if (almacen.aplicarRemoto(cursoId, remoto)) resumen.conflictos++
      resumen.bajados++
    }
    cursor = r.data.cursor
    grupos = r.data.grupos ?? grupos
    almacen.guardarCursor(cursoId, cursor, grupos)
    if (!r.data.hay_mas) break
  }

  return terminar(almacen, cursoId, resumen)
}

function terminar(almacen: Almacen, cursoId: number, resumen: ResumenSync, error?: Respuesta<unknown>): ResumenSync {
  if (error && !error.ok && error.status !== 0) {
    throw new Error(error.message)
  }
  return { ...resumen, pendientes: almacen.contarPendientes(cursoId), ultima: almacen.infoCurso(cursoId).ultima }
}
