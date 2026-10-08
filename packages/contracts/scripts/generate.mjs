// Genera los tipos TypeScript desde los esquemas y copia esquemas y catálogo a las apps que ya existan.
// Uso (desde packages/contracts): npm run gen
import { compileFromFile } from 'json-schema-to-typescript'
import { mkdir, writeFile, readdir, copyFile } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { resolve, join, dirname } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const repo = resolve(root, '..', '..')
const schemaDir = join(root, 'schema')
const catalogoDir = join(root, 'catalogo')
const banner = '/* GENERADO por packages/contracts — no editar a mano. Ejecuta: npm run gen */'

const ts = await compileFromFile(join(schemaDir, 'index.schema.json'), {
  cwd: schemaDir,
  bannerComment: banner,
  unreachableDefinitions: true,
  additionalProperties: false,
  ignoreMinAndMaxItems: true, // listas simples en vez de tuplas cuando hay minItems
  style: { singleQuote: true, semi: false }
})

async function escribir(destino, contenido) {
  await mkdir(dirname(destino), { recursive: true })
  await writeFile(destino, contenido)
  console.log('✔', destino)
}

async function copiarJson(origen, destino) {
  await mkdir(destino, { recursive: true })
  for (const archivo of await readdir(origen)) {
    if (archivo.endsWith('.json')) await copyFile(join(origen, archivo), join(destino, archivo))
  }
  console.log('✔', destino)
}

// 1) Siempre: copia local en dist/
await escribir(join(root, 'dist', 'contracts.ts'), ts)

// 2) Apps: solo si ya fueron creadas (evita crear carpetas antes que sus generadores)
const web = join(repo, 'apps', 'web')
const desktop = join(repo, 'apps', 'desktop')
const agente = join(repo, 'services', 'agent')

if (existsSync(join(web, 'artisan'))) {
  await escribir(join(web, 'resources', 'js', 'contracts', 'index.ts'), ts)
  await copiarJson(schemaDir, join(web, 'resources', 'contracts'))
  await copiarJson(catalogoDir, join(web, 'resources', 'contracts', 'catalogo'))
} else {
  console.log('… apps/web aún no existe: se omite')
}

if (existsSync(join(desktop, 'package.json'))) {
  await escribir(join(desktop, 'src', 'shared', 'contracts.ts'), ts)
  await copiarJson(schemaDir, join(desktop, 'src', 'main', 'esquemas'))
  await copiarJson(catalogoDir, join(desktop, 'src', 'shared', 'catalogo'))
} else {
  console.log('… apps/desktop aún no existe: se omite')
}

if (existsSync(join(agente, 'pyproject.toml'))) {
  await copiarJson(catalogoDir, join(agente, 'app', 'conocimiento'))
} else {
  console.log('… services/agent aún no existe: se omite')
}
