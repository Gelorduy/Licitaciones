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
