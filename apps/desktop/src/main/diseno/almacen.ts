import { DatabaseSync } from 'node:sqlite'
import type {
  Conflicto,
  Contenido,
  ElementoLocal,
  ElementoRemoto,
  GrupoCurso,
  TipoElemento
} from '../../shared/diseno'
import { ordenDe, padreDe } from '../../shared/diseno'
import { decidirRemoto } from './reglas'
import { validar } from './validador'

interface Fila {
  uid: string
  tipo: TipoElemento
  padre_uid: string | null
  orden: number
  contenido: string
  estado: ElementoLocal['estado']
  version: number
  sucio: number
  eliminado: number
  errores: string | null
  error_servidor: string | null
  agent_run_id: number | null
}

/** Base de datos local de la consola: borradores, cola de cambios, cursor y conflictos por curso. */
export class Almacen {
  private db: DatabaseSync

  constructor(ruta: string) {
    this.db = new DatabaseSync(ruta)
    this.db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;')
    this.migrar()
  }

  private migrar(): void {
    const { user_version } = this.db.prepare('PRAGMA user_version').get() as { user_version: number }
    if (user_version < 1) {
      this.db.exec(`
        CREATE TABLE elementos (
          curso_id INTEGER NOT NULL,
          uid TEXT NOT NULL,
          tipo TEXT NOT NULL,
          padre_uid TEXT,
          orden INTEGER NOT NULL DEFAULT 1,
          contenido TEXT NOT NULL,
          estado TEXT NOT NULL DEFAULT 'borrador',
          version INTEGER NOT NULL DEFAULT 0,
          sucio INTEGER NOT NULL DEFAULT 0,
          eliminado INTEGER NOT NULL DEFAULT 0,
          errores TEXT,
          error_servidor TEXT,
          actualizado_at TEXT NOT NULL DEFAULT (datetime('now')),
          PRIMARY KEY (curso_id, uid)
        );
        CREATE TABLE conflictos (
          curso_id INTEGER NOT NULL,
          uid TEXT NOT NULL,
          servidor TEXT NOT NULL,
          detectado_at TEXT NOT NULL DEFAULT (datetime('now')),
          PRIMARY KEY (curso_id, uid)
        );
        CREATE TABLE cursos (
          curso_id INTEGER PRIMARY KEY,
          cursor INTEGER NOT NULL DEFAULT 0,
          grupos TEXT NOT NULL DEFAULT '[]',
          ultima_sync TEXT
        );
        PRAGMA user_version = 1;
      `)
    }
    if (user_version < 2) {
      // Grabaciones y otros archivos que esperan subir al servidor
      this.db.exec(`
        CREATE TABLE subidas (
          curso_id INTEGER NOT NULL,
          uid TEXT NOT NULL,
          ruta TEXT NOT NULL,
          mime TEXT NOT NULL,
          subido INTEGER NOT NULL DEFAULT 0,
          PRIMARY KEY (curso_id, uid)
        );
        PRAGMA user_version = 2;
      `)
    }
    if (user_version < 3) {
      // Etapa 4: de qué propuesta del agente salió un elemento (se envía una vez al sincronizar)
      this.db.exec(`
        ALTER TABLE elementos ADD COLUMN agent_run_id INTEGER;
        PRAGMA user_version = 3;
      `)
    }
    // Las siguientes versiones del esquema local van aquí: if (user_version < 4) { ... }
  }

  listar(cursoId: number): ElementoLocal[] {
    const filas = this.db
      .prepare('SELECT * FROM elementos WHERE curso_id = ? AND eliminado = 0 ORDER BY tipo, orden, uid')
      .all(cursoId) as unknown as Fila[]
    return filas.map(aElemento)
  }

  obtener(cursoId: number, uid: string): ElementoLocal | null {
    const fila = this.fila(cursoId, uid)
    return fila && !fila.eliminado ? aElemento(fila) : null
  }

  /** Guarda un cambio hecho en esta computadora. Siempre se guarda; si no cumple el esquema, no se sube. */
  guardar(cursoId: number, tipo: TipoElemento, contenido: Contenido, agentRunId: number | null = null): ElementoLocal {
    const uid = (contenido as unknown as { uid: string }).uid
    const previo = this.fila(cursoId, uid)
    if (previo && previo.tipo !== tipo) throw new Error('Un elemento no puede cambiar de tipo.')

    const errores = validar(tipo, contenido)
    this.db
      .prepare(
        `INSERT INTO elementos (curso_id, uid, tipo, padre_uid, orden, contenido, sucio, eliminado, errores, error_servidor, agent_run_id, actualizado_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, NULL, ?, datetime('now'))
         ON CONFLICT (curso_id, uid) DO UPDATE SET
           padre_uid = excluded.padre_uid, orden = excluded.orden, contenido = excluded.contenido,
           sucio = 1, eliminado = 0, errores = excluded.errores, error_servidor = NULL,
           agent_run_id = COALESCE(excluded.agent_run_id, elementos.agent_run_id),
           actualizado_at = excluded.actualizado_at`
      )
      .run(cursoId, uid, tipo, padreDe(tipo, contenido), ordenDe(contenido), JSON.stringify(contenido), json(errores), agentRunId)
    return this.obtener(cursoId, uid)!
  }

