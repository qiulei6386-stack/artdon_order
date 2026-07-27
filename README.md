# Artdon Procurement Platform V1.0

Artdon’s Singapore procurement site combines a product catalogue, server-side
configuration rules, Project Cart, RFQ/order-request workflow and preliminary
IES lighting simulation in one PHP application.

## Implemented

- Data-driven commercial-lighting catalogue and product pages
- Server-validated product configuration, model generation, MOQ, price mode and
  lead-time result
- Durable, versioned Project Cart stored in SQLite; browser storage is a cache
- Transactional RFQ, sample and order-request records with immutable line
  snapshots, safe attachments and duplicate-submission protection
- Type C / `TILT=NONE` LM-63 parser and point-by-point direct-illuminance engine
- One-light result, automatic regular-grid layout, spacing, average/maximum/
  minimum lux and U0 uniformity
- False-colour heatmap, saved simulation project, PDF report and cart linkage
- Guided project brief recommendations that resolve to supported room, target,
  mounting and catalogue values
- Protected IES Library import/versioning command
- Idempotent ERP/CRM outbound queue; endpoint and credentials are environment
  configured
- Responsive public pages, sitemap, health endpoint and protected application
  storage

## Important data status

The checked-in catalogue, stock, price and IES profiles are demonstration data.
The IES files are synthetic workflow fixtures and are labelled as preliminary
in the interface and report. They are not manufacturer photometry and must not
be used for product claims or final construction design.

Production authority still requires:

- Naming System product feed, inventory and customer price rules
- Manufacturer-approved IES files for each optical configuration
- ERP/CRM endpoint, credentials and approved field mapping
- Customer/staff authentication identities and access policy
- Transactional email service
- Approved legal, privacy, retention, shipping and commercial terms

Until customer authentication is connected, the Account area deliberately
shows no sample customer records.

## Runtime

- Nginx with HTTPS
- PHP 8.2
- PHP extensions: PDO SQLite, JSON, Session, OpenSSL, GD, Zip and cURL
- Fileinfo is used when present; strict signature checks provide the documented
  canonical-MIME fallback when a minimal BaoTa PHP build omits it
- Writable protected `storage/` directory

The current Tencent deployment path is:

```text
/www/wwwroot/artdon_order
```

## Install or update

```bash
cd /www/wwwroot/artdon_order
php tools/migrate.php
chown -R www:www storage
find storage -type d -exec chmod 750 {} \;
find storage -type f -exec chmod 640 {} \;
```

Copy the security and routing rules from `nginx.conf.example` into the site’s
Nginx configuration and adapt only the PHP-FPM socket/certificate locations.

## Application data

The deployable node uses:

```text
storage/artdon.sqlite
```

Migrations are checksum protected and idempotent. The checked-in PHP product
configuration remains a read-only emergency seed/fallback; once migrated, the
application reads the catalogue and rules through the database layer.

## IES Library

An import is pending by default:

```bash
php tools/import_ies.php \
  --file=/secure/incoming/model.ies \
  --product=AL1010 \
  --model=AL1010-20W-3000K-24D \
  --options='{"power":"20W","beam":"24"}'
```

Only an authorised operator should add `--validated --activate`. See
`docs/IES_LIBRARY.md`.

## Verification

```bash
php tools/migrate.php
php tests/configurator_cases.php
php tests/cart/run.php
php tests/procurement/run.php
php tests/lighting/run.php
php tests/simulation_backend/run.php
php tests/ai_advisor.php
php tests/integration/run.php
php tests/ies_import/run.php
php tests/lighting-report/run.php
```

Then verify:

```text
GET /api/health.php
GET /lighting-simulation
GET /api/lighting-products.php
GET /cart
```

The full release checklist is in `docs/ACCEPTANCE_TEST.md`.
