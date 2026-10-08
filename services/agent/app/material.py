"""Material del curso (RAG): extraer texto, fragmentarlo y calcular embeddings con Ollama.

El agente no guarda nada: devuelve fragmentos y vectores a Laravel, que es dueño del índice (pgvector)
y decide qué fragmentos viajan en cada solicitud de generación.
"""

import io
import re
from dataclasses import dataclass

import httpx
from pypdf import PdfReader
from pypdf.errors import PdfReadError

from app.proveedor.base import ProveedorNoDisponible

MAXIMO_FRAGMENTOS = 3000  # un libro de ~600 páginas; más que eso no es material de un curso


class DocumentoIlegible(ValueError):
    pass


@dataclass
class Fragmento:
    orden: int
    pagina: int | None
    texto: str


def extraer(nombre: str, contenido: bytes) -> list[tuple[int | None, str]]:
    """Texto por página (PDF) o el documento completo como una sola «página» sin número (MD, TXT)."""
    if nombre.lower().endswith(".pdf"):
        try:
            lector = PdfReader(io.BytesIO(contenido))
            paginas = [(i, p.extract_text() or "") for i, p in enumerate(lector.pages, start=1)]
        except PdfReadError as e:
            raise DocumentoIlegible(f"No se pudo leer el PDF: {e}") from e
    else:
        paginas = [(None, contenido.decode("utf-8", errors="replace"))]

    if not any(t.strip() for _, t in paginas):
        raise DocumentoIlegible("El documento no tiene texto extraíble (¿es un escaneo? Pásalo antes por OCR).")
    return paginas


def _cola(texto: str, traslape: int) -> str:
    """Los últimos `traslape` caracteres, empezando en una palabra completa."""
    if len(texto) <= traslape:
        return texto
    corte = texto[-traslape:]
    espacio = corte.find(" ")
    return corte[espacio + 1 :] if espacio >= 0 else corte


def _ventanas(parrafo: str, tamano: int, traslape: int) -> list[str]:
    """Un párrafo más largo que `tamano` se parte en ventanas que respetan palabras y se traslapan."""
    salida, inicio = [], 0
    while inicio < len(parrafo):
        fin = min(inicio + tamano, len(parrafo))
        if fin < len(parrafo):
            espacio = parrafo.rfind(" ", inicio + tamano // 2, fin)
            fin = espacio if espacio > inicio else fin
        salida.append(parrafo[inicio:fin].strip())
        if fin >= len(parrafo):
            break
        inicio = max(fin - traslape, inicio + 1)
        espacio = parrafo.find(" ", inicio, fin)
        inicio = espacio + 1 if espacio >= 0 else inicio
    return [s for s in salida if s]


def fragmentar(paginas: list[tuple[int | None, str]], tamano: int = 800, traslape: int = 150) -> list[Fragmento]:
    """Agrupa párrafos hasta ~`tamano` caracteres. Un fragmento no cruza páginas: así se puede citar."""
    fragmentos: list[Fragmento] = []
    for pagina, texto in paginas:
        # Los PDF parten líneas a media oración: se unen dentro de cada párrafo
        parrafos = [re.sub(r"\s+", " ", p).strip() for p in re.split(r"\n\s*\n", texto)]
        piezas = [v for p in parrafos if p for v in (_ventanas(p, tamano, traslape) if len(p) > tamano else [p])]

        actual = ""
        for pieza in piezas:
            if actual and len(actual) + 1 + len(pieza) > tamano:
                fragmentos.append(Fragmento(len(fragmentos) + 1, pagina, actual))
                actual = f"{_cola(actual, traslape)} {pieza}"
            else:
                actual = f"{actual} {pieza}".strip()
        if actual:
            fragmentos.append(Fragmento(len(fragmentos) + 1, pagina, actual))

        if len(fragmentos) > MAXIMO_FRAGMENTOS:
            raise DocumentoIlegible(f"El documento es demasiado largo (más de {MAXIMO_FRAGMENTOS} fragmentos): divídelo.")
    return fragmentos


class ClienteEmbeddings:
    def __init__(
        self, url: str, modelo: str, lote: int = 32, keep_alive: str = "30m", transporte: httpx.AsyncBaseTransport | None = None
    ) -> None:
        self.cliente = httpx.AsyncClient(base_url=url, timeout=httpx.Timeout(300, connect=5), transport=transporte)
        self.modelo, self.lote, self.keep_alive = modelo, lote, keep_alive

    async def calcular(self, textos: list[str]) -> list[list[float]]:
        vectores: list[list[float]] = []
        for i in range(0, len(textos), self.lote):
            try:
                r = await self.cliente.post("/api/embed", json={"model": self.modelo, "input": textos[i : i + self.lote], "keep_alive": self.keep_alive})
            except httpx.HTTPError as e:
                raise ProveedorNoDisponible(f"Ollama no responde ({e.__class__.__name__}).") from e
            if r.status_code == 404:
                raise ProveedorNoDisponible(f"Ollama no tiene el modelo «{self.modelo}»: `ollama pull {self.modelo}`.")
            if r.status_code >= 400:
                raise ProveedorNoDisponible(f"Ollama respondió {r.status_code}: {r.text[:300]}")
            vectores += r.json()["embeddings"]
        return vectores
