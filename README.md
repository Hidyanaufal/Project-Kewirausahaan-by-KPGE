# KPG ERP

**Website:** [kpgin.com](https://kpgin.com)

ERP system untuk koperasi Pertamina Geothermal. Built on Laravel and Filament with full double-entry accrual accounting.

## Features

- Full double-entry accrual accounting
- Invoices, Estimates, Recurring Invoices
- Bills and Vendor Management
- Connected Accounts (Plaid integration)
- Balance Sheet, Trial Balance, Cash Flow reports
- Account Balances and Transactions
- Live Currency exchange rates
- Automatic translation (multi-language support)
- PDF generation for reports

## Custom Features

### PPH 23 Implementation

- PPh 23 withholding tax (2% on subtotal) on Invoices and Bills
- WAPU (Wajib Pungut PPN): for transactions > Rp 10,000,000, uses PPN WAPU
- `actual_total` logic:
  - WAPU: `subtotal - PPh23`
  - Non-WAPU: `subtotal + tax - PPh23`
- Automatic journal entries for PPh23 and PPN WAPU on payment
- Requires Account Defaults: PPh 23, PPN WAPU (via `AccountDefaultResource`)

Reference: `app/Models/Accounting/Invoice.php`, `app/Models/Accounting/Bill.php`

### Addons

Custom modules in `app/Filament/Company/Resources/Addons/`:

| Addon | Description |
|-------|-------------|
| Projects | Project management with number, name; linked to estimates, invoices, bills |
| Members | Member management for cooperative |
| Assets | Asset registry with acquisition cost, depreciation method, useful life |
| Asset Groups | Asset grouping and default depreciation settings |
| Depreciation Records | Depreciation by asset and fiscal year |
| Account Defaults | COA defaults (PPh 23, PPN WAPU, Receivable, etc.) |
| Project Types | Project type classification |
| Roles / Users | Role and user management |

## Sponsorship

While **ERPSAAS** (the base platform) is open-source, maintaining it requires significant time and resources. Your sponsorship directly supports ongoing development, bug fixes, and improved documentation.

**Become a sponsor:** [GitHub Sponsors](https://github.com/sponsors/andrewdwallo)

## Getting Started

### Requirements

- PHP 8.2+
- Composer
- Node.js & npm
- MySQL (or compatible database)

Please check the [official Laravel installation guide](https://laravel.com/docs) for server requirements before you start.

### Installation (Local)

Install dependencies:

```bash
composer install
npm install
```

Copy the example env file and configure:

```bash
cp .env.example .env
php artisan key:generate
```

Set the database connection in `.env`, then run migrations:

```bash
php artisan migrate
```

Build assets and start the development server:

```bash
php artisan filament:assets
npm run build
npm run dev
```

## Deployment

### Testing Server

Uses `docker-compose.yaml`.

- **Port:** 8011
- **Containers:** `mykpg`, `mykpg_queue`, `mykpg_nginx`, `mykpg_db`

```bash
make deploy-testing
# or
docker compose -f docker-compose.yaml up -d --build
```

Application: `http://localhost:8011`

### Production Server

Uses `docker-compose.prod.yaml`.

- **Port:** 8031
- **Containers:** `mykpg_prod`, `mykpg_prod_queue`, `mykpg_prod_nginx`, `mykpg_prod_db`

```bash
make deploy-prod
# or
docker compose -f docker-compose.prod.yaml up -d --build
```

### Configuration Files

- **Nginx:** `nginx/conf.d/` – `app.conf` defines:
  - `root /var/www/public`
  - `client_max_body_size 20M`
  - PHP-FPM upstream `app:9000`

- **PHP:** `php/local.ini` – PHP limits:
  - `upload_max_filesize=20M`, `post_max_size=20M`
  - `max_input_time=300`, `memory_limit=256M`

Both compose files mount:
- `./php/local.ini` → `/usr/local/etc/php/conf.d/local.ini`
- `./nginx/conf.d/` → `/etc/nginx/conf.d/`

### Makefile Commands

| Command | Description |
|---------|-------------|
| `make up` | Start containers (testing env by default) |
| `make down` | Stop containers |
| `make restart` | Restart containers with rebuild |
| `make logs` | Follow container logs |
| `make ps` | List running containers |
| `make deploy-testing` | Start testing environment |
| `make deploy-prod` | Start production environment |

### Database Seeding

Populate the database with sample data:

```bash
php artisan db:seed
```

Default login (after seeding):

- **Email:** admin@erpsaas.com
- **Password:** password

To reset the database and seed fresh:

```bash
php artisan migrate:fresh
php artisan db:seed
```

## Generating PDFs for Reports

The application uses Laravel Snappy with Wkhtmltopdf. Wkhtmltopdf is included in the Docker image. For local development without Docker:

1. Install from [wkhtmltopdf.org](https://wkhtmltopdf.org/downloads.html)
2. On macOS with Homebrew: `brew install wkhtmltopdf`
3. Adjust binary paths in `config/snappy.php` if needed

See [Laravel Snappy documentation](https://github.com/barryvdh/laravel-snappy) for details.

## Live Currency

Real-time currency exchange rates are supported via [ExchangeRate-API](https://www.exchangerate-api.com/). Free tier: 1,500 calls/month.

1. Register and obtain an API key
2. Add to `.env`: `CURRENCY_API_KEY=your-api-key`
3. Run:

```bash
php artisan migrate:fresh
php artisan currency:init
```

Configure a different provider in `config/services.php` and update `App\Services\CurrencyService` if desired.

## Automatic Translation

Uses the [andrewdwallo/transmatic](https://github.com/andrewdwallo/transmatic) package with AWS machine translation.

Supported languages include: English, Arabic, German, Spanish, French, Indonesian, Italian, Dutch, Portuguese, Turkish, and Chinese.

For setup and usage, see the [transmatic](https://github.com/andrewdwallo/transmatic) package documentation.

## Plaid Integration

For bank account connectivity:

1. Create a [Plaid](https://plaid.com/) account
2. Add to `.env`:

```env
PLAID_CLIENT_ID=your-client-id
PLAID_CLIENT_SECRET=your-secret
PLAID_ENVIRONMENT=sandbox
PLAID_WEBHOOK_URL=https://your-static-domain.example.com/api/plaid/webhook
```

3. Run the queue worker to process transactions:

```bash
php artisan queue:listen
```

## Testing

Tests use [Pest](https://pestphp.com/).

**Setup:** Create a test database `erpsaas_test`:

```sql
CREATE DATABASE erpsaas_test;
```

**Run tests:**

```bash
composer test
# or
php artisan test
```

The test suite refreshes and seeds the test database automatically. See [Pest Documentation](https://pestphp.com/docs).

## Dependencies

- [filamentphp/filament](https://github.com/filamentphp/filament) – Full-stack UI components
- [andrewdwallo/filament-companies](https://github.com/andrewdwallo/filament-companies) – Company-based authentication for Filament
- [andrewdwallo/transmatic](https://github.com/andrewdwallo/transmatic) – Machine translation
- [akaunting/laravel-money](https://github.com/akaunting/laravel-money) – Currency formatting and conversion
- [squirephp/squire](https://github.com/squirephp/squire) – Static Eloquent models for fixture data
- [awcodes/filament-table-repeater](https://github.com/awcodes/filament-table-repeater) – Table-style repeater for Filament Forms

Refer to each package's documentation to understand how it integrates with the application.

## License

This project is licensed under the MIT License – see the [LICENSE](LICENSE) file for details.
