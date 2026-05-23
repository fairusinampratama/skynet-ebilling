# Skynet E-Billing

**Skynet E-Billing** is a modern, comprehensive, and automated billing and customer management system designed specifically for Internet Service Providers (ISPs) and Wireless ISPs (WISPs).

Built with a robust Laravel backend and a reactive, fast Inertia.js + React frontend, Skynet E-Billing streamlines operations by handling customer subscriptions, automated invoicing, payment gateway integrations, and WhatsApp communications in one centralized platform.

## 🚀 Key Features

### 📊 Dashboard & Advanced Analytics
- **Financial Overview**: Real-time accounting widgets tracking Revenue, MRR (Monthly Recurring Revenue), and Collection Rates.
- **Deep Insights**: Analytics for Revenue Trends, Outstanding Aging, Revenue by Area, Package Performance, and Customer Growth.

### 👥 Customer & Subscription Management
- **Customer Lifecycle**: Manage customer profiles, assign packages, and group by operational areas.
- **Connection Control**: Quickly isolate (suspend) or reconnect customer internet access with a click.

### 📡 Router & Network Integration
- **Device Management**: Add and monitor network routers (e.g., Mikrotik).
- **MikroTik Source of Truth**: Sync PPPoE secrets from routers, mark missing eBilling customers, and stage router-only users for review.

### 🧾 Automated Invoicing
- **Lifecycle Management**: Generate, view, void, delete, and download (PDF) customer invoices.
- **Customer Portal**: Unique public, passwordless URLs for customers to view and pay their invoices securely.

### 💳 Payment Processing
- **Manual Payment Recording**: Admins can record cash, transfer, QRIS, and other offline payments against invoices.
- **Payment Proofs & Reconnection**: Store proof uploads and reconnect isolated customers automatically after full payment.

### 📱 WhatsApp Broadcasts & Campaigns
- **Customer Communication**: Integrated with **Whatspie** for sending automated WhatsApp messages and broadcast campaigns.
- **Delivery Tracking**: Monitor broadcast status and easily retry failed messages.

### ⚙️ Extensible Configuration
- **Settings System**: Configure company details, manual payment channels, and communication credentials from the UI.

---

## 🛠️ Technology Stack

Skynet E-Billing leverages a modern, reliable tech stack:

- **Backend**: [Laravel 12.x](https://laravel.com)
- **Frontend**: [React 19](https://react.dev) & [Inertia.js](https://inertiajs.com)
- **Styling**: [Tailwind CSS v4](https://tailwindcss.com) & [Shadcn UI](https://ui.shadcn.com)
- **Database**: MySQL 8.x
- **Cache & Queues**: Redis
- **Observability**: Sentry for application error tracking, plus stdout/stderr container logs
- **Runtime Tooling**: PHP 8.4, Node.js 22.x, npm lockfile installs
- **Containerization & Deployments**: Docker, Laravel Sail, Coolify/Nixpacks, Supervisor

---

## 📖 Getting Started

To get the application up and running on your local machine using Docker (Laravel Sail), please refer to our setup guide:

👉 **[Read the Setup Instructions (RUNNING_THE_APP.md)](./RUNNING_THE_APP.md)**

---

## ✅ Production Readiness

This application is intended to run behind HTTPS with Redis-backed sessions, cache, and queues. Production deployment is handled by `deploy.sh`, which runs migrations, creates the storage link, bootstraps the first superadmin only when needed, and caches Laravel config, routes, and views.

Before sending real billing traffic to production, verify these launch gates:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_FORCE_HTTPS=true`, and `SESSION_SECURE_COOKIE=true`.
- Redis is configured for `SESSION_DRIVER`, `CACHE_STORE`, and `QUEUE_CONNECTION`.
- `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL`, and `INITIAL_ADMIN_PASSWORD` are set for the first deployment only.
- `SENTRY_LARAVEL_DSN` is configured and a staging test event has been received.
- Managed database backups are enabled, retained, and restore-tested.
- `/up` is configured as the platform health check.
- Queue workers are running for `default`, `network-enforcement`, and `router-sync`, and the scheduler is active.

The first admin account is created by:

```bash
php artisan users:bootstrap-initial-admin
```

The command skips safely once any `superadmin` exists. Do not run demo seeders against production data.

---

## 🧪 Verification

CI runs Composer validation, PHP tests, frontend build, dependency audits, and Pint formatting checks. Run the same core checks locally before deploying:

```bash
composer validate --strict --no-interaction
composer audit --no-dev --no-interaction
npm audit --omit=dev
npm run build
vendor/bin/pint --test
```

Use the MySQL-backed Docker test command in `RUNNING_THE_APP.md` for the full feature suite. The current production-readiness baseline is a passing MySQL test suite, clean production dependency audits, successful route caching, and a successful frontend build under Node 22.

---

## 🔒 Security

If you discover any security-related issues, please avoid using the public issue tracker and instead communicate directly with the development team.

<!-- Last updated: 2026-05-22 -->
