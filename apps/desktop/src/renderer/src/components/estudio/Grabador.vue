<script setup lang="ts">
import fixWebmDuration from 'fix-webm-duration'
import { onBeforeUnmount, ref } from 'vue'
import type { Medio } from '@shared/contracts'
import { uidNuevo } from '@shared/diseno'
import { useDiseno } from '../../stores/diseno'

/** Grabador de protocolos verbales: pantalla + micrófono, con marcas de segmento mientras se graba. */
const emit = defineEmits<{ cerrar: [] }>()
const estudio = useDiseno()

const estado = ref<'listo' | 'grabando' | 'guardando'>('listo')
const titulo = ref('Protocolo verbal: ')
const etiqueta = ref('')
const segmentos = ref<Medio['segmentos']>([])
const segundos = ref(0)
const error = ref('')

let grabadora: MediaRecorder | null = null
let flujos: MediaStream[] = []
let trozos: Blob[] = []
let inicio = 0
let reloj: number | undefined

async function grabar(): Promise<void> {
  error.value = ''
  try {
    const pantalla = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: 15 }, audio: false })
    const microfono = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } })
    flujos = [pantalla, microfono]
    const mezcla = new MediaStream([...pantalla.getVideoTracks(), ...microfono.getAudioTracks()])
    grabadora = new MediaRecorder(mezcla, { mimeType: 'video/webm;codecs=vp9,opus', videoBitsPerSecond: 1_500_000 })
    trozos = []
    grabadora.ondataavailable = (e) => e.data.size && trozos.push(e.data)
    grabadora.onstop = terminar
    grabadora.start(1000)
    inicio = Date.now()
    segmentos.value = [{ inicio_s: 0, etiqueta: 'Lectura del problema' }]
    reloj = window.setInterval(() => (segundos.value = Math.round((Date.now() - inicio) / 1000)), 500)
    estado.value = 'grabando'
  } catch (e) {
    error.value = `No se pudo iniciar la grabación: ${(e as Error).message}`
  }
}

function marcar(): void {
  segmentos.value.push({ inicio_s: Math.round((Date.now() - inicio) / 1000), etiqueta: etiqueta.value || `Segmento ${segmentos.value.length + 1}` })
  etiqueta.value = ''
}

function detener(): void {
  grabadora?.stop()
  flujos.forEach((f) => f.getTracks().forEach((t) => t.stop()))
  window.clearInterval(reloj)
}

async function terminar(): Promise<void> {
  estado.value = 'guardando'
  const duracionMs = Date.now() - inicio
  // MediaRecorder no escribe la duración en el WebM; sin ella, el reproductor no puede saltar a un segmento
  const video = await fixWebmDuration(new Blob(trozos, { type: 'video/webm' }), duracionMs, { logger: false })
  const uid = uidNuevo('pv')
  await window.consola.medios.guardar(estudio.cursoId, uid, await video.arrayBuffer(), 'video/webm')

  const medio: Medio = {
    uid,
    tipo: 'protocolo_verbal',
    titulo: titulo.value,
    duracion_s: Math.round(duracionMs / 1000),
    segmentos: segmentos.value,
    transcripcion: null
  }
  await estudio.guardar('medio', medio)
  estudio.seleccionado = uid
  emit('cerrar')
}

onBeforeUnmount(() => {
  if (estado.value === 'grabando') detener()
})

const mmss = (s: number): string => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
</script>

<template>
  <div class="fixed inset-0 z-10 flex items-center justify-center bg-black/30">
    <div class="w-[520px] space-y-4 rounded-lg bg-white p-6 shadow-xl">
      <h2 class="text-lg font-semibold">Grabar protocolo verbal</h2>
      <p class="text-sm text-slate-600">
        Resuelve el problema en voz alta mientras se graba tu pantalla: explica qué piensas, qué decides y por qué.
        Marca un segmento cada vez que cambies de fase (entender, planear, programar, probar).
      </p>
      <input v-model="titulo" class="campo" :disabled="estado !== 'listo'" />
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

      <div v-if="estado === 'grabando'" class="space-y-2">
        <p class="font-mono text-2xl text-red-600">● {{ mmss(segundos) }}</p>
        <div class="flex gap-2">
          <input v-model="etiqueta" class="campo" placeholder="Nombre del segmento (opcional)" @keyup.enter="marcar" />
          <button class="btn-sec" @click="marcar">Marcar</button>
        </div>
        <ol class="max-h-32 overflow-y-auto text-sm">
          <li v-for="(s, i) in segmentos" :key="i">{{ mmss(s.inicio_s) }} — {{ s.etiqueta }}</li>
        </ol>
      </div>

      <div class="flex justify-end gap-2">
        <button v-if="estado === 'listo'" class="btn-sec" @click="emit('cerrar')">Cancelar</button>
        <button v-if="estado === 'listo'" class="btn" @click="grabar">Elegir pantalla y grabar</button>
        <button v-if="estado === 'grabando'" class="btn" @click="detener">Detener y guardar</button>
        <span v-if="estado === 'guardando'" class="text-sm text-slate-500">Guardando…</span>
      </div>
    </div>
  </div>
</template>
