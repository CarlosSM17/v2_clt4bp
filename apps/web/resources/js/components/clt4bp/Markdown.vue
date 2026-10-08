<script setup lang="ts">
import DOMPurify from 'dompurify';
import hljs from 'highlight.js/lib/core';
import c from 'highlight.js/lib/languages/c';
import cpp from 'highlight.js/lib/languages/cpp';
import python from 'highlight.js/lib/languages/python';
import 'highlight.js/styles/github.css';
import MarkdownIt from 'markdown-it';
import { nextTick, onMounted, ref, watch } from 'vue';
import type { Medios } from '@/lib/aula';
import { bloqueEspecial, claseRecuadro } from '@/lib/bloques';
import { montarTraza } from '@/lib/traza';

hljs.registerLanguage('c', c);
hljs.registerLanguage('cpp', cpp);
hljs.registerLanguage('python', python);

const props = defineProps<{ md: string; medios?: Medios }>();
const emit = defineEmits<{ segmento: [uid: string, inicio: number] }>();
const contenedor = ref<HTMLElement | null>(null);

// html: false → el Markdown no trae HTML propio; DOMPurify es la segunda barrera (igual que en la consola)
const md = new MarkdownIt({ html: false, linkify: true });
const esc = md.utils.escapeHtml;
md.options.highlight = (codigo, lenguaje) => {
    if (lenguaje === 'mermaid')
        return `<div class="mermaid-fuente">${esc(codigo)}</div>`;
    // Traza de código: se monta como reproductor paso a paso después de sanear el HTML
    if (lenguaje === 'traza')
        return `<div class="traza-fuente">${esc(codigo)}</div>`;
    const html =
        lenguaje && hljs.getLanguage(lenguaje)
            ? hljs.highlight(codigo, { language: lenguaje }).value
            : esc(codigo);

    return `<pre class="hljs"><code>${html}</code></pre>`;
};

// Bloques del mapa de ruta (ADR 0007): salida en consola, mensaje del compilador, pseudocódigo y código con notas
const resaltar = (codigo: string, lenguaje: string): string =>
    hljs.getLanguage(lenguaje)
        ? hljs.highlight(codigo, { language: lenguaje }).value
        : esc(codigo);
const bloquePorDefecto = md.renderer.rules.fence!;
md.renderer.rules.fence = (tokens, i, opciones, env, self) => {
    const lenguaje = tokens[i].info.trim().split(/\s+/)[0] ?? '';

    return (
        bloqueEspecial(lenguaje, tokens[i].content, resaltar, esc) ??
        bloquePorDefecto(tokens, i, opciones, env, self)
    );
};

