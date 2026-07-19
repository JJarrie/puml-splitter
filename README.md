# puml-splitter

`php-class-diagram` renders a flat 150-class PHP namespace as one giant PlantUML
diagram that no layout engine can make readable. `puml-splitter` post-processes
that `.puml` output — no PHP parsing involved — and splits it into smaller,
readable per-cluster diagrams plus an aggregated overview map.

## Installation

### Composer

```bash
composer require jjarrie/puml-splitter
vendor/bin/puml-splitter split --help
```

### PHAR

Download or build a standalone `puml-splitter.phar` (requires `php-cli` with
the `phar` extension; no other PHP dependency needed):

```bash
composer install
composer phar          # writes bin/puml-splitter.phar
php bin/puml-splitter.phar split --help
```

The PHAR bundles prod dependencies only (`symfony/console`, `symfony/filesystem`,
`symfony/string`) — no PHPUnit, no PHPStan.

### Docker

The image bundles PHP, the PHAR, `plantuml`, and `graphviz`, so `--render`
works out of the box with no host dependencies and no network access at
runtime:

```bash
docker build -t puml-splitter .
docker run --rm puml-splitter split --help
```

## Usage

Target end-to-end pipeline, piping straight from `php-class-diagram`:

```bash
php-class-diagram src/ | puml-splitter split --stdin --render --output docs/uml
```

Equivalent with the Docker image (mount an output directory, pipe stdin in):

```bash
php-class-diagram src/ | docker run --rm -i -v "$PWD/docs/uml:/out" \
    puml-splitter split --stdin --render --output /out
```

Preview the split plan — clusters, hub table, edge accounting — without
writing any file:

```bash
puml-splitter split diagram.puml --dry-run
```

## Options

| Option | Default | Description |
| --- | --- | --- |
| `input` (argument) | — | Path to the input `.puml` file (omit when using `--stdin`). |
| `--output=DIR` | `./puml-split` | Output directory. |
| `--max-size=N` | `25` | Maximum cluster size. |
| `--min-size=N` | `3` | Minimum cluster size; smaller clusters get merged. |
| `--strategy=auto\|louvain\|prefix\|map` | `auto` | Clustering strategy for oversized components. |
| `--map=FILE` | — | Required with `--strategy=map`: a versioned, hand-editable partition (JSON). |
| `--emit-map=FILE` | — | Export the computed partition as a map file, whatever the strategy. |
| `--hub-threshold=N` | `8` | In-degree at which a node is classified as a hub. |
| `--hub-out-threshold=N` | `20` | Out-degree at which a node is classified as a hub. |
| `--hub=ALIAS` | — | Force an alias to hub status (repeatable). |
| `--hub-policy=duplicate\|separate\|exclude` | `duplicate` | How hubs are represented in the clusters that reference them. |
| `--hub-policy-override=ALIAS:POLICY` | — | Per-hub policy override (repeatable). |
| `--render` | off | Also render SVGs via `plantuml`. |
| `--plantuml-bin=PATH` | `plantuml` | Path to the `plantuml` binary. |
| `--header=STRING` | — | Additional header line injected into every output file (repeatable). |
| `--stdin` | off | Read the `.puml` from standard input instead of a file argument. |
| `--dry-run` | off | Print the split plan without writing any file. |

Exit code stays `0` even with parse warnings (unrecognized lines are kept as
passthrough and reported on stderr); it's non-zero only on fatal errors
(unreadable input, zero classes found).

## Tuning

- **Hub thresholds** (`--hub-threshold` / `--hub-out-threshold`): a node with
  a high in-degree (many classes point *to* it — e.g. a shared value object)
  or a high out-degree (it points *to* dozens of others — e.g. a container
  class generated from an XSD) collapses connected-components into one giant
  blob and destroys Louvain's modularity if left in. Raise `--hub-threshold`
  if genuinely central domain types are being pulled out as hubs; raise
  `--hub-out-threshold` if a legitimate aggregate root with many owned
  relations is wrongly flagged. Use `--hub=ALIAS` to force edge cases either
  way.
