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
- recuperacion contextual via Pinecone (RAG),
- vision sobre ultimas paginas para sellos RPC / notariales,
- vision sobre primeras paginas con prioridad a la pagina 2 para `fecha_registro`, `escritura_numero`, `libro_numero` y `acto`,
- adjudicacion AI entre candidatos por campo usando confianza por fuente,
- regex solo como ultimo recurso para campos aun vacios.

Para cada acta procesada se persisten en `document_index.metadata`:

- `field_sources`
- `field_confidence`
- `recovery_candidates`
- `adjudication`
- `processing_trace`

La UI de edicion del acta expone todos los campos recuperados actualmente, incluidos arreglos editables para:

- `apoderados[*]` (`nombre_completo`, `ine`, `poder_documento`, `facultades_otorgadas`)
- `participacion_accionaria[*]` (`socio`, `porcentaje`)
- `consejo_administracion[*]`
- `direccion_empresa[*]`

Si necesitas inspeccionar el detalle del procesamiento, la vista de edicion del acta permite abrir el OCR, descargar la traza JSON y revisar las diferencias entre valores guardados y valores sugeridos por AI.
