# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status

Greenfield. The only content so far is `docs/plan.md` — a detailed, authoritative development plan (in French). No `composer.json`, `src/`, or tests exist yet. **`docs/plan.md` is the source of truth**; read it in full before implementing. The sections below summarize what it prescribes so you don't have to re-derive it, but defer to the plan on any conflict.

## What this tool is

`puml-splitter` is a PHP/Symfony CLI that post-processes PlantUML class-diagram output from `smeghead/php-class-diagram` (v1.6.x). It splits one huge flat-namespace diagram (100+ classes) into several readable sub-diagrams grouped by auto-detected clusters, plus an aggregated overview map.

**It does not parse PHP.** It consumes an already-extracted `.puml` graph and operates only on that graph — no `nikic/php-parser`, no PHP source analysis.

Final goal is to digest any `.puml` graph from any sources.

## Toolchain (per plan §3)

- PHP >= 8.2 (oldest version with security support), `declare(strict_types=1)` everywhere, `readonly` where relevant.
- `symfony/console` ^7.0 (standalone component, not full framework); `symfony/filesystem`, `symfony/process` allowed. Minimize all other deps.
- PHPUnit ^13 for tests. PHPStan at level 8.
- Distribution: Composer project + PHAR build via `box-project/box`; `Dockerfile` on `php:8.3-cli-alpine` with `plantuml` + `graphviz`.

Commands do not exist yet. When scaffolding, the expected developer commands will be (verify against the real `composer.json` once created):

```bash
composer install
vendor/bin/phpunit                        # all tests
vendor/bin/phpunit --filter <TestName>    # single test / method
vendor/bin/phpstan analyse                # static analysis, max level
```

Target end-to-end usage: `php-class-diagram src/ | puml-splitter split --stdin --render --output docs/uml`

## Architecture (per plan §4)

Strict pipeline, each stage unit-testable in isolation, graph logic pure and separated from the CLI command:

```
Parser (.puml → immutable Document)
  → Graph pipeline (→ Partition: clusters + hubs + inter-cluster edges)
    → Output generators (write .puml files + index.html + optional SVG)
```

Intended layout under `src/`: `Command/SplitCommand.php` (single `split` command), `Puml/{Parser,Writer,Model/*}`, `Graph/{Graph,ConnectedComponents,HubDetector,LouvainClusterer,PrefixClusterer,ClusterRefiner}`, `Output/{ClusterPumlGenerator,OverviewPumlGenerator,IndexHtmlGenerator,SvgRenderer}`, `Config/SplitConfig`.

### Clustering pipeline order (plan §6)
1. **Hub removal** (`HubDetector`): node is a hub if `in-degree >= hub-threshold` (default 8) or forced via `--hub=ALIAS`. Hubs are pulled out before clustering.
2. **Connected components** (undirected) on the remaining graph; any component `<= max-size` becomes a cluster directly.
3. **Split large components** per `--strategy` (`auto`|`louvain`|`prefix`, default `auto`). `auto` computes both and keeps whichever minimizes inter-cluster edges under the size constraint (tie → `prefix`).
4. **Refine** (`ClusterRefiner`): re-split clusters over `max-size` (25), merge clusters under `min-size` (3) into most-connected neighbor, else a `misc` cluster.
5. **Hub policy** (`--hub-policy`, default `duplicate`): `duplicate` (hub `<<shared>>` in each referencing cluster), `separate` (dedicated `shared-types` cluster), or `exclude`.

## Non-negotiable invariants (plan §11)

These shape every design decision — violating them is a bug:

- **Byte-identical bodies**: `Writer` re-emits class bodies exactly as parsed (no reformatting). Fidelity over prettiness. Round-trip: generated `.puml` must be re-parsable by this tool's own `Parser`.
- **Total determinism**: same input + same options → identical output files. Sort every order-sensitive iteration by **alias, alphabetically**. Use a fixed seed in Louvain.
- **PlantUML aliases are the canonical identifiers**; the quoted short names are display-only (used by `PrefixClusterer`).
- **No lost edges**: integration invariant is `internal + inter-cluster + hub edges == total input edges`; every non-hub node appears exactly once.
- **Never crash on unknown lines**: unrecognized `.puml` lines are kept as passthrough + a warning to stderr. Exit code stays 0 with parse warnings; non-zero only on fatal errors (unreadable file, zero classes).
- Don't over-engineer the graph: adjacency arrays keyed by alias suffice at ~150 nodes.

## Parser input format (plan §5)

Line-by-line, tolerant of indentation variations. Declarations: `^(abstract class|class|interface|enum)\s+"([^"]+)"\s+as\s+(\S+)`, capturing the multi-line `{ … }` body raw. Relations: `^\s*(\S+)\s+(\.\.>|-->|<\|--|<\|\.\.|o--|\*--|-\[[^\]]*\]->)\s+(\S+)(\s*:\s*(.+))?$`, preserving arrow type and label. Inheritance arrows count as graph edges like dependencies. Lines between `@startuml` and the first declaration are headers, re-injected into every output file. `package` blocks: flatten them (original package becomes ignored node metadata in v1).

## Milestones (plan §10)

M1 skeleton (Composer + `split --dry-run` + Parser) → M2 clustering (components, hubs, refiner, `--strategy=prefix`) → M3 outputs (cluster/overview `.puml` + index.html + `--render`) → M4 Louvain + `auto` + determinism tests → M5 distribution (PHAR, Dockerfile, README, CI).

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