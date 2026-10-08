<script setup lang="ts">
import DOMPurify from 'dompurify'
import hljs from 'highlight.js/lib/core'
import c from 'highlight.js/lib/languages/c'
import cpp from 'highlight.js/lib/languages/cpp'
import python from 'highlight.js/lib/languages/python'
import 'highlight.js/styles/github.css'
import MarkdownIt from 'markdown-it'
import mermaid from 'mermaid'
import { nextTick, ref, watch } from 'vue'
import { bloqueEspecial, claseRecuadro } from '../lib/bloques'
import { montarTraza } from '../lib/traza'

hljs.registerLanguage('c', c)
hljs.registerLanguage('cpp', cpp)
hljs.registerLanguage('python', python)
mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' })

const props = defineProps<{ md: string }>()
const contenedor = ref<HTMLElement | null>(null)

// html: false → el Markdown no puede traer HTML propio; DOMPurify es la segunda barrera
const md = new MarkdownIt({ html: false, linkify: true })
md.options.highlight = (codigo, lenguaje) => {
  if (lenguaje === 'mermaid')
    return `<div class="mermaid-fuente">${md.utils.escapeHtml(codigo)}</div>`
  // Traza de código: se monta como reproductor paso a paso después de sanear el HTML
  if (lenguaje === 'traza') return `<div class="traza-fuente">${md.utils.escapeHtml(codigo)}</div>`
  if (lenguaje && hljs.getLanguage(lenguaje)) {
    return `<pre class="hljs"><code>${hljs.highlight(codigo, { language: lenguaje }).value}</code></pre>`
  }
  return `<pre class="hljs"><code>${md.utils.escapeHtml(codigo)}</code></pre>`
}
// Bloques del mapa de ruta (ADR 0007): salida en consola, mensaje del compilador, pseudocódigo y código con notas
const resaltar = (codigo: string, lenguaje: string): string =>
  hljs.getLanguage(lenguaje)
    ? hljs.highlight(codigo, { language: lenguaje }).value
    : md.utils.escapeHtml(codigo)
const bloquePorDefecto = md.renderer.rules.fence!
md.renderer.rules.fence = (tokens, i, opciones, env, self) => {
  const lenguaje = tokens[i].info.trim().split(/\s+/)[0] ?? ''
  return (
    bloqueEspecial(lenguaje, tokens[i].content, resaltar, md.utils.escapeHtml) ??
    bloquePorDefecto(tokens, i, opciones, env, self)
  )
}
// Referencias a medios del curso: ![Explicación](media:uid) se muestra como una ficha
const imagenPorDefecto = md.renderer.rules.image!
md.renderer.rules.image = (tokens, i, opciones, env, self) => {
  const src = String(tokens[i].attrGet('src') ?? '')
  if (src.startsWith('media:')) {
    return `<span class="medio">🎞 ${md.utils.escapeHtml(tokens[i].content || src)}</span>`
  }
  return imagenPorDefecto(tokens, i, opciones, env, self)
}

let contador = 0
async function pintar(): Promise<void> {
  if (!contenedor.value) return
  contenedor.value.innerHTML = DOMPurify.sanitize(md.render(props.md ?? ''))
  await nextTick()
  // Recuadros: 💡 analogía, ⚠️ ¡cuidado!, 🙋 actividad en el aula…
  for (const cita of Array.from(contenedor.value.querySelectorAll<HTMLElement>('blockquote'))) {
    const clase = claseRecuadro(cita.textContent ?? '')
    if (clase) cita.classList.add('recuadro', `recuadro-${clase}`)
  }
  for (const nodo of Array.from(contenedor.value.querySelectorAll<HTMLElement>('.traza-fuente'))) {
    montarTraza(nodo, nodo.textContent ?? '')
  }
  for (const nodo of Array.from(
    contenedor.value.querySelectorAll<HTMLElement>('.mermaid-fuente')
  )) {
    try {
      const { svg } = await mermaid.render(`mmd-${++contador}`, nodo.textContent ?? '')
      nodo.innerHTML = svg
    } catch (e) {
      nodo.innerHTML = `<p class="text-red-600 text-xs">Diagrama con errores: ${md.utils.escapeHtml((e as Error).message)}</p>`
    }
  }
}

watch(() => props.md, pintar)
watch(contenedor, pintar)
</script>

<template>
  <div ref="contenedor" class="markdown" />
</template>

