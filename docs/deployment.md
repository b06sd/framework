# Deployment

## Build and ship

```bash
composer install --no-dev --optimize-autoloader
APP_ENV=production trunk build          # or just `trunk build`; it always builds for production
```

Ship the code and `build/`. **Production refuses to start without a build** (fail closed), and `trunk doctor` in the target environment checks the build is fresh. Rebuild after changing `.env` values or config that your config files read; `secret()` values are read from the real environment at run time and are never in `build/`.

## Web server

Point the document root at `public/` and send every request that is not a static file to `public/index.php`.

```nginx
location / { try_files $uri /index.php$is_args$args; }
location ~ \.php$ { fastcgi_pass unix:/run/php/php-fpm.sock; include fastcgi_params;
                    fastcgi_param SCRIPT_FILENAME $document_root/index.php; }
```

Enable OPcache (`opcache.validate_timestamps=0` in production, with a reload on deploy). Do not expose `.env`, `build/`, `storage/` or `vendor/` (they are outside `public/`).

## Environment

Set real environment variables (not a committed `.env`): `APP_ENV=production` (or unset), `DB_*` including `DB_PASSWORD`, `LOG_CHANNEL=stderr` in containers. Files in `build/` are `0640` in `0750` directories; `trunk doctor` warns about a world-readable `.env` or world-writable `storage/logs`.

## Behind a load balancer

List your proxies in `http.trusted_proxies` so scheme, host and client IP are right (HSTS is sent only on https requests, and cookies are `Secure` in production). Set `auth.session.secure` only if you serve https; over plain http, sessions need `secure = false`.

## Workers, scheduling, maintenance

Run `trunk queue:work` under a supervisor that restarts it (workers exit cleanly at their memory, job or time limits):

```ini
# systemd: /etc/systemd/system/trunk-worker.service
[Service]
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php vendor/bin/trunk queue:work --queue=default --max-time=3600
Restart=always
User=www-data
```

Cron: `trunk auth:prune` (dead sessions, tokens, throttle counters). Run `trunk migrate` as a deploy step.

## Observability

Logs are JSON lines with request and trace ids. `trunk package:install health` adds `/health/live` (liveness) and `/health/ready` (dependency checks); set `METRICS_TOKEN` to expose `/metrics` (Prometheus text; metrics are per process, so under php-fpm each worker reports its own requests).
