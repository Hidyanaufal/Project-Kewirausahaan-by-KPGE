---
name: create-pull-request
description: >-
  Create a pull request targeting main for KPG ERP: check branch state,
  optionally collect issue tracker references, analyze git log and diff against
  main, derive scope from Laravel paths and business domains (accounting,
  addons, banking, etc.) and migrations, then push and run gh pr create. Use
  when the user asks to open a PR, create a PR to main, or wants the full PR
  workflow with structured description.
---

# Create Pull Request

Create a pull request to `main` for **KPG ERP** (`kpge`) with a detailed description.

**Product:** ERP untuk Koperasi Pertamina Geothermal — Laravel 12 + Filament 3, double-entry accounting, multi-company (Filament Companies). Lihat `README.md` untuk fitur kustom (PPh 23, WAPU, Projects, Assets, dll.).

## Repository layout (scope)

Monolith Laravel di repo root (bukan monorepo `api/` / `web/`). Gunakan **scope tag domain** dari path yang berubah; gabungkan beberapa tag jika PR menyentuh lebih dari satu area (mis. `[accounting][addons]`).

| Path pattern | Scope tag | Role |
|--------------|-----------|------|
| `app/Models/Accounting/`, `app/Filament/Company/Resources/Accounting/`, `app/Services/*` accounting, `tests/Feature/Accounting/` | `[accounting]` | COA, jurnal, invoice/bill logic, PPh 23 / WAPU, budgets, transactions |
| `app/Models/Banking/`, `app/Filament/Company/Resources/Banking/`, `tests/Feature/` banking | `[banking]` | Rekening bank, Plaid, impor transaksi |
| `app/Filament/Company/Resources/Sales/`, sales models | `[sales]` | Client, estimate, invoice, recurring invoice |
| `app/Filament/Company/Resources/Purchases/`, purchase models | `[purchases]` | Vendor, bill |
| `app/Models/Addons/`, `app/Filament/Company/Resources/Addons/`, `tests/Feature/Addons/` | `[addons]` | Projects, members, assets, depreciation, account defaults, roles |
| `app/Filament/Company/Pages/Reports/`, `app/Transformers/*Report*`, `tests/Feature/Reports/` | `[reports]` | Balance sheet, trial balance, income statement, dll. |
| `app/Filament/Company/Resources/Core/`, `app/Models/Core/` | `[core]` | Department dan entitas inti perusahaan |
| `app/Filament/Company/Clusters/Settings/`, `app/Models/Setting/` | `[settings]` | Pengaturan perusahaan, document defaults |
| `app/Jobs/`, `app/Mail/`, `app/Notifications/`, `app/Services/Notification*` | `[notifications]` | Email, queue jobs, penerima notifikasi |
| `app/Console/Commands/` | `[commands]` | Artisan commands (data fix, batch) |
| `database/migrations/` | `[db]` | Skema — tambah **type** `[migration]` di judul jika PR terutama schema |
| `database/seeders/`, `database/factories/` | `[db]` | Data awal / factory |
| `resources/views/`, `resources/css/`, `resources/js/`, `package.json` | `[ui]` | Blade, Vite, PDF views, email templates |
| `app/Filament/` (sisanya, tanpa domain di atas) | `[filament]` | Panel, widgets, forms bersama |
| `config/`, `.env.example` | `[config]` | Konfigurasi & env baru |
| `tests/` (hanya test, tanpa produksi) | `[tests]` | Pest feature/unit |
| `docs/` | `[docs]` | Dokumentasi / RCA |
| `scripts/` | `[scripts]` | Helper scripts |
| `.github/`, `docker-compose*.yaml`, `Dockerfile`, `nginx/`, `Makefile`, `php/` | `[infra]` | CI, Docker, deploy |

Jika perubahan tidak masuk domain di atas tetapi di `app/`, `routes/`, `bootstrap/`, gunakan `[app]`.

**Migrations:** Laravel (`php artisan migrate`), file di `database/migrations/*.php` — bukan golang-migrate.

