# syntax=docker/dockerfile:1
FROM php:8.3-cli-alpine AS build

RUN apk add --no-cache git unzip curl bash \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && curl -fsSL -o /usr/local/bin/box https://github.com/box-project/box/releases/latest/download/box.phar \
    && chmod +x /usr/local/bin/box

WORKDIR /app

# Full install first (cached unless composer.json/composer.lock change) so
# rebuilding after a source-only edit doesn't re-resolve dependencies.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --quiet

COPY bin ./bin
COPY src ./src
COPY box.json ./box.json
COPY scripts ./scripts

# Single source of truth for "strip dev deps, compile, restore dev deps" —
# same script used locally and in CI (composer phar). Also the reason
# --optimize-autoloader here actually sees the app's classes: it now runs
# after src/ is copied in, not before.
RUN BOX_BIN=box bash scripts/build-phar.sh

FROM php:8.3-cli-alpine

# plantuml pulls in its own JRE dependency; graphviz provides the dot layout
# engine plantuml shells out to for class diagrams. Both are resolved from
# Alpine's own repos at build time, so the runtime image needs no network.
RUN apk add --no-cache plantuml graphviz

COPY --from=build /app/bin/puml-splitter.phar /usr/local/bin/puml-splitter.phar

WORKDIR /work

ENTRYPOINT ["php", "/usr/local/bin/puml-splitter.phar"]
CMD ["--help"]
