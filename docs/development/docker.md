# Docker Development

NENE2 uses Docker as the standard development runtime so contributors do not need to install the required PHP version on the host OS.

## Services

`compose.yaml` defines one service:

- `app`: PHP 8.4 Apache container with Composer installed

Apache serves `public_html/` as the document root.

## First Setup

```bash
docker compose build
docker compose run --rm app composer install
```

## Verification

```bash
docker compose run --rm app composer validate --strict
docker compose run --rm app composer test
docker compose run --rm app composer analyse
docker compose run --rm app composer check
```

Use these Docker commands as the default verification path when the host PHP version is older than NENE2's runtime requirement.

## Web Server

```bash
docker compose up -d app
```

Then open:

```text
http://localhost:8080/
```

Stop it with:

```bash
docker compose down
```

## Runtime Boundary

Only `public_html/` is exposed by Apache. Source code, `vendor/`, tests, config, and frontend source stay outside the document root.
