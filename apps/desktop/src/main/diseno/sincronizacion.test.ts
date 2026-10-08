import { describe, expect, it } from 'vitest'
import { Almacen } from './almacen'
import { decidirRemoto, iguales } from './reglas'
import { sincronizar } from './sincronizador'
import type { Metodo, Respuesta } from '../../shared/tipos'
import type { ElementoRemoto, TipoElemento } from '../../shared/diseno'
import { ordenDe, padreDe } from '../../shared/diseno'
import ejemplo from '../../../../../packages/contracts/examples/diseno-curso/clase-recorridos.json'

/** Servidor falso con las mismas reglas que Laravel (control optimista de versión). */
function servidorFalso() {
  const elementos = new Map<string, ElementoRemoto>()
  let seq = 0
  let enLinea = true
  const pedir = async <T,>(metodo: Metodo, ruta: string, cuerpo?: unknown): Promise<Respuesta<T>> => {
    if (!enLinea) return { ok: false, status: 0, message: 'No hay conexión con el servidor.' }
    if (metodo === 'POST') {
      const { cambios } = cuerpo as { cambios: { uid: string; tipo: TipoElemento; base_version: number; eliminar: boolean; contenido?: never }[] }
      const resultados = cambios.map((c) => {
        const actual = elementos.get(c.uid)
        if (actual && actual.version !== c.base_version) {
          if (!c.eliminar && !actual.eliminado && iguales(actual.contenido, c.contenido)) return { uid: c.uid, estado: 'ok', version: actual.version }
          return { uid: c.uid, estado: 'conflicto', servidor: actual }
        }
        const contenido = c.eliminar ? actual!.contenido : c.contenido!
        const nuevo: ElementoRemoto = {
          uid: c.uid, tipo: c.tipo, padre_uid: padreDe(c.tipo, contenido),
          orden: ordenDe(contenido), estado: 'borrador',
          contenido, version: (actual?.version ?? 0) + 1, eliminado: c.eliminar, seq: ++seq
        }
        elementos.set(c.uid, nuevo)
        return { uid: c.uid, estado: 'ok', version: nuevo.version }
      })
      return { ok: true, status: 200, data: { resultados, cursor: seq } as T }
    }
    const desde = Number(new URL(`http://x${ruta}`).searchParams.get('desde'))
    const cambios = [...elementos.values()].filter((e) => e.seq > desde).sort((a, b) => a.seq - b.seq)
    return { ok: true, status: 200, data: { cursor: seq, cambios, hay_mas: false, grupos: [] } as T }
  }
  return { pedir, elementos, desconectar: () => (enLinea = false), conectar: () => (enLinea = true) }
}

const clase = ejemplo.clases[0] as never
const tarea = ejemplo.tareas[0] as never

describe('reglas de sincronización', () => {
  it('distingue los casos de un elemento remoto', () => {
    const local = { version: 2, sucio: false, eliminado: false, contenido: { a: 1 } }
    expect(decidirRemoto(null, { version: 1, eliminado: false, contenido: {} })).toBe('insertar')
    expect(decidirRemoto(local, { version: 3, eliminado: false, contenido: {} })).toBe('reemplazar')
    expect(decidirRemoto(local, { version: 3, eliminado: true, contenido: {} })).toBe('borrar')
    expect(decidirRemoto({ ...local, sucio: true }, { version: 3, eliminado: false, contenido: { b: 1 } })).toBe('conflicto')
    expect(decidirRemoto({ ...local, sucio: true }, { version: 3, eliminado: false, contenido: { a: 1 } })).toBe('igualar')
    expect(decidirRemoto({ ...local, sucio: true }, { version: 2, eliminado: false, contenido: {} })).toBe('ignorar')
  })
})

