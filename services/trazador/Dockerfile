# Trazador de CLT4BP: gcc/g++ + gdb + Python, sin dependencias de terceros. Ver servidor.py y ADR 0006.
FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc g++ libc6-dev gdb python3 coreutils \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 10010 trazador

WORKDIR /app
COPY servidor.py ejecutor.py traza_gdb.py traza_python.py ./
COPY pruebas ./pruebas

# Sin privilegios: el código que se traza no es confiable
USER trazador
EXPOSE 2010
CMD ["python3", "/app/servidor.py"]
