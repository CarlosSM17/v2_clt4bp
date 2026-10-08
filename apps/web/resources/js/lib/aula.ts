// Tipos compartidos por las páginas del aula (Etapa 5).

export type Medio = {
    tipo: 'imagen' | 'audio' | 'video' | 'protocolo_verbal';
    titulo: string;
    segmentos: { inicio_s: number; etiqueta: string }[];
    transcripcion: string | null;
    url: string;
};
export type Medios = Record<string, Medio>;

export type NivelApoyo = 'ejemplo_resuelto' | 'por_completar' | 'convencional' | 'solucion_libre';

export type Caso = { entrada: string; salida_esperada: string };

/** Resultado de EvaluadorCasos (los casos ocultos llegan sin entrada ni salidas). */
export type Resultado = {
    aprobados: number;
    total: number;
    fraccion: number;
    error_compilacion: string | null;
    casos: {
        caso: number;
        aprobado: boolean;
        oculto: boolean;
        tiempo_excedido: boolean;
        entrada: string | null;
        esperada: string | null;
        obtenida: string | null;
    }[];
};

export const COLOR_APOYO: Record<NivelApoyo, string> = {
    ejemplo_resuelto: 'bg-emerald-100 text-emerald-800',
    por_completar: 'bg-amber-100 text-amber-800',
    convencional: 'bg-sky-100 text-sky-800',
    solucion_libre: 'bg-violet-100 text-violet-800',
};

export function fecha(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
}
