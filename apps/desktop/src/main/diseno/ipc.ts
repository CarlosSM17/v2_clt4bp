import { ipcMain } from 'electron'
import { pedir } from '../api'
import type { Contenido, TipoElemento } from '../../shared/diseno'
import type { Almacen } from './almacen'
import { sincronizar } from './sincronizador'
import { abrirMedio, guardarMedio, subirMedios } from './medios'

const TIPOS: TipoElemento[] = ['objetivo', 'clase', 'tarea', 'soporte', 'procedimental', 'practica_parcial', 'variante', 'medio']

/** Canales diseno:* — la interfaz nunca toca SQLite directamente. */
export function registrarIpcDiseno(almacen: Almacen): void {
  const curso = (v: unknown): number => {
    if (!Number.isInteger(v) || (v as number) <= 0) throw new Error('Curso inválido.')
    return v as number
  }

  ipcMain.handle('diseno:listar', (_e, cursoId: number) => almacen.listar(curso(cursoId)))

  ipcMain.handle('diseno:guardar', (_e, cursoId: number, tipo: TipoElemento, contenido: Contenido, agentRunId: number | null = null) => {
    if (!TIPOS.includes(tipo)) throw new Error('Tipo inválido.')
    if (agentRunId !== null && !Number.isInteger(agentRunId)) throw new Error('Corrida inválida.')
    return almacen.guardar(curso(cursoId), tipo, contenido, agentRunId)
  })

  ipcMain.handle('diseno:eliminar', (_e, cursoId: number, uid: string) => almacen.eliminar(curso(cursoId), String(uid)))

  ipcMain.handle('diseno:sincronizar', async (_e, cursoId: number) => {
    await subirMedios(almacen, curso(cursoId)) // primero los archivos: los elementos «medio» los citan
    return sincronizar(almacen, cursoId, pedir)
  })

  ipcMain.handle('medios:guardar', (_e, cursoId: number, uid: string, datos: ArrayBuffer, mime: string) =>
    guardarMedio(almacen, curso(cursoId), String(uid), datos, String(mime))
  )
  ipcMain.handle('medios:abrir', (_e, cursoId: number, uid: string) => abrirMedio(almacen, curso(cursoId), String(uid)))

  ipcMain.handle('diseno:conflictos', (_e, cursoId: number) => almacen.conflictos(curso(cursoId)))

  ipcMain.handle('diseno:resolver', (_e, cursoId: number, uid: string, eleccion: 'local' | 'servidor') => {
    if (eleccion !== 'local' && eleccion !== 'servidor') throw new Error('Elección inválida.')
    almacen.resolver(curso(cursoId), String(uid), eleccion)
  })

  ipcMain.handle('diseno:info', (_e, cursoId: number) => ({
    ...almacen.infoCurso(curso(cursoId)),
    pendientes: almacen.contarPendientes(curso(cursoId)),
    conflictos: almacen.conflictos(curso(cursoId)).length
  }))
}