## Instructions

1. First, check the current branch:

   ```bash
   git branch --show-current
   ```

2. If on `main` branch:

   - First, pull the latest main: `git pull origin main`
   - Check `git status` and `git log --oneline -5` to understand the current state
   - If there are staged/unstaged changes or unpushed commits, **auto-create a feature branch**:
     - Derive a branch name from the changes (e.g., `feat/short-description` or `fix/short-description`)
     - Run `git checkout -b <branch-name>` to create and switch to the new branch
     - Continue with the PR flow
   - If there are no changes at all (clean working tree, no unpushed commits), inform the user there's nothing to create a PR for

3. **Ask the user for issue tracker reference(s)** (optional):

   - Prompt: "Do you have any Jira/GitHub issue references for this PR? (e.g., KPG-1234, Fixes #42)"
   - Accept multiple card numbers (comma or space separated)
   - If the user says "no", "none", "skip", or leaves it empty, proceed without references
   - Store the references for inclusion in the PR description

4. Get the commits that will be included in the PR (commits since diverging from main):

   ```bash
   git log main..HEAD --oneline
   ```

5. Get the full diff to understand all changes:

   ```bash
   git diff main...HEAD
   ```

5b. Determine scope from changed paths:

   ```bash
   git diff main...HEAD --name-only
   ```

   Map prefixes to scope tags (gunakan `grep` per domain, contoh):

   ```bash
   git diff main...HEAD --name-only | grep -E "Accounting|/Sales/|Invoice|Bill|Journal|Transaction|Budget" | head -1
   git diff main...HEAD --name-only | grep -E "Banking|Plaid|ConnectedAccount" | head -1
   git diff main...HEAD --name-only | grep -E "/Addons/|/Addons\\.|Project|Asset|Depreciation|Member" | head -1
   git diff main...HEAD --name-only | grep -E "Reports/|Report" | head -1
   git diff main...HEAD --name-only | grep -E "^database/migrations/" | head -1
   git diff main...HEAD --name-only | grep -E "^app/Jobs/|^app/Mail/|^app/Notifications/" | head -1
   git diff main...HEAD --name-only | grep -E "^docs/" | head -1
   git diff main...HEAD --name-only | grep -E "^(docker-compose|Dockerfile|Makefile|\\.github/|nginx/)" | head -1
   ```

   - Combine matching scopes in the title (e.g. `[accounting][notifications][migration]`)
   - If only one domain changed, use a single scope tag (e.g. `[addons]`)
   - PR yang hanya menambah/ubah migration: sertakan `[migration]` sebagai **type** dan `[db]` sebagai scope