const mmss = (s: number): string =>
    `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

// ![título](media:uid) → imagen, audio o video con sus segmentos (efecto de información transitoria)
const imagenPorDefecto = md.renderer.rules.image!;
md.renderer.rules.image = (tokens, i, opciones, env, self) => {
    const src = String(tokens[i].attrGet('src') ?? '');
    if (!src.startsWith('media:'))
        return imagenPorDefecto(tokens, i, opciones, env, self);
    const uid = src.slice(6);
    const m = (env as { medios?: Medios }).medios?.[uid];
    if (!m)
        return `<span class="medio">🎞 ${esc(tokens[i].content || uid)} (no disponible)</span>`;
    if (m.tipo === 'imagen')
        return `<img src="${esc(m.url)}" alt="${esc(m.titulo)}">`;
    const etiqueta = m.tipo === 'audio' ? 'audio' : 'video';
    const segmentos = m.segmentos
        .map(
            (s) =>
                `<li><button type="button" data-inicio="${s.inicio_s}">${mmss(s.inicio_s)} · ${esc(s.etiqueta)}</button></li>`,
        )
        .join('');

    return (
        `<figure class="medio-bloque" data-uid="${esc(uid)}"><${etiqueta} controls preload="metadata" src="${esc(m.url)}"></${etiqueta}>` +
        `<figcaption>${esc(m.titulo)}</figcaption>` +
        (segmentos ? `<ol class="segmentos">${segmentos}</ol>` : '') +
        (m.transcripcion
            ? `<details><summary>Transcripción</summary><p>${esc(m.transcripcion)}</p></details>`
            : '') +
        `</figure>`
    );
};

let contador = 0;
async function pintar(): Promise<void> {
    if (!contenedor.value) return;
    contenedor.value.innerHTML = DOMPurify.sanitize(
        md.render(props.md ?? '', { medios: props.medios ?? {} }),
        {
            ADD_TAGS: ['video', 'audio'],
            ADD_ATTR: ['controls', 'preload', 'data-inicio', 'data-uid'],
        },
    );
    await nextTick();
    // Recuadros: 💡 analogía, ⚠️ ¡cuidado!, 🙋 actividad en el aula…
    for (const cita of Array.from(
        contenedor.value.querySelectorAll<HTMLElement>('blockquote'),
    )) {
        const clase = claseRecuadro(cita.textContent ?? '');
        if (clase) cita.classList.add('recuadro', `recuadro-${clase}`);
    }
    for (const nodo of Array.from(
        contenedor.value.querySelectorAll<HTMLElement>('.traza-fuente'),
    )) {
        montarTraza(nodo, nodo.textContent ?? '');
    }
    const fuentes = Array.from(
        contenedor.value.querySelectorAll<HTMLElement>('.mermaid-fuente'),
    );
    if (!fuentes.length) return;
    // Mermaid pesa: se carga solo en las páginas que tienen diagramas
    const { default: mermaid } = await import('mermaid');
    mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' });
    for (const nodo of fuentes) {
        try {
            nodo.innerHTML = (
                await mermaid.render(
                    `mmd-${++contador}`,
                    nodo.textContent ?? '',
                )
            ).svg;
        } catch {
            nodo.innerHTML =
                '<p class="text-xs text-red-600">No se pudo dibujar el diagrama.</p>';
        }
    }
}

/** Botones de segmento: saltan a ese momento del medio que tienen arriba. */
function alHacerClic(ev: MouseEvent): void {
    const boton = (ev.target as HTMLElement).closest<HTMLButtonElement>(
        'button[data-inicio]',
    );
    const figura = boton?.closest<HTMLElement>('figure.medio-bloque');
    const medio = figura?.querySelector<HTMLMediaElement>('video, audio');
    if (!boton || !figura || !medio) return;
    const inicio = Number(boton.dataset.inicio);
    medio.currentTime = inicio;
    void medio.play();
    emit('segmento', figura.dataset.uid ?? '', inicio);
}

watch(() => [props.md, props.medios], pintar);
onMounted(pintar);
</script>

<template>
    <div ref="contenedor" class="markdown" @click="alHacerClic" />
</template>

<style>
.markdown {
    line-height: 1.6;
}
.markdown h1,
.markdown h2,
.markdown h3 {
    font-weight: 600;
    margin: 0.9em 0 0.4em;
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
    border-radius: 0.5rem;
    overflow-x: auto;
    font-size: 0.85rem;
}
.markdown code {
    font-family: ui-monospace, monospace;
}
.markdown img,
.markdown video {
    max-width: 100%;
    border-radius: 0.5rem;
}
.markdown .medio-bloque {
    margin: 1em 0;
}
.markdown .medio-bloque figcaption {
    font-size: 0.85rem;
    opacity: 0.8;
}
.markdown .segmentos {
    list-style: none;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
.markdown .segmentos button {
    border: 1px solid currentColor;
    border-radius: 9999px;
    padding: 0.1em 0.7em;
    font-size: 0.8rem;
    opacity: 0.8;
}
.markdown .medio {
    display: inline-block;
    border: 1px solid;
    border-radius: 9999px;
    padding: 0 0.6em;
    font-size: 0.8rem;
}
/* Trazas de código: colores con transparencia para que funcionen en modo claro y oscuro */
.markdown .traza {
    margin: 1em 0;
    border: 1px solid rgb(148 163 184 / 0.5);
    border-radius: 0.5rem;
    overflow: hidden;
    font-size: 0.85rem;
}
.markdown .traza-cabecera {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.6rem;
    background: rgb(148 163 184 / 0.12);
    border-bottom: 1px solid rgb(148 163 184 / 0.5);
}
.markdown .traza-titulo {
    font-weight: 600;
}
.markdown .traza-contador {
    opacity: 0.7;
}
.markdown .traza-botones {
    margin-left: auto;
    display: flex;
    gap: 0.3rem;
}
.markdown .traza-boton {
    border: 1px solid currentColor;
    border-radius: 0.375rem;
    padding: 0.1rem 0.6rem;
    opacity: 0.85;
}
.markdown .traza-boton:disabled {
    opacity: 0.3;
}
.markdown .traza-cuerpo {
    display: grid;
    grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
}
@media (max-width: 640px) {
    .markdown .traza-cuerpo {
        grid-template-columns: 1fr;
    }
    .markdown .traza-lado {
        border-left: 0;
        border-top: 1px solid rgb(148 163 184 / 0.5);
    }
}
.markdown .traza-codigo {
    position: relative;
    margin: 0;
    max-height: 20rem;
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
    opacity: 0.45;
    user-select: none;
}
.markdown .traza-actual {
    background: rgb(99 102 241 / 0.18);
    box-shadow: inset 3px 0 rgb(99 102 241);
}
.markdown .traza-lado {
    border-left: 1px solid rgb(148 163 184 / 0.5);
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
    opacity: 0.65;
}
.markdown .traza-variables td {
    padding: 0.05rem 0.5rem 0.05rem 0;
    font-family: ui-monospace, monospace;
}
.markdown .traza-cambio .traza-valor {
    font-weight: 700;
    color: rgb(99 102 241);
}
.markdown .traza-salida {
    margin: 0;
    padding: 0.3rem 0.4rem;
    background: rgb(15 23 42);
    color: rgb(226 232 240);
    border-radius: 0.25rem;
    white-space: pre-wrap;
    font-family: ui-monospace, monospace;
}
.markdown .traza-leyenda {
    margin: 0;
    padding: 0.25rem 0.6rem;
    font-size: 0.75rem;
    opacity: 0.7;
    border-bottom: 1px solid rgb(148 163 184 / 0.3);
}
.markdown .traza-aviso {
    margin: 0;
    padding: 0.25rem 0.6rem;
    font-size: 0.8rem;
    background: rgb(245 158 11 / 0.15);
}
/* Diagrama de memoria de las trazas */
.markdown .traza-memoria {
    border-top: 1px solid rgb(148 163 184 / 0.5);
    padding: 0.5rem 0.6rem;
}
.markdown .traza-pila {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
}
.markdown .traza-marco {
    border: 1px solid rgb(148 163 184 / 0.5);
    border-radius: 0.375rem;
    padding: 0.35rem 0.5rem;
    background: rgb(148 163 184 / 0.08);
}
.markdown .traza-marco-actual {
    border-color: rgb(99 102 241);
    background: rgb(99 102 241 / 0.08);
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
    opacity: 0.75;
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
    border: 1px solid rgb(148 163 184);
    text-align: center;
    font-family: ui-monospace, monospace;
}
.markdown .traza-celda-cambio {
    font-weight: 700;
    background: rgb(250 204 21 / 0.25);
}
.markdown .traza-indice,
.markdown .traza-apuntada {
    font-size: 0.65rem;
    line-height: 1rem;
    opacity: 0.7;
}
.markdown .traza-apuntada {
    font-weight: 700;
    opacity: 1;
}
.markdown .traza-destino {
    border-width: 2px;
}
.markdown .traza-color-0 {
    color: rgb(99 102 241);
    border-color: rgb(99 102 241);
}
.markdown .traza-color-1 {
    color: rgb(16 185 129);
    border-color: rgb(16 185 129);
}
.markdown .traza-color-2 {
    color: rgb(245 158 11);
    border-color: rgb(245 158 11);
}
.markdown .traza-color-3 {
    color: rgb(244 63 94);
    border-color: rgb(244 63 94);
}
.markdown .traza-error {
    color: rgb(220 38 38);
    font-size: 0.8rem;
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
.dark .markdown .anotado-nota,
.dark .markdown .pseint-clave {
    color: #93c5fd;
}
.dark .markdown .pseint-titulo {
    color: #facc15;
}
</style>
