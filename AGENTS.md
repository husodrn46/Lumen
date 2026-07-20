# Repository Guidelines

## Project Structure & Module Organization
This repository is a classic PHP web app (no framework) centered in the repo root. Main entry points include `giris.php`, `index.php`, and `lg_fis.php`. Shared bootstrapping lives in `ayr.php`, auth/session guard logic in `kontrol.php`, DB connection settings in `_baglanti_.inc`, and runtime feature flags in `_bilgi_.inc`.

Primary modules are folder-based:
- `ayar/`: user, permission and system settings (incl. `marka.php` logo upload)
- `rapor/`: reporting pages and report APIs
- `stok/`: stock/inventory operations
- `barkod/`: barcode/label utilities
- `doviz/`: multi-currency order flows
- `sql/`: schema/setup scripts for the app's own `M_*` tables

Shared front-end assets are under `tm/` (`tm/css`, `tm/js`) and `assets/`. Composer dependencies are in `vendor/`. First-time setup runs through the `/kurulum.php` wizard, which writes `.env` and `_baglanti_.inc`.

## Build, Test, and Development Commands
- `composer install`: install PHP dependencies.
- `php -S localhost:8000 -t .`: run a local server for quick checks.
- `bash scripts/validate.sh`: run syntax lint (`php -l`), sanity scans, and Rector dry-run.
- `php -l path\to\file.php`: lint a single PHP file.
- `php vendor/bin/rector process --dry-run`: preview refactor changes without editing files.

## Coding Style & Naming Conventions
Use `declare(strict_types=1);` in new/updated PHP files. Follow existing 4-space indentation and keep braces/style consistent with nearby files. Keep page filenames snake_case (for example `stok_hareket_pdf.php`) and function names descriptive. For protected pages, include files in this order:
1. `include_once(__DIR__ . '/ayr.php');`
2. `include(__DIR__ . '/kontrol.php');`

Prefer PDO prepared statements over inline SQL interpolation.

## Testing Guidelines
There is no dedicated automated test suite yet. Minimum validation before merging:
1. Run `bash scripts/validate.sh`.
2. Manually verify login (`giris.php`) and the changed module flow.
3. For DB changes, add/update SQL scripts in `sql/` and verify on a test database.

## Commit & Pull Request Guidelines
Git history is not available in this workspace snapshot, so use a clear, consistent format such as:
- `feat(rapor): add monthly customer export`
- `fix(giris): handle invalid remember token`

PRs should include scope, affected files/modules, database impact, manual test steps, and screenshots for UI changes.

## Security & Configuration Tips
Never commit real credentials or production secrets in `_baglanti_.inc`, `.env`, or similar files. Keep logs/export artifacts out of commits, and preserve existing auth checks (`kontrol.php`) on all protected endpoints.

## Agent Operation Note
For critical file operations in this repository, use Bash-based commands/workflows as the default execution path.
