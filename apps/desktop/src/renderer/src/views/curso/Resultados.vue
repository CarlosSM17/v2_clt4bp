<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import type { Curso } from '@shared/tipos'
import type { TrabajoAgente } from '@shared/agente'
import {
  ARCS, MEDIDAS, errorDe, formatoP, magnitud, num, sinError,
  type EstadoEvaluacionFinal, type Informe, type RevisionResultados
} from '@shared/evaluacion'
import { api } from '../../lib/api'
import PestanasCurso from '../../components/PestanasCurso.vue'

const props = defineProps<{ id: string }>()

const final = ref<EstadoEvaluacionFinal | null>(null)
const r = ref<RevisionResultados | null>(null)
const cursos = ref<Curso[]>([])
const control = ref<number | ''>('')
const ventana = reactive({ abre: '', cierra: '', instrumentos: ['mslq', 'imms'] as string[] })
const decision = reactive({ decision: 'cerrar' as 'cerrar' | 'iterar', regresar_a: 'fase2' as 'fase1' | 'fase2', notas: '' })
const informe = ref<{ trabajo: TrabajoAgente; datos: Informe | null } | null>(null)
const ocupado = ref(false)
const error = ref('')
const aviso = ref('')
let sondeo: number | undefined

const prePost = computed(() => sinError(r.value?.pre_post))
const porGrupo = computed(() => sinError(r.value?.por_grupo))
const correlacion = computed(() => sinError(r.value?.eficiencia_ganancia))
const cmp = computed(() => r.value?.comparacion ?? null)
const ancova = computed(() => sinError(cmp.value?.ancova))
const completos = computed(() => (final.value?.estudiantes ?? []).filter((e) => e.pasos.length && e.pasos.every((p) => p.estado === 'completo')).length)

async function ejecutar(fn: () => Promise<void>): Promise<void> {
  ocupado.value = true
  error.value = ''
  aviso.value = ''
  try {
    await fn()
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    ocupado.value = false
  }
}

const cargar = (): Promise<void> =>
  ejecutar(async () => {
    final.value = await api<EstadoEvaluacionFinal>('GET', `/courses/${props.id}/evaluacion-final`)
    r.value = await api<RevisionResultados>('GET', `/courses/${props.id}/resultados${control.value ? `?control=${control.value}` : ''}`)
    cursos.value = (await api<Curso[]>('GET', '/courses')).filter((c) => c.id !== Number(props.id))

    // El informe más reciente del agente, si lo hay
    const trabajos = await api<TrabajoAgente[]>('GET', `/courses/${props.id}/agent/jobs`)
    const ultimo = trabajos.find((t) => t.plantilla === 'informe_revision')
    if (ultimo) await abrirInforme(ultimo.id)
  })

const prepararFinal = (): Promise<void> =>
  ejecutar(async () => {
    const res = await api<{ instrumentos: string[]; pruebas: number; advertencias: string[] }>('POST', `/courses/${props.id}/evaluacion-final`, {
      abre_at: new Date(ventana.abre).toISOString(),
      cierra_at: new Date(ventana.cierra).toISOString(),
      instrumentos: ventana.instrumentos
    })
    aviso.value = `Evaluación final programada: ${res.pruebas} pruebas y ${res.instrumentos.join(', ') || 'ningún cuestionario'}. ${res.advertencias.join(' ')}`
    final.value = await api<EstadoEvaluacionFinal>('GET', `/courses/${props.id}/evaluacion-final`)
  })

async function abrirInforme(id: number): Promise<void> {
  const t = await api<TrabajoAgente>('GET', `/agent/jobs/${id}`)
  informe.value = { trabajo: t, datos: (t.resultado?.notas.informe as Informe | undefined) ?? null }
  window.clearTimeout(sondeo)
  if (t.estado === 'en_cola' || t.estado === 'procesando') sondeo = window.setTimeout(() => void abrirInforme(id), 3000)
}

