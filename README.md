# Licitaciones App

Plataforma base para gestion de licitaciones con:

- Laravel 13 + Inertia.js
- Vue 3 + Vite + Tailwind + PrimeVue
- MySQL + Redis + MinIO
- Docker Compose para despliegue en VPS

## Autenticacion

Este proyecto **no usa Sanctum**.

- Autenticacion basada en sesion (cookies HttpOnly + CSRF)
- Inertia.js para frontend SPA server-driven
- Fortify/Breeze como base del flujo de login

## Levantar en Docker

1. Construir imagenes:

```bash
docker compose build
```

2. Levantar servicios:

```bash
docker compose up -d
```

3. Ejecutar migraciones:

```bash
docker compose exec app php artisan migrate
```

4. Crear bucket en MinIO (opcional automatizar en seeder):

- Consola MinIO: http://localhost:9001
- Usuario: `minioadmin`
- Password: `minioadmin`
- Bucket sugerido: `licitaciones`

5. Acceder a la app:

- App: http://localhost:8000
- MinIO API: http://localhost:9000

## Servicios Docker

- `app`: servidor Laravel en puerto 8000
- `worker`: procesamiento de colas Redis
- `scheduler`: ejecucion de tareas programadas
- `mysql`: base de datos principal
- `redis`: cache, sesiones y colas
- `minio`: almacenamiento de objetos compatible S3

## Comandos utiles

```bash
docker compose logs -f app
docker compose logs -f worker
docker compose exec app php artisan test
docker compose exec app php artisan queue:failed
```

## Extraccion de Actas (Estado Actual)

La extraccion de actas ya no depende de una sola fuente. El pipeline actual combina:

- texto nativo del PDF y OCR cuando hace falta,
- separacion entre `extracted_text` (estable para UI/extraccion) e `index_text` (optimizado para retrieval),
- recuperacion contextual via Pinecone (RAG),
- vision sobre ultimas paginas para sellos RPC / notariales,
- vision sobre primeras paginas con prioridad a la pagina 2 para `fecha_registro`, `escritura_numero`, `libro_numero` y `acto`,
- adjudicacion AI entre candidatos por campo usando confianza por fuente,
- regex solo como ultimo recurso para campos aun vacios,
- normalizacion OCR adicional para convenciones notariales mexicanas (`--` y placeholders `~` frecuentes).

Para indexacion vectorial de actas:

- los chunks exactos de Pinecone se persisten en `document_index.metadata.index_chunk_payloads`,
- el mapeo chunk -> paginas fuente se persiste en `document_index.metadata.index_chunk_page_map`,
- cada vector incluye metadata de pagina (`page_ids`, `page_numbers_csv`, `primary_page`),
- el indexador elimina vectores obsoletos cuando cambia la cantidad de chunks de un documento.

Para cada acta procesada se persisten en `document_index.metadata`:

- `field_sources`
- `field_confidence`
- `recovery_candidates`
- `adjudication`
- `processing_trace`
- `index_chunk_payloads`
- `index_chunk_page_map`

La UI de edicion del acta expone todos los campos recuperados actualmente, incluidos arreglos editables para:

- `apoderados[*]` (`nombre_completo`, `ine`, `poder_documento`, `facultades_otorgadas`)
- `participacion_accionaria[*]` (`socio`, `porcentaje`)
- `consejo_administracion[*]`
- `direccion_empresa[*]`

Si necesitas inspeccionar el detalle del procesamiento, la vista de edicion del acta permite:

- abrir el OCR,
- abrir la vista de datos Pinecone del documento,
- descargar la traza JSON,
- revisar las diferencias entre valores guardados y valores sugeridos por AI.

La vista de Pinecone de Acta muestra:

- IDs de vectores,
- registros exactos recuperados desde Pinecone,
- texto guardado por chunk,
- metadata vectorial,
- paginas de origen por chunk,
- score heuristico de sospecha por chunk,
- correcciones manuales por chunk con intento de vision y fallback a OCR dirigido sobre las paginas exactas del chunk.

En hosts CPU-only con Ollama local, la correccion manual por vision esta afinada para enviar una sola imagen reducida por request (ancho cercano a 320px), limites explicitos de `num_ctx` / `num_predict` y un timeout con margen para arranque en frio; sin ese recorte, las paginas notariales completas pueden tardar varios minutos o caer al fallback OCR.

Validacion operativa reciente sobre la ruta real de correccion por chunk:

- motor: `qwen2.5vl:3b`
- imagen enviada: 1 pagina reducida (`~320px`, base64 aproximado de 8 KB)
- limites multimodales: `num_ctx=1024`, `num_predict=384`
- tiempo observado en contenedor real: ~31.5 segundos
- resultado: la correccion por vision devolvio texto util y ya no dependio del fallback OCR como comportamiento por defecto para ese flujo.
