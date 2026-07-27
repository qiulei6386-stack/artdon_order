# Lighting Simulation backend API

All JSON responses use UTF-8. POST requests require the current page's CSRF
token in `X-CSRF-Token` or `csrf_token`. Saved projects and reports are
available only to the PHP session that created them.

## `GET /api/lighting-products.php`

Returns products with one or more active photometric `profiles`.

Each profile includes:

- `id`: opaque IES profile ID used by the simulation endpoint.
- `configured_model` and `configuration_match`: the optical variant binding.
- `lumens`, `power_w`, and `beam_angle_deg`.
- `ies`: file display name, LM-63 standard, type, tilt, and validation warnings.
- `data_status`, `manufacturer_validated`, and `disclaimer`.

The initial seed profiles always return
`data_status: "synthetic_preliminary_demo"` and
`manufacturer_validated: false`.

## `POST /api/lighting-simulate.php`

Example request:

```json
{
  "profile_id": "IES-DEMO-AT2020-24D",
  "product_sku": "AT2020",
  "project_name": "Hotel lobby study",
  "configuration": {
    "power": "20W",
    "beam": "24",
    "cct": "3000K"
  },
  "mode": "auto_layout",
  "room": {
    "type": "hotel",
    "length_m": 10,
    "width_m": 8,
    "height_m": 4,
    "installation_height_m": 3,
    "calculation_plane_m": 0.8,
    "mounting_type": "track",
    "target_lux": 300
  },
  "maintenance_factor": 0.8,
  "options": {
    "grid_nx": 30,
    "grid_ny": 24,
    "max_fixtures": 120
  }
}
```

`product_sku` is required and must match the selected profile. The endpoint
also rejects any supplied optical configuration that conflicts with the
profile binding, so a configured product can never be silently replaced or
rewritten to fit another IES profile.

Modes are `single`, `auto_layout`, and `layout`. Manual `layout` also requires:

```json
{"layout":{"columns":5,"rows":4,"rotation_deg":0}}
```

Public calculations accept a 10–36 cell heatmap on each axis (maximum 1,296
points) and at most 120 luminaires. Auto-layout defaults to 96 luminaires and
uses a bounded coarse candidate search followed by one full-resolution
heatmap for the selected layout.

Success returns the product/profile snapshot, calculation result, metrics,
layout, false-color heatmap values, warnings, and a 30-minute
`simulation_token`. A compact result is held server-side in the current
session so the client cannot change calculated values before saving. Parsed
IES/candela data and derivable fixture coordinates are not copied into the
session.

Room types: `retail`, `office`, `hotel`, `restaurant`, `gallery`, `museum`,
`residential`, `warehouse`.

Mounting types: `recessed`, `track`, `surface`, `pendant`, `linear`.

## `POST /api/lighting-project.php`

```json
{
  "simulation_token": "LST-0123456789ABCDEF",
  "project_name": "Hotel lobby study"
}
```

Saves the exact server-held result and returns the saved project. Reusing a
successfully saved token in the same session is idempotent and returns the
same project with `duplicate: true`.

## `GET /api/lighting-project.php?id=SIM-0123456789ABCDEF`

Returns the saved input, product/IES snapshot, layout, metrics, heatmap, and
report URL only when the ID belongs to the current session.

## `GET /api/lighting-report.php?id=SIM-0123456789ABCDEF`

Returns `application/pdf` as an attachment. The path is derived exclusively
from the session-owned database record under `storage/reports/YYYY/MM`; no
client path is accepted. The report includes room and product details, layout,
average/maximum/minimum illuminance, U0 uniformity, a false-color heatmap,
the preliminary-data warning, and the professional-verification disclaimer.

## Common errors

- `404`: profile, pending result, project, or report is unavailable to this
  session.
- `419`: CSRF token expired.
- `422`: invalid room, layout, configuration binding, grid, or maintenance
  factor.
- `429`: per-session or IP-aware network simulation rate limit exceeded.
- `500`: unexpected server or storage failure; use the returned `request_id`
  to locate the server log entry.