  /** Borra un elemento y todo lo que cuelga de él (tareas de una clase, variantes, ayudas). */
  eliminar(cursoId: number, uid: string): string[] {
    const borrados: string[] = []
    const pendientes = [uid]
    while (pendientes.length) {
      const actual = pendientes.pop()!
      const fila = this.fila(cursoId, actual)
      if (!fila) continue
      borrados.push(actual)
      if (fila.version === 0) {
        // Nunca llegó al servidor: basta con olvidarlo
        this.db.prepare('DELETE FROM elementos WHERE curso_id = ? AND uid = ?').run(cursoId, actual)
      } else {
        this.db
          .prepare("UPDATE elementos SET eliminado = 1, sucio = 1, actualizado_at = datetime('now') WHERE curso_id = ? AND uid = ?")
          .run(cursoId, actual)
      }
      const hijos = this.db
        .prepare('SELECT uid FROM elementos WHERE curso_id = ? AND padre_uid = ? AND eliminado = 0')
        .all(cursoId, actual) as { uid: string }[]
      pendientes.push(...hijos.map((h) => h.uid))
    }
    return borrados
  }

  /** Cambios listos para subir: los que no cumplen el esquema esperan en esta computadora. */
  pendientes(cursoId: number): { uid: string; tipo: TipoElemento; version: number; eliminado: boolean; contenido: Contenido; agent_run_id: number | null }[] {
    const filas = this.db
      .prepare('SELECT * FROM elementos WHERE curso_id = ? AND sucio = 1 AND (errores IS NULL OR eliminado = 1) AND uid NOT IN (SELECT uid FROM conflictos WHERE curso_id = ?)')
      .all(cursoId, cursoId) as unknown as Fila[]
    return filas.map((f) => ({ uid: f.uid, tipo: f.tipo, version: f.version, eliminado: !!f.eliminado, contenido: JSON.parse(f.contenido), agent_run_id: f.agent_run_id }))
  }

  contarPendientes(cursoId: number): number {
    const r = this.db.prepare('SELECT count(*) AS n FROM elementos WHERE curso_id = ? AND sucio = 1').get(cursoId) as { n: number }
    return r.n
  }

  /** El servidor aceptó el cambio. */
  confirmar(cursoId: number, uid: string, version: number): void {
    const fila = this.fila(cursoId, uid)
    if (!fila) return
    if (fila.eliminado) {
      this.db.prepare('DELETE FROM elementos WHERE curso_id = ? AND uid = ?').run(cursoId, uid)
    } else {
      this.db
        .prepare("UPDATE elementos SET version = ?, sucio = 0, error_servidor = NULL, estado = 'borrador', agent_run_id = NULL WHERE curso_id = ? AND uid = ?")
        .run(version, cursoId, uid)
    }
  }

  rechazar(cursoId: number, uid: string, errores: Record<string, string[]>): void {
    this.db.prepare('UPDATE elementos SET error_servidor = ? WHERE curso_id = ? AND uid = ?').run(JSON.stringify(errores), cursoId, uid)
  }

  /** Aplica un elemento que llegó del servidor. Devuelve true si abrió un conflicto nuevo. */
  aplicarRemoto(cursoId: number, r: ElementoRemoto): boolean {
    const fila = this.fila(cursoId, r.uid)
    const local = fila
      ? { version: fila.version, sucio: !!fila.sucio, eliminado: !!fila.eliminado, contenido: JSON.parse(fila.contenido) }
      : null
    switch (decidirRemoto(local, r)) {
      case 'insertar':
      case 'reemplazar':
        this.db
          .prepare(
            `INSERT INTO elementos (curso_id, uid, tipo, padre_uid, orden, contenido, estado, version, sucio, eliminado, errores, error_servidor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, NULL)
             ON CONFLICT (curso_id, uid) DO UPDATE SET
               tipo = excluded.tipo, padre_uid = excluded.padre_uid, orden = excluded.orden, contenido = excluded.contenido,
               estado = excluded.estado, version = excluded.version, sucio = 0, eliminado = 0, errores = NULL, error_servidor = NULL`
          )
          .run(cursoId, r.uid, r.tipo, r.padre_uid, r.orden, JSON.stringify(r.contenido), r.estado, r.version)
        return false
      case 'borrar':
        this.db.prepare('DELETE FROM elementos WHERE curso_id = ? AND uid = ?').run(cursoId, r.uid)
        return false
      case 'igualar':
        this.db
          .prepare('UPDATE elementos SET version = ?, estado = ?, sucio = 0 WHERE curso_id = ? AND uid = ?')
          .run(r.version, r.estado, cursoId, r.uid)
        return false
      case 'conflicto': {
        const yaExistia = !!this.db.prepare('SELECT 1 FROM conflictos WHERE curso_id = ? AND uid = ?').get(cursoId, r.uid)
        this.registrarConflicto(cursoId, r) // guarda siempre la copia más reciente del servidor
        return !yaExistia
      }
      case 'ignorar':
        return false
    }
  }

