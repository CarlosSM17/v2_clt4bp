// Eventos de aprendizaje desde el navegador: se acumulan y se envían en lotes (cada 10 s, al llenarse
// o al ocultar la página). Lo que califica o cuenta como envío lo registra el servidor, no esto.

type Evento = {
    verbo: string;
    objeto_tipo?: string;
    objeto_uid?: string;
    resultado?: Record<string, unknown>;
    duracion_ms?: number;
    ocurrido_at: string;
};

let cursoId: number | null = null;
let cola: Evento[] = [];
let temporizador: number | undefined;

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

/** Se llama al montar cada página del aula. */
export function iniciarEventos(curso: number): void {
    if (cursoId !== curso) {
        void vaciar(); // lo pendiente del curso anterior sale con su propio curso
        cursoId = curso;
    }
    if (temporizador === undefined) {
        temporizador = window.setInterval(() => void vaciar(), 10_000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') void vaciar();
        });
        window.addEventListener('pagehide', () => void vaciar());
    }
}

export function registrar(
    verbo: string,
    objetoTipo?: string,
    objetoUid?: string,
    extra: { resultado?: Record<string, unknown>; duracion_ms?: number } = {},
): void {
    cola.push({ verbo, objeto_tipo: objetoTipo, objeto_uid: objetoUid, ...extra, ocurrido_at: new Date().toISOString() });
    if (cola.length >= 40) void vaciar();
}

async function vaciar(): Promise<void> {
    if (!cola.length || cursoId === null) return;
    // Todo antes del primer await: si cambia el curso, este lote va al curso correcto
    const lote = cola.splice(0, 50);
    const url = `/aula/${cursoId}/eventos`;
    try {
        // keepalive: la petición termina aunque la página se esté cerrando
        const r = await fetch(url, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ eventos: lote }),
        });
        if (r.status >= 500 || r.status === 429) cola = [...lote, ...cola]; // se reintenta en el siguiente ciclo
    } catch {
        cola = [...lote, ...cola];
    }
}

/** Mide el tiempo que la página estuvo visible (no el que estuvo abierta en otra pestaña). Devuelve la función de cierre. */
export function medirTiempo(objetoTipo: string, objetoUid: string): () => void {
    let visibleMs = 0;
    let desde: number | null = document.visibilityState === 'visible' ? performance.now() : null;
    const alCambiar = (): void => {
        if (document.visibilityState === 'hidden' && desde !== null) {
            visibleMs += performance.now() - desde;
            desde = null;
        } else if (document.visibilityState === 'visible' && desde === null) {
            desde = performance.now();
        }
    };
    document.addEventListener('visibilitychange', alCambiar);

    return () => {
        document.removeEventListener('visibilitychange', alCambiar);
        if (desde !== null) visibleMs += performance.now() - desde;
        registrar('tiempo_visible', objetoTipo, objetoUid, { duracion_ms: Math.round(visibleMs) });
        void vaciar();
    };
}
