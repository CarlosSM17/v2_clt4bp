import Ajv2020 from 'ajv/dist/2020'
import type { ErrorObject, ValidateFunction } from 'ajv'
import type { TipoElemento } from '../../shared/diseno'
// Copias GENERADAS por packages/contracts (npm run gen)
import comunes from '../esquemas/comunes.schema.json'
import objetivo from '../esquemas/objetivo.schema.json'
import clase from '../esquemas/clase-tareas.schema.json'
import tarea from '../esquemas/tarea.schema.json'
import soporte from '../esquemas/info-soporte.schema.json'
import procedimental from '../esquemas/info-procedimental.schema.json'
import practica from '../esquemas/practica-parcial.schema.json'
import variante from '../esquemas/variante.schema.json'
import medio from '../esquemas/medio.schema.json'

const ajv = new Ajv2020({ allErrors: true, strict: false })
ajv.addSchema(comunes, 'comunes.schema.json')

const validadores: Record<TipoElemento, ValidateFunction> = {
  objetivo: ajv.compile(objetivo),
  clase: ajv.compile(clase),
  tarea: ajv.compile(tarea),
  soporte: ajv.compile(soporte),
  procedimental: ajv.compile(procedimental),
  practica_parcial: ajv.compile(practica),
  variante: ajv.compile(variante),
  medio: ajv.compile(medio)
}

/** Errores de esquema agrupados por ruta JSON (null = válido). Mismo formato que devuelve Laravel. */
export function validar(tipo: TipoElemento, contenido: unknown): Record<string, string[]> | null {
  const v = validadores[tipo]
  if (v(contenido)) return null
  const errores: Record<string, string[]> = {}
  for (const e of v.errors ?? []) {
    const ruta = e.instancePath || '/'
    ;(errores[ruta] ??= []).push(mensaje(e))
  }
  return errores
}

function mensaje(e: ErrorObject): string {
  switch (e.keyword) {
    case 'required':
      return `Falta el campo «${(e.params as { missingProperty: string }).missingProperty}».`
    case 'enum':
      return `Valor no permitido. Opciones: ${(e.params as { allowedValues: unknown[] }).allowedValues.join(', ')}.`
    case 'minLength':
      return 'El texto es demasiado corto.'
    case 'additionalProperties':
      return `Campo desconocido: «${(e.params as { additionalProperty: string }).additionalProperty}».`
    default:
      return e.message ?? 'Valor inválido.'
  }
}
