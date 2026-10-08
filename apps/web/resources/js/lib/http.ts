/** POST con JSON y protección CSRF de Laravel (cookie XSRF-TOKEN), para llamadas que no son navegación Inertia. */
export async function postJson<T>(url: string, cuerpo: unknown): Promise<T> {
    const xsrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf,
        },
        credentials: 'same-origin',
        body: JSON.stringify(cuerpo),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error(
            (json as { message?: string }).message ?? `Error ${res.status}`,
        );
    }
    return json as T;
}