describe('almacén local y sincronización', () => {
  it('trabaja sin conexión y sube todo al reconectar', async () => {
    const s = servidorFalso()
    const laptop = new Almacen(':memory:')
    s.desconectar()
    laptop.guardar(1, 'clase', clase)
    laptop.guardar(1, 'tarea', tarea)

    const sin = await sincronizar(laptop, 1, s.pedir)
    expect(sin.sinConexion).toBe(true)
    expect(sin.pendientes).toBe(2)

    s.conectar()
    const con = await sincronizar(laptop, 1, s.pedir)
    expect(con.subidos).toBe(2)
    expect(con.pendientes).toBe(0)
    expect(s.elementos.get('tc1-t1')?.version).toBe(1)
    expect(laptop.obtener(1, 'tc1-t1')?.padre_uid).toBe('tc1')
  })

  it('lo que edita otra computadora llega; si ambas editan lo mismo, hay conflicto y se resuelve', async () => {
    const s = servidorFalso()
    const laptop = new Almacen(':memory:')
    const escuela = new Almacen(':memory:')
    laptop.guardar(1, 'clase', clase)
    await sincronizar(laptop, 1, s.pedir)
    await sincronizar(escuela, 1, s.pedir)
    expect(escuela.obtener(1, 'tc1')?.version).toBe(1)

    laptop.guardar(1, 'clase', { ...(clase as object), titulo: 'Desde la laptop' } as never)
    escuela.guardar(1, 'clase', { ...(clase as object), titulo: 'Desde la escuela' } as never)
    await sincronizar(laptop, 1, s.pedir)
    const r = await sincronizar(escuela, 1, s.pedir)

    expect(r.conflictos).toBe(1)
    const [c] = escuela.conflictos(1)
    expect((c.local as { titulo: string }).titulo).toBe('Desde la escuela')
    expect((c.servidor.contenido as { titulo: string }).titulo).toBe('Desde la laptop')

    escuela.resolver(1, 'tc1', 'local')
    await sincronizar(escuela, 1, s.pedir)
    expect((s.elementos.get('tc1')?.contenido as { titulo: string }).titulo).toBe('Desde la escuela')
    expect(s.elementos.get('tc1')?.version).toBe(3)

    await sincronizar(laptop, 1, s.pedir)
    expect((laptop.obtener(1, 'tc1')?.contenido as { titulo: string }).titulo).toBe('Desde la escuela')
  })

  it('un borrador que no cumple el esquema se guarda, pero no se sube', async () => {
    const s = servidorFalso()
    const laptop = new Almacen(':memory:')
    const incompleta = { ...(tarea as object), nivel_apoyo: 'mucho' } as never
    const guardada = laptop.guardar(1, 'tarea', incompleta)
    expect(guardada.errores).not.toBeNull()

    const r = await sincronizar(laptop, 1, s.pedir)
    expect(r.subidos).toBe(0)
    expect(r.pendientes).toBe(1)
  })

  it('eliminar una clase elimina sus tareas y el servidor se entera', async () => {
    const s = servidorFalso()
    const laptop = new Almacen(':memory:')
    laptop.guardar(1, 'clase', clase)
    laptop.guardar(1, 'tarea', tarea)
    await sincronizar(laptop, 1, s.pedir)

    expect(laptop.eliminar(1, 'tc1').sort()).toEqual(['tc1', 'tc1-t1'])
    await sincronizar(laptop, 1, s.pedir)
    expect(s.elementos.get('tc1-t1')?.eliminado).toBe(true)
    expect(laptop.listar(1)).toHaveLength(0)
  })

  it('la procedencia del agente se envía una sola vez; las ediciones posteriores son del instructor', async () => {
    const s = servidorFalso()
    const enviados: unknown[] = []
    const pedir: typeof s.pedir = async (metodo, ruta, cuerpo) => {
      if (metodo === 'POST') enviados.push(...(cuerpo as { cambios: unknown[] }).cambios)
      return s.pedir(metodo, ruta, cuerpo)
    }
    const laptop = new Almacen(':memory:')
    laptop.guardar(1, 'clase', clase, 77)
    await sincronizar(laptop, 1, pedir)
    laptop.guardar(1, 'clase', { ...(clase as object), titulo: 'Editada' } as never)
    await sincronizar(laptop, 1, pedir)

    expect(enviados.map((c) => (c as { agent_run_id?: number }).agent_run_id)).toEqual([77, undefined])
  })
})