<style>
.markdown {
  font-size: 0.9rem;
  line-height: 1.55;
}
.markdown h1,
.markdown h2,
.markdown h3 {
  font-weight: 600;
  margin: 0.8em 0 0.4em;
}
.markdown p,
.markdown ul,
.markdown ol {
  margin: 0.5em 0;
}
.markdown ul {
  list-style: disc;
  padding-left: 1.4em;
}
.markdown ol {
  list-style: decimal;
  padding-left: 1.4em;
}
.markdown pre.hljs {
  padding: 0.75em;
  border-radius: 0.375rem;
  overflow-x: auto;
  font-size: 0.8rem;
}
.markdown code {
  font-family: ui-monospace, monospace;
}
.markdown .medio {
  display: inline-block;
  border: 1px solid #cbd5e1;
  border-radius: 9999px;
  padding: 0 0.6em;
  font-size: 0.8rem;
}
.markdown .traza {
  margin: 0.8em 0;
  border: 1px solid #cbd5e1;
  border-radius: 0.5rem;
  overflow: hidden;
  font-size: 0.8rem;
}
.markdown .traza-cabecera {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0.6rem;
  background: #f1f5f9;
  border-bottom: 1px solid #cbd5e1;
}
.markdown .traza-titulo {
  font-weight: 600;
}
.markdown .traza-contador {
  color: #64748b;
}
.markdown .traza-botones {
  margin-left: auto;
  display: flex;
  gap: 0.3rem;
}
.markdown .traza-boton {
  border: 1px solid #cbd5e1;
  border-radius: 0.375rem;
  padding: 0.1rem 0.5rem;
  background: white;
}
.markdown .traza-boton:disabled {
  opacity: 0.4;
}
.markdown .traza-cuerpo {
  display: grid;
  grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
}
.markdown .traza-codigo {
  position: relative;
  margin: 0;
  max-height: 18rem;
  overflow: auto;
  padding: 0.4rem 0;
  font-family: ui-monospace, monospace;
}
.markdown .traza-linea {
  display: flex;
  white-space: pre;
}
.markdown .traza-numero {
  width: 2.5em;
  padding-right: 0.6em;
  text-align: right;
  color: #94a3b8;
  user-select: none;
}
.markdown .traza-actual {
  background: #e0e7ff;
  box-shadow: inset 3px 0 #4f46e5;
}
.markdown .traza-lado {
  border-left: 1px solid #cbd5e1;
  padding: 0.5rem 0.6rem;
}
.markdown .traza-nota {
  margin: 0 0 0.4rem;
  font-style: italic;
}
.markdown .traza-entrada,
.markdown .traza-rotulo {
  margin: 0.3rem 0 0.1rem;
  font-size: 0.7rem;
  text-transform: uppercase;
  color: #64748b;
}
.markdown .traza-variables td {
  padding: 0.05rem 0.5rem 0.05rem 0;
  font-family: ui-monospace, monospace;
}
.markdown .traza-cambio .traza-valor {
  font-weight: 700;
  color: #4f46e5;
}
.markdown .traza-salida {
  margin: 0;
  padding: 0.3rem 0.4rem;
  background: #0f172a;
  color: #e2e8f0;
  border-radius: 0.25rem;
  white-space: pre-wrap;
  font-family: ui-monospace, monospace;
}
.markdown .traza-leyenda {
  margin: 0;
  padding: 0.25rem 0.6rem;
  font-size: 0.72rem;
  color: #64748b;
  border-bottom: 1px solid #e2e8f0;
}
.markdown .traza-aviso {
  margin: 0;
  padding: 0.25rem 0.6rem;
  font-size: 0.75rem;
  background: #fef3c7;
  color: #92400e;
}
.markdown .traza-memoria {
  border-top: 1px solid #cbd5e1;
  padding: 0.5rem 0.6rem;
}
.markdown .traza-pila {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
}
.markdown .traza-marco {
  border: 1px solid #cbd5e1;
  border-radius: 0.375rem;
  padding: 0.35rem 0.5rem;
  background: #f8fafc;
}
.markdown .traza-marco-actual {
  border-color: #6366f1;
  background: #eef2ff;
}
.markdown .traza-marco-nombre {
  margin: 0 0 0.3rem;
  font-weight: 600;
  font-family: ui-monospace, monospace;
}
.markdown .traza-var {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0.25rem 0;
}
.markdown .traza-var-nombre {
  min-width: 2.5rem;
  font-family: ui-monospace, monospace;
  color: #475569;
}
.markdown .traza-celdas {
  display: flex;
  gap: 2px;
}
.markdown .traza-celda-envoltura {
  display: flex;
  flex-direction: column;
  align-items: center;
}
.markdown .traza-celda {
  min-width: 2.2rem;
  padding: 0.15rem 0.35rem;
  border: 1px solid #94a3b8;
  background: white;
  text-align: center;
  font-family: ui-monospace, monospace;
}
.markdown .traza-celda-cambio {
  font-weight: 700;
  background: #fef9c3;
}
.markdown .traza-indice,
.markdown .traza-apuntada {
  font-size: 0.65rem;
  color: #64748b;
  line-height: 1rem;
}
.markdown .traza-apuntada {
  font-weight: 700;
}
.markdown .traza-destino {
  border-width: 2px;
}
.markdown .traza-color-0 {
  color: #4f46e5;
  border-color: #4f46e5;
}
.markdown .traza-color-1 {
  color: #059669;
  border-color: #059669;
}
.markdown .traza-color-2 {
  color: #d97706;
  border-color: #d97706;
}
.markdown .traza-color-3 {
  color: #e11d48;
  border-color: #e11d48;
}
.markdown .traza-error {
  color: #dc2626;
  font-size: 0.75rem;
}
/* Forma del mapa de ruta CLT4BP (ADR 0007): tablas, recuadros, consola, código con notas y pseudocódigo */
.markdown table {
  border-collapse: collapse;
  margin: 0.6em 0;
  font-size: 0.85rem;
}
.markdown th,
.markdown td {
  border-bottom: 1px solid rgb(148 163 184 / 0.35);
  padding: 0.3em 0.6em;
  text-align: left;
  vertical-align: top;
}
.markdown th {
  background: rgb(148 163 184 / 0.15);
  font-weight: 600;
}
.markdown blockquote {
  border-left: 3px solid rgb(148 163 184 / 0.6);
  padding: 0.2em 0.8em;
  margin: 0.6em 0;
}
.markdown blockquote.recuadro {
  border-left-width: 4px;
  border-radius: 0.5rem;
  padding: 0.5em 0.9em;
}
.markdown .recuadro-analogia {
  border-color: #f59e0b;
  background: rgb(245 158 11 / 0.1);
}
.markdown .recuadro-pregunta {
  border-color: #0284c7;
  background: rgb(2 132 199 / 0.08);
}
.markdown .recuadro-proposito {
  border-color: #10b981;
  background: rgb(16 185 129 / 0.08);
}
.markdown .recuadro-cuidado {
  border-color: #dc2626;
  background: rgb(220 38 38 / 0.07);
}
.markdown .recuadro-actividad {
  border-color: #ea580c;
  background: rgb(234 88 12 / 0.08);
}
.markdown .recuadro-resumen,
.markdown .recuadro-ejemplo {
  border-color: #2563eb;
  background: rgb(37 99 235 / 0.06);
}
.markdown .recuadro-sintaxis,
.markdown .recuadro-guia,
.markdown .recuadro-errores,
.markdown .recuadro-isomorfico {
  border-color: #7c3aed;
  background: rgb(124 58 237 / 0.06);
}
.markdown .recuadro-colaborativo {
  border-color: #0d9488;
  background: rgb(13 148 136 / 0.07);
}
.markdown .recuadro-protocolo {
  border-color: #c2410c;
  background: rgb(194 65 12 / 0.06);
}
.markdown .consola {
  background: #0f172a;
  color: #e2e8f0;
  border-radius: 0.5rem;
  margin: -0.2em 0 0.8em;
  overflow: hidden;
  font-size: 0.8rem;
}
.markdown .consola-titulo {
  padding: 0.3em 0.8em;
  color: #94a3b8;
  font-size: 0.75rem;
  border-bottom: 1px solid #1e293b;
}
.markdown .consola-error .consola-titulo {
  color: #fca5a5;
}
.markdown .consola pre {
  margin: 0;
  padding: 0.5em 0.8em;
  white-space: pre-wrap;
  font-family: ui-monospace, monospace;
}
.markdown .consola-entrada {
  color: #fde68a;
}
.markdown .consola-rotulo {
  color: #94a3b8;
}
.markdown .codigo-anotado {
  border: 1px solid rgb(148 163 184 / 0.4);
  border-radius: 0.5rem;
  margin: 0.6em 0 0.2em;
  overflow-x: auto;
}
.markdown .codigo-anotado table {
  margin: 0;
  width: 100%;
  font-size: 0.8rem;
}
.markdown .codigo-anotado td {
  border: 0;
  padding: 0.05em 0.6em;
  white-space: pre;
}
.markdown .anotado-numero {
  color: #94a3b8;
  text-align: right;
  user-select: none;
  width: 2.5em;
}
.markdown .anotado-codigo {
  font-family: ui-monospace, monospace;
}
.markdown .anotado-nota {
  white-space: normal !important;
  border-left: 2px solid rgb(59 130 246 / 0.4) !important;
  color: #1d4ed8;
  font-size: 0.75rem;
  min-width: 14em;
}
.markdown .pseint {
  background: rgb(250 204 21 / 0.1);
  border: 1px solid rgb(234 179 8 / 0.4);
  border-radius: 0.5rem;
  margin: 0.6em 0;
}
.markdown .pseint-titulo {
  text-align: right;
  padding: 0.2em 0.8em;
  font-size: 0.7rem;
  font-weight: 600;
  color: #a16207;
}
.markdown .pseint pre {
  margin: 0;
  padding: 0.3em 0.8em 0.6em;
  font-family: ui-monospace, monospace;
  font-size: 0.8rem;
}
.markdown .pseint-clave {
  color: #1d4ed8;
}
</style>
