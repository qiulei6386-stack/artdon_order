# Artdon Procurement Platform V1.0 — Requirements Traceability

This document turns the approved product strategy, complete requirements, and
IES Simulation specification into release gates.

## Current release decision

- Gates A and B are implemented for the clearly labelled demonstration
  catalogue, including server validation, durable cart, procurement records,
  bounded IES calculation, saved projects, PDF reports, and cart linkage.
- Gate C includes structured guided recommendations and a secure, idempotent
  outbound queue. Live ERP/CRM delivery remains disabled until the approved
  endpoint, credentials, mapping, and acknowledgement contract are supplied.
- Gate D is met for public-data isolation, protected storage, upload
  quarantine, retention, capacity limits, mobile layouts, and demo labelling.
  Customer/staff account workflows and tenant isolation remain intentionally
  unavailable until authentication identities and policy are approved.
- Manufacturer photometry is not bundled. The three seed profiles are
  synthetic preliminary workflow fixtures and cannot satisfy a
  manufacturer-validated lighting acceptance test.

## Product priorities

1. Product master and media data
2. Server-validated Product Configurator
3. Durable Project Cart and procurement requests
4. IES Simulation, heatmap, report, and cart linkage
5. Structured AI product guidance
6. ERP/CRM integration and operational hardening

## Release gates

### Gate A — Procurement foundation

- Product records are loaded through the application data layer.
- Quick Configure and the full configurator use one rule schema.
- The server regenerates model, price, MOQ, lead time, and review state.
- Project Cart survives page reloads and is stored on the server.
- RFQ and Order Request submissions create immutable item snapshots.
- Duplicate submissions are stopped by a durable idempotency key.

### Gate B — Lighting decision workflow

- A product configuration resolves to a matching validated IES record.
- Room dimensions, mounting height, work plane, and target lux are validated.
- Single-light and automatic multi-light calculations return lux statistics.
- A two-dimensional false-colour heatmap is rendered from the calculated grid.
- Simulation input, algorithm version, and results are stored together.
- A report can be downloaded and linked to a Project Cart line.

### Gate C — Assisted selection and integration

- AI guidance returns structured, rule-compatible product recommendations.
- Recommendations link into the configurator and simulation workflow.
- ERP/CRM communication uses signed APIs rather than direct database access.
- Failed integration jobs retry safely without creating duplicate records.
- Sync attempts and material business actions are auditable.

### Gate D — Production acceptance

- Customer and staff data is access-controlled and tenant-isolated.
- Uploads, reports, IES files, and customer attachments are private by default.
- Security, backup/restore, performance, mobile, accessibility, and SEO checks pass.
- Demo prices, stock, accounts, and photometric files are clearly identified
  until authoritative business data replaces them.

## External inputs still required for authoritative production data

- Naming System product API contract and signing credentials
- Real inventory and customer price rules
- Validated manufacturer IES files per optical configuration
- Customer authentication policy and staff administrator identities
- SMTP or transactional email service
- ERP/CRM API endpoints, credentials, and field mappings
- Approved privacy, retention, payment, shipping, and commercial policy text