const pedirInforme = (): Promise<void> =>
  ejecutar(async () => {
    const t = await api<TrabajoAgente>('POST', `/courses/${props.id}/agent/jobs`, {
      plantilla: 'informe_revision', alcance: {}, indicaciones: '', calidad: 'normal', clave_idempotencia: crypto.randomUUID()
    })
    await abrirInforme(t.id)
    aviso.value = 'El agente está redactando el informe (tarda uno o dos minutos).'
  })

function usarRecomendacion(): void {
  const d = informe.value?.datos
  if (!d) return
  decision.decision = d.recomendacion
  if (d.regresar_a !== 'ninguna') decision.regresar_a = d.regresar_a
  decision.notas = [d.resumen, ...d.cambios.map((c) => `Paso ${c.paso}${c.elemento_uid ? ` (${c.elemento_uid})` : ''}: ${c.sugerencia}`)].join('\n')
}

const decidir = (): Promise<void> =>
  ejecutar(async () => {
    await api('POST', `/courses/${props.id}/resultados/decision`, {
      ...decision,
      agent_job_id: informe.value?.datos ? informe.value.trabajo.id : null
    })
    r.value = await api<RevisionResultados>('GET', `/courses/${props.id}/resultados`)
    aviso.value = decision.decision === 'cerrar' ? 'Ciclo cerrado: el curso quedó como concluido.' : 'Iteración abierta: tus notas aparecen en el Estudio de diseño.'
  })

const exportar = (formato: 'csv' | 'xlsx'): Promise<void> =>
  ejecutar(async () => {
    const res = await window.consola.exportar(Number(props.id), formato)
    if (res.error) throw new Error(res.error)
    if (res.ruta) aviso.value = `Datos guardados en ${res.ruta}`
  })

onMounted(cargar)
onBeforeUnmount(() => window.clearTimeout(sondeo))
</script>

