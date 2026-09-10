# Auth Microservice

Standalone authentication service for Project 1. Owns users, roles, opaque
token login, and audit logs. Future microservices validate tokens via the
internal introspection endpoint — they never read this database.

## Stack

- Backend: PHP 8.3, Laravel 13, Sanctum 4 (opaque personal access tokens), MySQL 8
- Frontend: React 19, Vite 8, React Router 7, Axios 1
- Infra: Docker + Docker Compose (this folder only)

## Ports

| Service  | Local URL             |
| -------- | --------------------- |
| Frontend | http://localhost:5173 |
| Backend  | http://localhost:8001 |
| MySQL    | localhost:3311 → 3306 |

Databases: `auth_db` (main), `auth_test_db` (automated tests only).

## Structure

```
auth/
├── backend/        complete Laravel application
├── frontend/       complete React + Vite application
├── docker/         backend.Dockerfile, frontend.Dockerfile, backend.env, mysql/init.sql
├── docs/
├── docker-compose.yml
├── .env.example
├── .gitignore
└── README.md
```

## Start

```bash
docker compose up -d --build
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan app:create-owner
```

Open http://localhost:5173 and sign in. Tokens live 12 hours by default
(`AUTH_TOKEN_TTL_MINUTES`). `INTERNAL_API_KEY` **must** be rotated for any
shared/production use — never reuse the dev value.

## Without Docker (Windows + WAMP MySQL on 3306)

```bash
cd backend
composer install
php artisan migrate
php artisan app:create-owner
php artisan serve --host=127.0.0.1 --port=8001
```

```bash
cd frontend
npm install
npm run dev
```

## Tests (MySQL `auth_test_db` — never `auth_db`)

```bash
cd backend
php artisan test
```

The suite refuses to run unless the active database is `auth_test_db`.

## API

```
GET    /api/v1/health
POST   /api/v1/auth/login            (throttle:login ~5/min)
GET    /api/v1/auth/me               (auth_token cookie)
POST   /api/v1/auth/logout           (auth_token cookie)
GET    /api/v1/users                 (OWNER)
POST   /api/v1/users                 (OWNER)
GET    /api/v1/users/{user}          (OWNER)
PATCH  /api/v1/users/{user}          (OWNER)
DELETE /api/v1/users/{user}          (OWNER, soft-deactivate + revoke tokens)
POST   /api/v1/users/{user}/activate (OWNER)
GET    /api/v1/audit-logs            (OWNER)

POST   /internal/v1/auth/introspect  (X-Internal-Key + X-Auth-Token)
```

Auth cookie: `auth_token`, HttpOnly, `Path=/`, `SameSite=Lax`
(`Secure` configurable, off for local dev). The raw token is never returned
in JSON and never stored client-side.

Framework sessions are file-based on purpose (`SESSION_DRIVER=file`,
cookie `auth-session`): authentication uses the opaque token cookie, never
database sessions — so no `sessions` table exists or is needed.

If your browser still holds a stale `core-session` cookie from the abandoned
prototype: Chrome DevTools → Application → Cookies → localhost → delete
`core-session`. The backend ignores it; this is hygiene only.

## React pages

`/login` · `/dashboard` · `/users` · `/users/new` · `/users/:id` ·
`/users/:id/edit` · `/activity` · `/403` · 404 fallback.
