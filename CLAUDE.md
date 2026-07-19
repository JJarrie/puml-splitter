# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status

**M1–M5 are implemented, tested, and merged**: parser + model, full clustering pipeline (components, hubs, refiner, prefix + Louvain + auto strategies), output generation (cluster/overview `.puml`, `index.html`, `--render` via PlantUML), and distribution (PHAR, Dockerfile, CI). **Current milestone: M6 — Style & UX** (plan §7bis): layout injection, deterministic edge coloring, stereotype styling, inter-cluster hyperlink navigation, legends.

**`docs/plan.md` is the source of truth**; read it in full before implementing. It has been amended several times since M1 (out-degree hubs, per-hub policies, token-level anonymization, §7bis) — do not rely on memory of an earlier version. The sections below summarize it; defer to the plan on any conflict. If a plan choice seems wrong or ambiguous mid-implementation: STOP, expose the problem, and wait for arbitration — the plan gets corrected in `docs/plan.md`, never silently diverged from.

## What this tool is

`puml-splitter` is a PHP/Symfony CLI that post-processes PlantUML class-diagram output from `smeghead/php-class-diagram` (v1.6.x). It splits one huge flat-namespace diagram (100+ classes) into several readable sub-diagrams grouped by auto-detected clusters, plus an aggregated overview map with cross-diagram navigation.

**It does not parse PHP.** It consumes an already-extracted `.puml` graph and operates only on that graph — no `nikic/php-parser`, no PHP source analysis.

Final goal is to digest any `.puml` class-diagram graph from any source.

## Toolchain (per plan §3)

- PHP >= 8.2 floor, `declare(strict_types=1)` everywhere, `readonly` where relevant.
- `symfony/console` ^7.0 (standalone component); `symfony/filesystem`, `symfony/process` allowed. No other runtime deps — adding a Composer dependency requires explicit human approval.
- PHPUnit version: use what `composer.json` constrains (plan §3 documents the PHP-floor rationale). PHPStan at **max** level — must be clean before concluding any milestone.
- Distribution: PHAR via `box-project/box` (prod deps only); `Dockerfile` on `php-cli-alpine` with `plantuml` **and `graphviz`** (PlantUML class-diagram rendering hard-requires `dot`; do not "slim" graphviz out of the image).

```bash
composer install
composer test                             # all tests (PHPUnit)
vendor/bin/phpunit --filter <TestName>    # single test / method
vendor/bin/phpstan analyse                # static analysis, max level
php bin/puml-splitter split tests/fixtures/very-large.puml --dry-run   # smoke check
```

Target end-to-end usage: `php-class-diagram src/ | puml-splitter split --stdin --render --output docs/uml`

## Architecture (per plan §4)

Strict pipeline, each stage unit-testable in isolation, graph logic pure and separated from the CLI command:

```
Parser (.puml → immutable Document)
  → Graph pipeline (→ Partition: clusters + hubs + inter-cluster edges)
    → Output generators (write .puml files + index.html + optional SVG)
```

Layout under `src/`: `Command/SplitCommand.php` (single `split` command), `Puml/{Parser,Writer,Model/*}`, `Graph/{Graph,ConnectedComponents,HubDetector,LouvainClusterer,PrefixClusterer,ClusterRefiner,Partitioner}`, `Output/{ClusterPumlGenerator,OverviewPumlGenerator,IndexHtmlGenerator,SvgRenderer}`, `Config/SplitConfig`.

### Clustering pipeline order (plan §6)
1. **Hub removal** (`HubDetector`): node is a hub if `in-degree >= hub-threshold` (default 8), OR `out-degree >= hub-out-threshold` (default 20), OR forced via `--hub=ALIAS`. The detector records the detection reason (in / out / forced) — it drives policy and display.
2. **Connected components** (undirected) on the remaining graph; any component `<= max-size` becomes a cluster directly.
3. **Split large components** per `--strategy` (default `auto`). Shipped: `prefix`, `louvain`; `auto` computes both, keeps whichever minimizes inter-cluster edges under the size constraint (tie → `prefix`), and reports the compared scores. Planned (plan §6ter): `map` (M7, versioned human partition, exempt from the refiner), `seeds` (M8, BFS expansion from aggregate roots), `leiden` (M9, replaces louvain inside `auto`), backlog `bisect`/`jaccard`/`layers` (M10, only on demonstrated need). All strategies implement the same `Clusterer` interface.
4. **Refine** (`ClusterRefiner`): re-split clusters over `max-size` (25) via the injected strategy (never force-split), merge clusters under `min-size` (3) into most-connected neighbor, else a `misc` cluster.
5. **Hub policy** (`--hub-policy`, default `duplicate`; per-hub `--hub-policy-override=ALIAS:POLICY`): `duplicate` (hub `<<shared>>` in each referencing cluster), `separate` (dedicated `shared-types` cluster), or `exclude`. **Differentiated default**: an out-only hub gets `separate` automatically unless overridden — never duplicate an out-hub (it would re-inject dozens of edges into every sub-diagram).

