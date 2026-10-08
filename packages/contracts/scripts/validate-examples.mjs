// Valida cada ejemplo de examples/<esquema>/*.json contra su esquema.
import Ajv2020 from 'ajv/dist/2020.js'
import { readdir, readFile } from 'node:fs/promises'
import { resolve, join } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const ajv = new Ajv2020({ allErrors: true, strict: false })
for (const archivo of await readdir(join(root, 'schema'))) {
  ajv.addSchema(JSON.parse(await readFile(join(root, 'schema', archivo), 'utf8')), archivo)
}

let fallos = 0
for (const carpeta of await readdir(join(root, 'examples'))) {
  const validar = ajv.getSchema(`${carpeta}.schema.json`)
  if (!validar) throw new Error(`No existe el esquema ${carpeta}.schema.json`)
  for (const ej of await readdir(join(root, 'examples', carpeta))) {
    const dato = JSON.parse(await readFile(join(root, 'examples', carpeta, ej), 'utf8'))
    if (validar(dato)) console.log('✔', carpeta, ej)
    else { fallos++; console.error('✘', carpeta, ej, validar.errors) }
  }
}
process.exit(fallos ? 1 : 0)
