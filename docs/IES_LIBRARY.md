# IES Library operations

The customer simulator lists only profiles with `status = active` and a parser
status of `valid` or `warning`. A parsed file is not active merely because its
LM-63 syntax is valid.

## Safety model

Run `php tools/migrate.php` as a separate deployment step before using the
importer. The importer opens only an already-ready database; it never runs
migrations or inserts demo catalogue data.

Activation binds four things as one versioned optical record:

1. the IES checksum;
2. one active catalog product;
3. the complete configuration accepted by the server configurator;
4. the exact configured model generated from that configuration.

The activation command rejects missing options, unknown options, invalid
combinations, a different configured model, or an occupied version. The stored
`option_signature` is canonical JSON containing the complete accepted
configuration, never a partial optical hint.

`--validated` records that an authorised operator checked file provenance and
the product/optic mapping. It does not itself prove a manufacturer claim,
laboratory measurement, regulatory result, or construction suitability.

## Step 1: pending parse and protected storage

Use a pending import to check the file and hold it outside the customer-facing
library:

```bash
php tools/import_ies.php \
  --file=/secure/incoming/AT2020-20W-24D.ies \
  --product=AT2020 \
  --model=AT2020-20W-BK-3000K-24D-LIF-ON \
  --options='{"power":"20W","beam":"24"}'
```

The output and database messages say `PENDING ONLY`. Partial options are
permitted at this stage only because the record cannot be selected by a
customer. Re-running the same checksum and the same pending mapping is
idempotent.

Before activation, an authorised operator should independently confirm:

- the original file came from the expected manufacturer or test laboratory;
- the product, wattage, optic, CCT/CRI and hardware revision match;
- the file is not a renamed profile for a different variant;
- the source version and approval evidence are retained outside this tool.

## Step 2: get the server-authoritative model

Obtain the full option list and generated model from the shared configurator.
The activation JSON must include every option in the active configuration
schema, including options that do not change the visible model string.

For the current `AT2020` schema, a complete example is:

```json
{
  "beam": "24",
  "cct": "3000K",
  "color": "Black",
  "control": "On/Off",
  "cri": "CRI90",
  "driver": "Lifud",
  "fixture": "Complete",
  "light_source": "Bridgelux",
  "power": "20W"
}
```

The current server-generated model for that exact selection is:

```text
AT2020-20W-BK-3000K-24D-LIF-ON
```

Do not construct this string manually. Product schemas and SKU components can
change; use the value returned by the server configurator.

## Step 3: activate

Run the same file with the complete configuration, exact generated model and
both approval flags:

```bash
php tools/import_ies.php \
  --file=/secure/incoming/AT2020-20W-24D.ies \
  --product=AT2020 \
  --model=AT2020-20W-BK-3000K-24D-LIF-ON \
  --options='{"beam":"24","cct":"3000K","color":"Black","control":"On/Off","cri":"CRI90","driver":"Lifud","fixture":"Complete","light_source":"Bridgelux","power":"20W"}' \
  --validated \
  --activate
```

If the checksum already exists as a pending record for this product, the tool
promotes that same record after all activation checks pass. It does not create
a checksum duplicate. A repeated, identical activation is also idempotent.

## Versions and replacement

Without `--version`, a new checksum receives the next version for the same
product and complete option signature. On successful activation, the previous
active record for that exact signature is archived in the same transaction.

Use an explicit version only when the release process assigned one:

```bash
php tools/import_ies.php ... --version=3 --validated --activate
```

The command fails before activation if that product, complete signature and
version already exist. It also rejects attempts to reuse a checksum for a
different product, configuration, model or version.

## Supported parser boundary

- LM-63 Type C photometry
- `TILT=NONE`
- maximum file size 5 MB
- strictly ordered vertical and horizontal angles
- no negative candela values
- no more than 500,000 candela values

Files outside this boundary are rejected instead of approximated silently.
The simulator remains a preliminary direct-illuminance aid. Final construction
design must be verified by a qualified lighting designer or professional
lighting software.

## Storage

The default protected location is `storage/ies/<sku>/<sha256>.ies`. Production
web-server rules must deny direct access to `storage/`.

Tests or installations with a separate protected volume may set
`APP_IES_STORAGE_PATH` to an absolute directory. The database then records that
protected absolute path; it is never returned by the customer API.