## Non-negotiable invariants (plan §11)

These shape every design decision — violating them is a bug:

- **Byte-identical bodies**: `Writer` re-emits class bodies exactly as parsed (no reformatting). Round-trip: generated `.puml` must be re-parsable by this tool's own `Parser` without warnings.
- **Total determinism**: same input + same options → identical output files, colors and cluster order included. Sort every order-sensitive iteration by **alias, alphabetically**. Louvain has no randomness at all (deterministic node visit order, no shuffle, no unseeded rand).
- **PlantUML aliases are the canonical identifiers**; the quoted short names are display-only (used by `PrefixClusterer` and anonymization tokenization).
- **No lost edges**: `internal + inter-cluster + hub edges == total input edges`; every non-hub node appears exactly once (hubs may appear multiple times only under `duplicate`).
- **Never crash on unknown lines**: unrecognized `.puml` lines are kept as passthrough + a warning to stderr. Exit code stays 0 with parse warnings; non-zero only on fatal errors (unreadable file, zero classes).
- **M6 style is strictly additive**: `--layout=none --edge-color=none --no-legend` output must be byte-identical to pre-M6 output (covered by a non-regression test).
- **Manually mapped clusters are never refined**: with `--strategy=map`, human-assigned clusters bypass the refiner entirely (size bounds produce a warning, never a change); only fallback-clustered nodes go through the normal pipeline.
- Don't over-engineer the graph: adjacency arrays keyed by alias suffice at ~150 nodes.

## Fixtures & anonymization (plan §5)

- `tests/fixtures/very-large.puml` is derived from real project data via `scripts/anonymize-puml.php` (token-level CamelCase anonymization preserving both topology and naming structure, with mandatory self-verification).
- **Never edit real-data-derived fixtures by hand** (human or agent) — regenerate through the script only. A past hand-edit silently destroyed the fixture's degree distribution; the script's invariants exist to make that impossible.
- Reference acceptance numbers on this fixture with default options: 156 classes, 290 relations, exactly 3 hubs (in=12 `duplicate`, in=11 `duplicate`, out=68 `separate`), edge invariant `internal + inter-cluster + hub == 290`.

## Milestones (plan §10)

M1 skeleton ✅ → M2 clustering (components, hubs in/out, refiner, `prefix`, anonymization script) ✅ → M3 outputs (cluster/overview `.puml` + index.html + `--render`) ✅ → M4 Louvain + `auto` + determinism ✅ → M5 distribution (PHAR, Dockerfile, README, CI) ✅ → **M6 Style & UX (§7bis) ← current** → M7 `map` strategy + `--emit-map` → M8 `seeds` strategy → M9 Leiden → M10 strategy backlog (on demonstrated need only).

Before concluding any milestone: `composer test` + `vendor/bin/phpstan analyse` green, plus a dry-run (or full run) on `tests/fixtures/very-large.puml` with its output pasted in the final report. Do not commit unless explicitly asked.

<!-- rtk-instructions v2 -->
# RTK (Rust Token Killer) - Token-Optimized Commands

## Golden Rule

**Always prefix commands with `rtk`**. If RTK has a dedicated filter, it uses it. If not, it passes through unchanged. This means RTK is always safe to use.

**Important**: Even in command chains with `&&`, use `rtk`:
```bash
# ❌ Wrong
git add . && git commit -m "msg" && git push

# ✅ Correct
rtk git add . && rtk git commit -m "msg" && rtk git push
```

## RTK Commands by Workflow

