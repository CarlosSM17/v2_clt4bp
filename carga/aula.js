// Prueba de carga del aula con k6: una clase entera trabajando a la vez sobre la misma tarea.
// Uso (desde la raíz del repositorio, contra el servidor ANTES del piloto):
//   k6 run -e BASE_URL=https://clt4bp.midominio.mx -e CURSO=1 -e TAREA=t1 -e PASSWORD=... carga/aula.js
import http from 'k6/http'
import { check, sleep } from 'k6'

const BASE = __ENV.BASE_URL
const CURSO = __ENV.CURSO
const TAREA = __ENV.TAREA
const CUENTAS = Number(__ENV.CUENTAS || 100)
const CODIGO = __ENV.LENGUAJE === 'python'
  ? 'n = int(input())\nprint(n * 2)\n'
  : '#include <stdio.h>\nint main(void) {\n  int n;\n  scanf("%d", &n);\n  printf("%d\\n", n * 2);\n  return 0;\n}\n'

export const options = {
  scenarios: {
    clase: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '2m', target: 50 },  // van llegando
        { duration: '5m', target: 100 }, // toda la clase (y más) trabajando
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],                       // menos de 1 % de errores
    'http_req_duration{tipo:pagina}': ['p(95)<800'],       // páginas en menos de 0.8 s
    'http_req_duration{tipo:ejecutar}': ['p(95)<5000'],    // ejecutar código en menos de 5 s
    checks: ['rate>0.99'],
  },
}

const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' }
let conectado = false // cada usuario virtual tiene su propia copia (y su propio frasco de cookies)

function xsrf() {
  const cookies = http.cookieJar().cookiesForURL(BASE)
  return cookies['XSRF-TOKEN'] ? decodeURIComponent(cookies['XSRF-TOKEN'][0]) : ''
}

function iniciarSesion() {
  http.get(`${BASE}/login`, { tags: { tipo: 'pagina' } }) // deja las cookies de sesión y XSRF
  const email = `ensayo-${String(((__VU - 1) % CUENTAS) + 1).padStart(3, '0')}@ensayo.invalid`
  const r = http.post(`${BASE}/login`, JSON.stringify({ email, password: __ENV.PASSWORD }), {
    headers: { ...JSON_HEADERS, 'X-XSRF-TOKEN': xsrf() },
    tags: { tipo: 'login' },
  })
  conectado = check(r, { 'inicia sesión': (res) => res.status === 200 || res.status === 204 })
}

export default function () {
  if (!conectado) iniciarSesion()
  if (!conectado) return sleep(30)

  const mapa = http.get(`${BASE}/aula/${CURSO}`, { tags: { tipo: 'pagina' } })
  check(mapa, { 'mapa 200': (r) => r.status === 200 })
  sleep(5 + Math.random() * 5)

  const tarea = http.get(`${BASE}/aula/${CURSO}/tareas/${TAREA}`, { tags: { tipo: 'pagina' } })
  check(tarea, { 'tarea 200': (r) => r.status === 200 })

  // Cada estudiante prueba su programa unas cuantas veces, pensando entre intento e intento
  for (let i = 0; i < 3; i++) {
    sleep(15 + Math.random() * 15)
    const r = http.post(`${BASE}/aula/${CURSO}/tareas/${TAREA}/ejecutar`,
      JSON.stringify({ codigo: CODIGO, entrada: String(i + 20) }),
      { headers: { ...JSON_HEADERS, 'X-XSRF-TOKEN': xsrf() }, tags: { tipo: 'ejecutar' } })
    check(r, { 'ejecuta y responde bien': (res) => res.status === 200 && res.json('salida').trim() === String((i + 20) * 2) })
  }
  sleep(10)
}