6. Analyze ALL commits and the full diff thoroughly, then generate a well-structured PR description:

   **Title Guidelines:**

   - Format: `[issue refs][scope][type] Description`
   - **Issue refs** (if provided): `[KPG-1234]` or `Fixes #42` in body; multiple: `[KPG-1234][KPG-5678]`
   - **Scope**: dari tabel di atas (`[accounting]`, `[addons]`, `[infra]`, …)
   - **Type**: `[feat]`, `[fix]`, `[refactor]`, `[chore]`, `[docs]`, `[migration]`
   - **Description**: Ringkasan singkat perubahan
   - Examples:
     - `[KPG-42][accounting][migration] Add approval columns to projects`
     - `[addons][feat] Link invoices to project requestor`
     - `[accounting][fix] Correct balance sheet equity aggregation`
     - `[notifications][feat] Email accounting on invoice/project approval`

   **Summary Section:**

   - Write 2-4 bullet points capturing the HIGH-LEVEL purpose of the PR
   - Focus on WHAT was achieved and WHY (dampak bisnis/akuntansi jika relevan)
   - Each bullet should be a complete, standalone statement
   - Start with action verbs (Add, Implement, Fix, Update, Remove, Refactor)

   **Changes Section:**

   - Group by domain atau layer: Accounting, Addons, Filament UI, Database, Notifications, Infra
   - Use format: `**\`path/to/file.php\`**: Description of what changed`
   - Sebut class/method konkret: `InvoiceApprovedNotification`, `SendQuotationApprovedNotification`, `BalanceSheetReport`, Filament `*Resource`, migration timestamp
   - Untuk PR besar, gunakan subheading (e.g. `### Accounting`, `### Migrations`, `### Tests`)

   **Test Plan Section:**

   - Checkbox format untuk langkah verifikasi
   - **Automated tests** (default sebelum merge):
     - DB test: `erpsaas_test` (lihat `README.md`)
     - `composer test` atau `php artisan test`
     - Filter domain: `php artisan test --filter=ApprovalNotification`
   - **Local / Docker manual** bila UI atau integrasi:
     - Setup: `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate`
     - Assets: `npm run build` atau `npm run dev`; Filament: `php artisan filament:assets`
     - Queue (notifikasi/email): `php artisan queue:listen`
     - Docker testing: `make deploy-testing` → `http://localhost:8011` (port 8011)
     - Login seed default: `admin@erpsaas.com` / `password` (setelah `php artisan db:seed`)
   - **Filament:** sebut panel Company, resource/page yang diuji, company/role jika relevan
   - **Accounting:** sebut dokumen (invoice, bill, jurnal), status approval, dampak neraca/jurnal bila perlu
   - Bug fix: langkah reproduksi sebelum/sesudah

   **Optional Sections (include when relevant):**

   - **Issues**: Jira / GitHub dari step 3
   - **Breaking Changes**: env baru (`.env.example`), migration irreversible, perubahan COA defaults, perilaku PPh 23 / WAPU
   - **Screenshots**: Perubahan Filament / Blade — minta screenshot panel Company
   - **Related Issues**: `Fixes #123` atau `Related to #456`
   - **Deploy notes**: `make deploy-prod`, queue worker, `php artisan migrate` di server

7. Show the user the generated PR title and description.

8. Push the branch (if not already pushed) and create the PR:

   ```bash
   git push -u origin <branch-name>
   gh pr create --base main --title "<title>" --body "$(cat <<'EOF'
   <body markdown here>
   EOF
   )"
   ```

## PR Description Template

```markdown
## Summary
- [High-level description of what this PR achieves]
- [Another key change or improvement]
- [Why this change was needed - the motivation]

## Changes
### Accounting
- **`app/Models/Accounting/Invoice.php`**: [Specific description]

### Addons / Filament
- **`app/Filament/Company/Resources/Addons/ProjectResource.php`**: [What changed]

### Database
- **`database/migrations/2026_02_26_025738_add_requestor_id_to_projects_table.php`**: [Schema change]

### Tests
- **`tests/Feature/Accounting/ApprovalNotificationTest.php`**: [Coverage added]

## Test Plan
- [ ] `composer test` (or `php artisan test --filter=...`)
- [ ] `php artisan migrate` on a fresh or existing DB if migrations included
- [ ] [Filament: login to Company panel, navigate to …, verify …]
- [ ] [Queue: run `php artisan queue:listen`, trigger action, confirm email/job]
- [ ] [Edge case or regression scenario]

---
```

## Notes

- Always target `main` branch
- Review ALL commits in `main..HEAD`, not just the latest — description must reflect the full changeset
- Be specific with PHP paths, class names, Filament resources, and migration filenames
- Mention breaking changes prominently at the TOP of the description
- For Filament / `resources/views/` UI changes, suggest screenshots from Company panel
- **PR Title Format**: `[issue][scope][type] Description` — issue refs optional
- **Product context:** multi-company ERP, Indonesian tax (PPh 23, PPN WAPU), cooperative addons — see `README.md` and `docs/` (e.g. RCA balance sheet)
- **CI:** `.github/workflows/ci.yml` may not match this Laravel app; still run `composer test` locally and note results in the test plan until CI is aligned with KPG
