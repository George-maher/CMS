# Church Management System (CMS)

**PROPRIETARY SOFTWARE — All Rights Reserved**

Copyright (c) 2026 Church Management System. Unauthorized copying,
modification, distribution, or use of this software is strictly
prohibited.

---

A production-grade church management platform with QR-based attendance
tracking, role-based dashboards, and comprehensive member management.

## ⚠️ Legal Notice

This software is **proprietary and confidential**. It is protected by
copyright law and international treaties. See the [LICENSE](LICENSE) file
for complete terms.

**You may NOT:**
- Copy, modify, or distribute this software without written permission
- Use this software for commercial purposes without a license agreement
- Reverse engineer, decompile, or disassemble the software
- Fork, republish, or host this as an open-source project
- Use any portion of the source code in other projects

**You MAY:**
- Use the software as authorized by a valid license agreement
- Evaluate the software for potential purchase under a trial license

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | Laravel 12 + PHP 8.3 |
| **Frontend** | React 19 + TypeScript + TailwindCSS 4 |
| **Database** | PostgreSQL 15 |
| **Storage** | Supabase Storage (native REST API) |
| **Email** | Resend |
| **Queue** | Laravel Database Queue |
| **Auth** | Laravel Sanctum (token-based) |
| **Infrastructure** | Docker + Nginx |

## Architecture

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Vercel    │────▶│   Railway   │────▶│  Supabase   │
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

## Commercial Licensing

This software is **NOT open source**. It is proprietary commercial software.

For licensing inquiries:
- **Commercial License**: enterprise@churchmanager.app
- **Evaluation License**: legal@churchmanager.app
- **Partnerships**: partners@churchmanager.app

### License Types Available

| License Type | Use Case |
|-------------|----------|
| **Single Church** | One church, self-hosted |
| **Multi-Church** | Multiple churches under one organization |
| **Enterprise SaaS** | Hosted service for multiple customers |
| **Evaluation** | 30-day trial for evaluation purposes |

## Quick Start (Docker — Evaluation Only)

```bash
# 1. Clone the repository (authorized users only)
git clone <repository-url>
cd church-manager

# 2. Copy environment files
cp docker-compose.override.yml.example docker-compose.override.yml
cp backend/.env.example backend/.env

# 3. Configure your environment in backend/.env

# 4. Start the stack
docker compose up -d

# 5. Access the application
#    Frontend: http://localhost:3000
#    Backend:  http://localhost:8000
#    Health:   http://localhost:8000/health
```

## Security

See [SECURITY.md](SECURITY.md) for our security policy and vulnerability
reporting process.

## Intellectual Property

This project includes third-party open-source components under their
respective licenses (see [NOTICE](NOTICE) for attribution). All original
code, design, and intellectual property is proprietary.

## Support

- **Documentation**: Available to licensed customers
- **Technical Support**: enterprise@churchmanager.app
- **Security Issues**: security@churchmanager.app

---

© 2026 Church Management System. All Rights Reserved.