<template>
  <h1 class="mb-2 text-2xl font-semibold">Resultados</h1>
  <PestanasCurso :id="id" />

  <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>
  <p v-if="aviso" class="mb-4 text-sm text-green-700">{{ aviso }}</p>

  <!-- 1. Evaluación final -->
  <section class="tarjeta mb-6">
    <h2 class="mb-2 font-medium">Evaluación final</h2>
    <p v-if="final?.ventana" class="mb-3 text-sm">
      {{ final.abierta ? 'Abierta' : 'Programada' }} del {{ new Date(final.ventana.abre_at).toLocaleString('es-MX') }}
      al {{ final.ventana.cierra_at ? new Date(final.ventana.cierra_at).toLocaleString('es-MX') : '--' }} ·
      {{ completos }} de {{ final.estudiantes.length }} estudiantes la completaron.
    </p>
    <form class="grid grid-cols-4 items-end gap-3 text-sm" @submit.prevent="prepararFinal">
      <label><span class="etiqueta">Abre</span><input v-model="ventana.abre" type="datetime-local" required class="campo" /></label>
      <label><span class="etiqueta">Cierra</span><input v-model="ventana.cierra" type="datetime-local" required class="campo" /></label>
      <fieldset>
        <legend class="etiqueta">Cuestionarios</legend>
        <label v-for="c in ['mslq', 'imms', 'cis']" :key="c" class="mr-3"><input v-model="ventana.instrumentos" type="checkbox" :value="c" /> {{ c.toUpperCase() }}</label>
      </fieldset>
      <button class="btn" :disabled="ocupado">{{ final?.ventana ? 'Reprogramar' : 'Abrir evaluación final' }}</button>
    </form>
    <p class="mt-2 text-xs text-slate-500">
      Antes, crea en «Pruebas» el post-test teórico y el práctico con momento «post» y la forma paralela (B). La escala CS se pide sola al terminar cada clase.
    </p>
  </section>

  <template v-if="r">
    <!-- 2. Revisión de resultados (paso 10) -->
    <section class="tarjeta mb-6">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="font-medium">Revisión de resultados (paso 10) · {{ r.con_pre_y_post }} de {{ r.n }} estudiantes con pre y post</h2>
        <div class="flex items-center gap-2 text-sm">
          <select v-model="control" class="campo w-64 py-0.5">
            <option value="">Sin grupo de control</option>
            <option v-for="c in cursos" :key="c.id" :value="c.id">Control: {{ c.titulo }}</option>
          </select>
          <button class="btn-sec" :disabled="ocupado" @click="cargar">Calcular</button>
        </div>
      </div>

      <h3 class="mb-1 text-sm font-medium">Logro por objetivo (post-test)</h3>
      <p v-if="!r.logro_objetivos.length" class="mb-3 text-sm text-slate-500">Sin resultados del post-test o sin ítems ligados a objetivos.</p>
      <table v-else class="mb-4 w-full text-sm">
        <thead class="text-left text-slate-500">
          <tr><th class="py-1">Objetivo</th><th>Ítems</th><th>Media</th><th>Alcanzan el criterio</th></tr>
        </thead>
        <tbody>
          <tr v-for="o in r.logro_objetivos" :key="o.codigo" class="border-t border-slate-100">
            <td class="py-1"><span class="font-mono">{{ o.codigo }}</span> {{ o.descripcion }}</td>
            <td>{{ o.items }}</td>
            <td>{{ num(o.media, 1) }} %</td>
            <td :class="o.logran < 70 ? 'font-medium text-rose-700' : 'text-emerald-700'">{{ num(o.logran, 0) }} % (≥ {{ o.criterio }})</td>
          </tr>
        </tbody>
      </table>

      <h3 class="mb-1 text-sm font-medium">Pre contra post (muestras relacionadas)</h3>
      <p v-if="errorDe(r.pre_post)" class="mb-3 text-sm text-amber-700">{{ errorDe(r.pre_post) }}</p>
      <table v-if="prePost" class="mb-2 w-full text-sm">
        <thead class="text-left text-slate-500">
          <tr><th class="py-1">Medida</th><th>n</th><th>Pre M (DE)</th><th>Post M (DE)</th><th>t (gl)</th><th>p</th><th>Wilcoxon V · p</th><th>d<sub>z</sub></th><th>g de Hake</th></tr>
        </thead>
        <tbody>
          <tr v-for="(s, k) in prePost" :key="k" class="border-t border-slate-100">
            <td class="py-1">{{ MEDIDAS[k] ?? k }}</td>
            <td>{{ s.n }}</td>
            <td>{{ num(s.pre.media, 1) }} ({{ num(s.pre.de, 1) }})</td>
            <td>{{ num(s.post.media, 1) }} ({{ num(s.post.de, 1) }})</td>
            <td :class="s.prueba_sugerida === 't' ? 'font-medium' : ''">{{ num(s.t_pareada?.estadistico) }} ({{ s.t_pareada?.gl ?? '--' }})</td>
            <td :class="s.prueba_sugerida === 't' ? 'font-medium' : ''">{{ formatoP(s.t_pareada?.p) }}</td>
            <td :class="s.prueba_sugerida === 'wilcoxon' ? 'font-medium' : ''">{{ num(s.wilcoxon?.estadistico, 1) }} · {{ formatoP(s.wilcoxon?.p) }}</td>
            <td>{{ num(s.d_z) }} <span class="text-xs text-slate-500">{{ magnitud(s.d_z) }}</span></td>
            <td>{{ num(s.g_hake) }} <span class="text-xs text-slate-500">{{ s.nivel_g ?? '' }}</span></td>
          </tr>
        </tbody>
      </table>
      <p class="mb-4 text-xs text-slate-500">
        En negritas, la prueba que conviene reportar: t si las diferencias son normales (Shapiro–Wilk p ≥ .05), Wilcoxon si no.
        d<sub>z</sub> = media de las diferencias / su desviación; g = (post - pre) / (100 - pre).
      </p>

      <template v-if="porGrupo">
        <h3 class="mb-1 text-sm font-medium">Por grupo diferenciado (conocimiento global)</h3>
        <table class="mb-4 w-full text-sm">
          <tbody>
            <tr v-for="(s, g) in porGrupo" :key="g" class="border-t border-slate-100">
              <td class="py-1">{{ g }}</td><td>n = {{ s.n }}</td>
              <td>{{ num(s.pre.media, 1) }} → {{ num(s.post.media, 1) }}</td>
              <td>p = {{ formatoP(s.prueba_sugerida === 'wilcoxon' ? s.wilcoxon?.p : s.t_pareada?.p) }}</td>
              <td>d<sub>z</sub> = {{ num(s.d_z) }}</td><td>g = {{ num(s.g_hake) }}</td>
            </tr>
          </tbody>
        </table>
      </template>

      <template v-if="cmp">
        <h3 class="mb-1 text-sm font-medium">Experimental contra control: {{ cmp.curso_control.titulo }}</h3>
        <p v-if="cmp.error" class="mb-3 text-sm text-amber-700">{{ cmp.error }}</p>
        <div v-else class="mb-4 grid grid-cols-3 gap-4 text-sm">
          <div v-for="(x, nombre) in { 'Post-test': sinError(cmp.post), Ganancia: sinError(cmp.ganancia) }" :key="nombre">
            <p class="font-medium">{{ nombre }} (Welch)</p>
            <template v-if="x">
              <p>{{ num(x.a.media, 1) }} contra {{ num(x.b.media, 1) }} (n = {{ x.a.n }} y {{ x.b.n }})</p>
              <p>t({{ num(x.welch.gl, 1) }}) = {{ num(x.welch.estadistico) }}, p {{ formatoP(x.welch.p) }}</p>
              <p>d = {{ num(x.d_cohen) }} ({{ magnitud(x.d_cohen) }}), g = {{ num(x.g_hedges) }}</p>
            </template>
          </div>
          <div v-if="ancova">
            <p class="font-medium">ANCOVA (pre como covariable)</p>
            <p>F(1, {{ ancova!.gl_error }}) = {{ num(ancova!.grupo.estadistico) }}, p {{ formatoP(ancova!.grupo.p) }}</p>
            <p>η² parcial = {{ num(ancova!.eta2_parcial, 3) }}</p>
            <p>Medias ajustadas: <span v-for="(m, g) in ancova!.medias_ajustadas" :key="g" class="mr-2">{{ g }} {{ num(m, 1) }}</span></p>
            <p v-if="(ancova!.pendientes_homogeneas.p ?? 1) < 0.05" class="text-amber-700">
              Las pendientes no son homogéneas (p {{ formatoP(ancova!.pendientes_homogeneas.p) }}): la ANCOVA no es adecuada.
            </p>
          </div>
        </div>
      </template>

      <p v-if="correlacion" class="mb-4 text-sm">
        Eficiencia instruccional durante el curso contra ganancia: r = {{ num(correlacion.pearson.estadistico) }}
        (p {{ formatoP(correlacion.pearson.p) }}), ρ = {{ num(correlacion.spearman.estadistico) }}, n = {{ correlacion.n }}.
      </p>

      <div class="grid grid-cols-2 gap-6 text-sm">
        <div>
          <h3 class="mb-1 font-medium">IMMS por dimensión ARCS (1–5)</h3>
          <p v-if="!r.imms" class="text-slate-500">Sin respuestas todavía.</p>
          <p v-for="(v, k) in r.imms?.subescalas ?? {}" :key="k">{{ ARCS[k] ?? k }}: {{ num(v) }}</p>
        </div>
        <div>
          <h3 class="mb-1 font-medium">Carga por clase (CS, 0–10)</h3>
          <p v-for="c in r.carga_por_clase" :key="c.clase_uid" :class="c.extrinseca_alta ? 'text-rose-700' : ''">
            Clase {{ c.orden }}: intrínseca {{ num(c.intrinseca, 1) }}, extrínseca {{ num(c.extrinseca, 1) }}, germana {{ num(c.germana, 1) }}
          </p>
        </div>
      </div>
    </section>

    <!-- 3. Informe del agente -->
    <section class="tarjeta mb-6">
      <div class="mb-2 flex items-center justify-between">
        <h2 class="font-medium">Informe de revisión del agente</h2>
        <button class="btn-sec" :disabled="ocupado || informe?.trabajo.estado === 'procesando'" @click="pedirInforme">Pedir informe nuevo</button>
      </div>
      <p class="mb-2 text-xs text-slate-500">El agente recibe solo cifras agregadas del curso (sin nombres ni seudónimos).</p>
      <p v-if="informe && !informe.datos" class="text-sm text-slate-500">
        {{ informe.trabajo.estado === 'error' ? `Error: ${informe.trabajo.error}` : 'Redactando...' }}
      </p>
      <div v-if="informe?.datos" class="space-y-2 text-sm">
        <p>{{ informe.datos.resumen }}</p>
        <ul class="list-disc pl-5">
          <li v-for="o in informe.datos.objetivos" :key="o.codigo">{{ o.codigo }}: {{ o.logrado ? 'logrado' : 'no logrado' }} -- {{ o.evidencia }}</li>
        </ul>
        <ul class="list-disc pl-5"><li v-for="(h, i) in informe.datos.hallazgos" :key="i">{{ h }}</li></ul>
        <p class="font-medium">
          Recomienda {{ informe.datos.recomendacion === 'cerrar' ? 'cerrar el ciclo' : `iterar (${informe.datos.regresar_a})` }}
        </p>
        <ul class="list-disc pl-5"><li v-for="(c, i) in informe.datos.cambios" :key="i">Paso {{ c.paso }}: {{ c.sugerencia }}</li></ul>
        <p v-if="informe.datos.limitaciones.length" class="text-xs text-slate-500">Limitaciones: {{ informe.datos.limitaciones.join(' ') }}</p>
        <button class="btn-sec" @click="usarRecomendacion">Usar como base de la decisión</button>
      </div>
    </section>

    <!-- 4. Decisión -->
    <section class="tarjeta mb-6">
      <h2 class="mb-2 font-medium">Decisión</h2>
      <form class="space-y-2 text-sm" @submit.prevent="decidir">
        <label class="mr-4"><input v-model="decision.decision" type="radio" value="cerrar" /> Cerrar el ciclo: los objetivos se cumplieron</label>
        <label><input v-model="decision.decision" type="radio" value="iterar" /> Iterar</label>
        <select v-if="decision.decision === 'iterar'" v-model="decision.regresar_a" class="campo">
          <option value="fase1">Regresar a la Fase 1: objetivos y evaluación</option>
          <option value="fase2">Regresar a la Fase 2: diseño del material</option>
        </select>
        <textarea v-model="decision.notas" required minlength="10" class="campo h-28" placeholder="Qué se decidió y por qué; qué cambiar en la siguiente iteración" />
        <button class="btn" :disabled="ocupado">Registrar decisión</button>
      </form>
      <ul v-if="r.revisiones.length" class="mt-4 space-y-1 text-sm">
        <li v-for="v in r.revisiones" :key="v.id">
          #{{ v.numero }} · {{ new Date(v.created_at).toLocaleDateString('es-MX') }} · {{ v.decision }}{{ v.regresar_a ? ` a ${v.regresar_a}` : '' }}
          · {{ v.autor?.name }}: <span class="text-slate-600">{{ v.notas }}</span>
        </li>
      </ul>
    </section>
  </template>

  <!-- 5. Exportación -->
  <section class="tarjeta">
    <h2 class="mb-2 font-medium">Exportar datos para SPSS, R o JASP</h2>
    <p class="mb-3 text-sm text-slate-600">Solo estudiantes con consentimiento de investigación vigente, con seudónimo y diccionario de datos.</p>
    <div class="flex gap-2">
      <button class="btn-sec" :disabled="ocupado" @click="exportar('csv')">CSV (ZIP)</button>
      <button class="btn-sec" :disabled="ocupado" @click="exportar('xlsx')">Excel (XLSX)</button>
    </div>
  </section>
</template>
