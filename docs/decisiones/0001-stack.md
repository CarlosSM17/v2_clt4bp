# 0001 — Stack tecnológico

- Fecha: 2026-09-26
- Estado: aceptada

## Contexto
Plataforma híbrida CLT4BP desarrollada por una sola persona en Windows.

## Decisión
Laravel 13 + Inertia/Vue (web y API), Electron + Vue (consola), FastAPI (agente),
PostgreSQL + pgvector, Piston para ejecutar código. Sin Redis ni MinIO en la v1.

## Consecuencias
Un solo motor de datos; colas en base de datos; la consola consulta el estado
de los trabajos del agente en lugar de usar websockets.
