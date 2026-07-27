# Artdon Procurement Platform V1.0 — Application Architecture

## Runtime

- Nginx terminates HTTPS and routes application URLs to PHP 8.2.
- The existing PHP templates and URLs remain stable.
- PDO provides the durable application data layer.
- SQLite is the deployable default for the Singapore application node.
- The repository keeps a MySQL/MariaDB schema for later central integration.
- Browser storage is a cache only; business records are validated and persisted
  by the server.

## Application boundaries

- Catalog owns product, variant, inventory, media, and configuration metadata.
- Configurator owns rule evaluation, model generation, MOQ, price, and lead time.
- Project Cart stores immutable product/configuration snapshots.
- Procurement creates RFQ and Order Request records transactionally.
- Photometry owns IES parsing and validated optical records.
- Simulation owns room inputs, layout, lux grid, statistics, and algorithm version.
- Reports render stored simulation results and are linked to cart/request items.
- Integration writes ERP/CRM work to an idempotent queue processed outside the
  customer request.

## Trust boundaries

- Product identifiers, prices, model strings, cart totals, and simulation
  summaries received from a browser are never authoritative.
- All database access uses prepared statements.
- Uploaded and generated files live under protected storage and are served only
  through an application-controlled download.
- Existing demo products remain a seed/fallback until Naming System data is
  available. The procurement site does not create official base models.

## IES V1 calculation scope

- LM-63 Type C photometry with `TILT=NONE`
- Downward luminaires and a horizontal work plane
- Point-by-point direct illuminance
- Symmetric and explicitly tabulated horizontal-angle distributions
- Automatic regular-grid layout
- Average, maximum, minimum, and minimum/average uniformity
- Two-dimensional false-colour heatmap

Unsupported photometric types or malformed files must be rejected explicitly.
The V1 calculation is a procurement design aid, not a replacement for a signed
professional lighting design. Reflections, daylight, obstructions, emergency
lighting, and regulatory compliance remain outside V1 unless later specified.