  registrarConflicto(cursoId: number, servidor: ElementoRemoto): void {
    this.db
      .prepare('INSERT OR REPLACE INTO conflictos (curso_id, uid, servidor) VALUES (?, ?, ?)')
      .run(cursoId, servidor.uid, JSON.stringify(servidor))
  }

  conflictos(cursoId: number): Conflicto[] {
    const filas = this.db.prepare('SELECT uid, servidor FROM conflictos WHERE curso_id = ?').all(cursoId) as {
      uid: string
      servidor: string
    }[]
    return filas.map((c) => {
      const f = this.fila(cursoId, c.uid)
      const servidor = JSON.parse(c.servidor) as ElementoRemoto
      return { uid: c.uid, tipo: servidor.tipo, local: f && !f.eliminado ? JSON.parse(f.contenido) : null, servidor }
    })
  }

  /** El instructor elige qué versión se queda. */
  resolver(cursoId: number, uid: string, eleccion: 'local' | 'servidor'): void {
    const c = this.db.prepare('SELECT servidor FROM conflictos WHERE curso_id = ? AND uid = ?').get(cursoId, uid) as
      | { servidor: string }
      | undefined
    if (!c) return
    const servidor = JSON.parse(c.servidor) as ElementoRemoto
    this.db.prepare('DELETE FROM conflictos WHERE curso_id = ? AND uid = ?').run(cursoId, uid)
    if (eleccion === 'servidor') {
      this.db.prepare('DELETE FROM elementos WHERE curso_id = ? AND uid = ?').run(cursoId, uid)
      if (!servidor.eliminado) this.aplicarRemoto(cursoId, servidor)
    } else {
      // Se conserva lo local, ahora «encima» de la versión del servidor: la siguiente subida gana
      this.db.prepare('UPDATE elementos SET version = ?, sucio = 1 WHERE curso_id = ? AND uid = ?').run(servidor.version, cursoId, uid)
    }
  }

  cursor(cursoId: number): number {
    const r = this.db.prepare('SELECT cursor FROM cursos WHERE curso_id = ?').get(cursoId) as { cursor: number } | undefined
    return r?.cursor ?? 0
  }

  guardarCursor(cursoId: number, cursor: number, grupos: GrupoCurso[]): void {
    this.db
      .prepare(
        `INSERT INTO cursos (curso_id, cursor, grupos, ultima_sync) VALUES (?, ?, ?, datetime('now'))
         ON CONFLICT (curso_id) DO UPDATE SET cursor = excluded.cursor, grupos = excluded.grupos, ultima_sync = excluded.ultima_sync`
      )
      .run(cursoId, cursor, JSON.stringify(grupos))
  }

  infoCurso(cursoId: number): { grupos: GrupoCurso[]; ultima: string | null } {
    const r = this.db.prepare('SELECT grupos, ultima_sync FROM cursos WHERE curso_id = ?').get(cursoId) as
      | { grupos: string; ultima_sync: string | null }
      | undefined
    return { grupos: r ? JSON.parse(r.grupos) : [], ultima: r?.ultima_sync ?? null }
  }

  encolarSubida(cursoId: number, uid: string, ruta: string, mime: string): void {
    this.db.prepare('INSERT OR REPLACE INTO subidas (curso_id, uid, ruta, mime, subido) VALUES (?, ?, ?, ?, 0)').run(cursoId, uid, ruta, mime)
  }

  subidasPendientes(cursoId: number): { uid: string; ruta: string; mime: string }[] {
    return this.db.prepare('SELECT uid, ruta, mime FROM subidas WHERE curso_id = ? AND subido = 0').all(cursoId) as {
      uid: string
      ruta: string
      mime: string
    }[]
  }

  marcarSubida(cursoId: number, uid: string): void {
    this.db.prepare('UPDATE subidas SET subido = 1 WHERE curso_id = ? AND uid = ?').run(cursoId, uid)
  }

  rutaMedio(cursoId: number, uid: string): string | null {
    const r = this.db.prepare('SELECT ruta FROM subidas WHERE curso_id = ? AND uid = ?').get(cursoId, uid) as { ruta: string } | undefined
    return r?.ruta ?? null
  }

  cerrar(): void {
    this.db.close()
  }

  private fila(cursoId: number, uid: string): Fila | undefined {
    return this.db.prepare('SELECT * FROM elementos WHERE curso_id = ? AND uid = ?').get(cursoId, uid) as unknown as Fila | undefined
  }
}

function aElemento(f: Fila): ElementoLocal {
  return {
    uid: f.uid,
    tipo: f.tipo,
    padre_uid: f.padre_uid,
    orden: f.orden,
    contenido: JSON.parse(f.contenido),
    estado: f.estado,
    version: f.version,
    sucio: !!f.sucio,
    errores: f.errores ? JSON.parse(f.errores) : null,
    error_servidor: f.error_servidor ? JSON.parse(f.error_servidor) : null
  }
}

function json(v: unknown): string | null {
  return v === null || v === undefined ? null : JSON.stringify(v)
}