- **`--max-size` / `--min-size`**: keep clusters small enough to render
  legibly (default `25`) without fragmenting into too many single-purpose
  files (default floor `3`, merged into the most-connected neighbor or into
  a catch-all `misc` cluster otherwise).
- **Strategy**: `auto` (default) runs both `prefix` and `louvain` and keeps
  whichever cuts fewer inter-cluster edges while respecting `--max-size`
  (ties go to `prefix`) — the safest default for unfamiliar input. Prefer
  `prefix` directly when classes come from a naming-convention-heavy source
  (e.g. POPOs generated from an XSD, where `InvoiceLine`/`InvoiceHeader`
  share a meaningful prefix) — it's cheap and the grouping is predictable.
  Prefer `louvain` directly when names carry no structural signal but the
  relation graph does (community detection on the dependency graph itself).
- **Hub policy** (`--hub-policy`): `duplicate` (default) repeats each hub,
  marked `<<shared>>`, in every cluster that references it — good for a
  handful of hubs. `separate` puts every hub in one dedicated
  `shared-types` cluster instead — better once duplication would bloat every
  file. `exclude` drops hubs and their edges entirely from the output. Hubs
  detected *only* by out-degree default to `separate` even under a global
  `duplicate` policy (overridable per hub via `--hub-policy-override`):
  duplicating a 60-outgoing-edge container into every referencing cluster
  would reintroduce the very unreadability the tool exists to remove.

## Map workflow

No clustering algorithm knows your domain. `--strategy=map` lets a human
override the computed partition — durably, reviewably, and re-playable on
every future run — instead of re-fighting the same auto-clustering quirks
after each regeneration.

The intended loop:

```bash
# 1. Run auto (or any strategy) and export what it computed.
puml-splitter split diagram.puml --emit-map=docs/cluster-map.json --dry-run

# 2. Hand-edit the debatable ~5%: rename a cluster, move a class or two,
#    merge two clusters that auto split apart for a bad reason. It's plain JSON:
#    { "clusters": { "invoice": ["InvoiceHeader", "InvoiceLine"], ... }, "fallback": "auto" }

# 3. From now on, split with the edited map instead of re-guessing.
puml-splitter split diagram.puml --strategy=map --map=docs/cluster-map.json --render --output docs/uml
```

Commit `docs/cluster-map.json` alongside the generated docs — it's the
versioned record of the clustering decisions a human actually made. When the
source diagram gains new classes the map has never seen, `fallback` decides
what happens to them:

- `auto` (default) — clustered normally, as if the map didn't exist for them.
- `misc` — grouped into one `misc` cluster, to be triaged and mapped later.
- `error` — the run fails, listing every unmapped alias, until you update the map.

Mapped clusters are **never** touched by the size refiner — a cluster you
assigned by hand can sit outside `--min-size`/`--max-size` and the tool will
warn about it, not "fix" it. A class the map assigns to a cluster is also
never treated as a hub, even if its degree would normally qualify it, or an
explicit `--hub=ALIAS` names it: the map is the more specific, more recent
human decision, so it wins. Aliases the map doesn't mention are unaffected
and keep going through normal hub detection.

## Example output

```bash
puml-splitter split PPN.puml --output docs/uml --render
```

```
docs/uml/
├── overview.puml / overview.svg          # aggregated map: one package per cluster
├── cluster-invoice.puml / .svg           # one pair per cluster
├── cluster-order.puml / .svg
├── cluster-shared_types.puml / .svg      # present when --hub-policy=separate
├── cluster-misc.puml / .svg              # undersized clusters with no strong neighbor
└── index.html                            # self-contained summary + embedded SVGs
```

## Development

```bash
composer install
vendor/bin/phpunit                        # all tests
vendor/bin/phpunit --filter <TestName>    # single test / method
vendor/bin/phpstan analyse                # static analysis, max level
composer phar                             # build bin/puml-splitter.phar
docker build -t puml-splitter .           # build the container image
```

See [`docs/plan.md`](docs/plan.md) for the full design and rationale.