### Build & Compile (80-90% savings)
```bash
rtk cargo build         # Cargo build output
rtk cargo check         # Cargo check output
rtk cargo clippy        # Clippy warnings grouped by file (80%)
rtk tsc                 # TypeScript errors grouped by file/code (83%)
rtk lint                # ESLint/Biome violations grouped (84%)
rtk prettier --check    # Files needing format only (70%)
rtk next build          # Next.js build with route metrics (87%)
```

### Test (60-99% savings)
```bash
rtk cargo test          # Cargo test failures only (90%)
rtk go test             # Go test failures only (90%)
rtk jest                # Jest failures only (99.5%)
rtk vitest              # Vitest failures only (99.5%)
rtk playwright test     # Playwright failures only (94%)
rtk pytest              # Python test failures only (90%)
rtk rake test           # Ruby test failures only (90%)
rtk rspec               # RSpec test failures only (60%)
rtk test <cmd>          # Generic test wrapper - failures only
```

### Git (59-80% savings)
```bash
rtk git status          # Compact status
rtk git log             # Compact log (works with all git flags)
rtk git diff            # Compact diff (80%)
rtk git show            # Compact show (80%)
rtk git add             # Ultra-compact confirmations (59%)
rtk git commit          # Ultra-compact confirmations (59%)
rtk git push            # Ultra-compact confirmations
rtk git pull            # Ultra-compact confirmations
rtk git branch          # Compact branch list
rtk git fetch           # Compact fetch
rtk git stash           # Compact stash
rtk git worktree        # Compact worktree
```

Note: Git passthrough works for ALL subcommands, even those not explicitly listed.

### GitHub (26-87% savings)
```bash
rtk gh pr view <num>    # Compact PR view (87%)
rtk gh pr checks        # Compact PR checks (79%)
rtk gh run list         # Compact workflow runs (82%)
rtk gh issue list       # Compact issue list (80%)
rtk gh api              # Compact API responses (26%)
```

### JavaScript/TypeScript Tooling (70-90% savings)
```bash
rtk pnpm list           # Compact dependency tree (70%)
rtk pnpm outdated       # Compact outdated packages (80%)
rtk pnpm install        # Compact install output (90%)
rtk npm run <script>    # Compact npm script output
rtk npx <cmd>           # Compact npx command output
rtk prisma              # Prisma without ASCII art (88%)
```

### Files & Search (60-75% savings)
```bash
rtk ls <path>           # Tree format, compact (65%)
rtk read <file>         # Code reading with filtering (60%)
rtk grep <pattern>      # Search grouped by file (75%). Format flags (-c, -l, -L, -o, -Z) run raw.
rtk find <pattern>      # Find grouped by directory (70%)
```

### Analysis & Debug (70-90% savings)
```bash
rtk err <cmd>           # Filter errors only from any command
rtk log <file>          # Deduplicated logs with counts
rtk json <file>         # JSON structure without values
rtk deps                # Dependency overview
rtk env                 # Environment variables compact
rtk summary <cmd>       # Smart summary of command output
rtk diff                # Ultra-compact diffs
```

### Infrastructure (85% savings)
```bash
rtk docker ps           # Compact container list
rtk docker images       # Compact image list
rtk docker logs <c>     # Deduplicated logs
rtk kubectl get         # Compact resource list
rtk kubectl logs        # Deduplicated pod logs
```

### Network (65-70% savings)
```bash
rtk curl <url>          # Compact HTTP responses (70%)
rtk wget <url>          # Compact download output (65%)
```

### Meta Commands
```bash
rtk gain                # View token savings statistics
rtk gain --history      # View command history with savings
rtk discover            # Analyze Claude Code sessions for missed RTK usage
rtk proxy <cmd>         # Run command without filtering (for debugging)
rtk init                # Add RTK instructions to CLAUDE.md
rtk init --global       # Add RTK to ~/.claude/CLAUDE.md
```

## Token Savings Overview

| Category | Commands | Typical Savings |
|----------|----------|-----------------|
| Tests | vitest, playwright, cargo test | 90-99% |
| Build | next, tsc, lint, prettier | 70-87% |
| Git | status, log, diff, add, commit | 59-80% |
| GitHub | gh pr, gh run, gh issue | 26-87% |
| Package Managers | pnpm, npm, npx | 70-90% |
| Files | ls, read, grep, find | 60-75% |
| Infrastructure | docker, kubectl | 85% |
| Network | curl, wget | 65-70% |

Overall average: **60-90% token reduction** on common development operations.
<!-- /rtk-instructions -->