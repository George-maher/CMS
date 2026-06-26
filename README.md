# Church Management System

A production-grade church management platform with QR-based attendance tracking, role-based dashboards, and comprehensive member management.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | Laravel 12 + PHP 8.3 |
| **Frontend** | React 19 + TypeScript + TailwindCSS 4 |
| **Database** | Supabase PostgreSQL 15 |
| **Storage** | Supabase Storage (native REST API) |
| **Email** | Resend |
| **Queue** | Laravel Database Queue |
| **Auth** | Laravel Sanctum (SPA) |
| **Infrastructure** | Docker + Nginx |

## Architecture

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Vercel    │────▶│   Render    │────▶│  Supabase   │
│  (Frontend) │     │  (Backend)  │     │ (PostgreSQL)│
└─────────────┘     │  + Worker   │     │  + Storage  │
                    └─────────────┘     └─────────────┘
                           │
                           ▼
                     ┌─────────────┐
                     │   Resend    │
                     │   (Email)   │
                     └─────────────┘
```

## Features

- **Authentication**: Register, Login, Logout, Email Verification, Forgot/Reset Password
- **Role System**: Platform Admin, Church Admin, Assistant Admin, Servant, Member
- **QR Invites**: Admin→Servant, Servant→Member, Attendance QR with expiry & usage limits
- **Attendance Tracking**: QR scanning, member lookup, duplicate prevention, context-based
- **Points System**: Automatic attendance points, bonus points, leaderboards
- **Events**: Create, manage, target classes, track views
- **Feedback**: Submit, reply, resolve with anonymity option
- **Notifications**: Event, feedback, points, general notifications
- **Church Management**: Applications, approval, soft/hard deletion with recovery
- **Structure**: Stages and classes with servant assignments
- **Daily Verses**: Manage and activate daily bible verses
- **Multi-language**: English and Arabic (RTL) support

## Quick Start (Docker)

```bash
# 1. Clone the repository
git clone https://github.com/your-org/church-manager.git
cd church-manager

# 2. Copy environment files
cp docker-compose.override.yml.example docker-compose.override.yml
cp backend/.env.example backend/.env

# 3. Set your SUPABASE credentials in backend/.env:
#    SUPABASE_URL, SUPABASE_ANON_KEY, SUPABASE_SERVICE_ROLE_KEY
#    (optional: RESEND_API_KEY for email)

# 4. Start the stack
docker compose up -d

# 5. Access the application
#    Frontend: http://localhost:3000
#    Backend:  http://localhost:8000
#    Health:   http://localhost:8000/health
```

## Local Development (Without Docker)

### Backend

```bash
cd backend
cp .env.example .env
# Edit .env with your Supabase database credentials
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
php artisan queue:work database &
```

### Frontend

```bash
cd frontend
npm install
npm run dev
```

## Production Deployment

### Render (Backend)

1. Create a new **Web Service** on Render
2. Connect your GitHub repository
3. Set:
   - **Root Directory**: `backend`
   - **Build Command**: `composer install --no-dev --optimize-autoloader`
   - **Start Command**: `php artisan serve --host=0.0.0.0 --port=10000`
4. Add a **Cron Job** (for queue worker):
   - **Command**: `php artisan queue:work database --sleep=3 --tries=3`
5. Set environment variables:
   - `APP_ENV=production`
   - `APP_DEBUG=false`
   - `APP_KEY` (generate with `php artisan key:generate --show`)
   - `APP_URL=https://your-app.onrender.com`
   - `FRONTEND_URL=https://your-frontend.vercel.app`
   - `DB_CONNECTION=pgsql`
   - `DATABASE_URL` (your Supabase connection string)
   - `RESEND_API_KEY` (from Resend)
   - `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `SUPABASE_SERVICE_ROLE_KEY`

### Vercel (Frontend)

1. Install Vercel CLI: `npm i -g vercel`
2. Deploy:
   ```bash
   cd frontend
   vercel --prod
   ```
3. Set environment variable:
   - `VITE_API_URL=https://your-app.onrender.com/api`
4. The `vercel.json` file handles SPA routing automatically.

### Supabase (Database & Storage)

1. Create a new Supabase project
2. Get your connection string from **Project Settings → Database**
3. Use the **Connection Pooler** string for production
4. Create storage buckets: `profiles`, `events`, `documents`, `ids`, `attachments`
5. Set up Row Level Security (RLS) for storage:
   - `profiles` bucket: public read, authenticated write
   - `events` bucket: public read, authenticated write
   - Other buckets: authenticated read/write only

### Resend (Email)

1. Create a Resend account
2. Verify your domain
3. Create an API key
4. Set `RESEND_API_KEY` in your backend environment

## Environment Variables

### Backend (`backend/.env`)

| Variable | Description | Required |
|----------|-------------|----------|
| `APP_KEY` | Laravel app key (32-char base64) | Yes |
| `APP_ENV` | `production` or `development` | Yes |
| `APP_DEBUG` | `false` in production | Yes |
| `APP_URL` | Backend URL | Yes |
| `FRONTEND_URL` | Frontend URL (for CORS) | Yes |
| `DATABASE_URL` | Supabase connection string | Yes |
| `RESEND_API_KEY` | Resend API key | For email |
| `SUPABASE_URL` | Supabase project URL | For storage |
| `SUPABASE_SERVICE_ROLE_KEY` | Supabase service role key | For storage |
| `SANCTUM_STATEFUL_DOMAINS` | Comma-separated frontend domains | Yes |
| `QUEUE_CONNECTION` | `database` (default) or `redis` | Yes |

### Frontend (`frontend/.env`)

| Variable | Description | Required |
|----------|-------------|----------|
| `VITE_API_URL` | Backend API URL (e.g. `/api` or `https://api.example.com/api`) | Yes |

## Docker Services

| Service | Container | Port |
|---------|-----------|------|
| PostgreSQL | `church_postgres` | 5433 |
| PHP-FPM | `church_app` | 9000 |
| Nginx | `church_nginx` | 8000 (HTTP), 8443 (HTTPS) |
| Queue Worker | `church_worker` | - |
| Scheduler | `church_scheduler` | - |
| Frontend (Vite) | `church_frontend` | 3000 |

## API Documentation

The API is versioned under `/api/v1/`. All responses are JSON.

### Public Endpoints
- `POST /api/v1/auth/login` — User login
- `POST /api/v1/auth/register` — Registration with invite token
- `POST /api/v1/auth/forgot-password` — Request password reset
- `POST /api/v1/auth/reset-password` — Complete password reset
- `GET /api/v1/qr/validate/{token}` — Validate QR token
- `GET /api/v1/invite/{token}` — Get invite details
- `GET /api/v1/verses/active` — Get active daily verse
- `POST /api/v1/church-applications` — New church application
- `GET /api/v1/churches/active` — List active churches
- `GET /health` — Health check

### Authenticated Endpoints
All other endpoints require a Bearer token from login.

## CI/CD

The project includes GitHub Actions workflows:

- **Backend**: Lint, static analysis, tests
- **Frontend**: Lint, type check, build
- **Docker**: Build check

See `.github/workflows/ci.yml`.

## License

MIT
