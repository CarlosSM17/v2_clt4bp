import { contextBridge, ipcRenderer } from 'electron'
import type { DocumentoCurso, Metodo, Respuesta, ResultadoLogin, Usuario } from '../shared/tipos'
import type { Conflicto, Contenido, ElementoLocal, GrupoCurso, ResumenSync, TipoElemento } from '../shared/diseno'

// API mínima y tipada que ve la interfaz. Nada de Node ni del token.
const consola = {
  sesion: {
    actual: (): Promise<Usuario | null> => ipcRenderer.invoke('sesion:actual'),
    login: (datos: { email: string; password: string; code?: string }): Promise<ResultadoLogin> =>
      ipcRenderer.invoke('sesion:login', datos),
    logout: (): Promise<void> => ipcRenderer.invoke('sesion:logout')
  },
  api: <T>(metodo: Metodo, ruta: string, cuerpo?: unknown): Promise<Respuesta<T>> =>
    ipcRenderer.invoke('api:pedir', metodo, ruta, cuerpo),
  diseno: {
    listar: (cursoId: number): Promise<ElementoLocal[]> => ipcRenderer.invoke('diseno:listar', cursoId),
    guardar: (cursoId: number, tipo: TipoElemento, contenido: Contenido, agentRunId: number | null = null): Promise<ElementoLocal> =>
      ipcRenderer.invoke('diseno:guardar', cursoId, tipo, contenido, agentRunId),
    eliminar: (cursoId: number, uid: string): Promise<string[]> => ipcRenderer.invoke('diseno:eliminar', cursoId, uid),
    sincronizar: (cursoId: number): Promise<ResumenSync> => ipcRenderer.invoke('diseno:sincronizar', cursoId),
    conflictos: (cursoId: number): Promise<Conflicto[]> => ipcRenderer.invoke('diseno:conflictos', cursoId),
    resolver: (cursoId: number, uid: string, eleccion: 'local' | 'servidor'): Promise<void> =>
      ipcRenderer.invoke('diseno:resolver', cursoId, uid, eleccion),
    info: (cursoId: number): Promise<{ grupos: GrupoCurso[]; ultima: string | null; pendientes: number; conflictos: number }> =>
      ipcRenderer.invoke('diseno:info', cursoId)
  },
  medios: {
    guardar: (cursoId: number, uid: string, datos: ArrayBuffer, mime: string): Promise<void> =>
      ipcRenderer.invoke('medios:guardar', cursoId, uid, datos, mime),
    abrir: (cursoId: number, uid: string): Promise<string> => ipcRenderer.invoke('medios:abrir', cursoId, uid)
  },
  // Material del curso para el agente (RAG): null si el instructor cierra el diálogo sin elegir
  material: {
    subir: (cursoId: number, titulo: string): Promise<Respuesta<DocumentoCurso> | null> =>
      ipcRenderer.invoke('material:subir', cursoId, titulo)
  },
  // Etapa 6: descarga la exportación del curso y la guarda donde elija el instructor
  exportar: (cursoId: number, formato: 'csv' | 'xlsx'): Promise<{ ruta: string | null; error: string | null }> =>
    ipcRenderer.invoke('exportar:guardar', cursoId, formato)
}

export type ApiConsola = typeof consola

contextBridge.exposeInMainWorld('consola', consola)
