# Deploying on Render

This project uses Docker on Render, an external MySQL database, and S3-compatible object storage for item photos. Render's local filesystem is ephemeral, so configure object storage before accepting uploads.

## 1. Prepare external services

- Create a MySQL database with network access from Render. Load the schema and data from the database used by the current installation. Keep a backup before migrating.
- Create a private S3-compatible bucket and credentials with permission to list and read/write objects in that bucket. Use HTTPS for a custom S3 endpoint.
- If Google sign-in is enabled, update the OAuth client's authorized JavaScript origins with the deployed Render origin.

## 2. Create the Render service

Use **New > Blueprint** and select this repository. Render reads `render.yaml` and builds the included `Dockerfile`. Alternatively, create a Docker web service and set its health check path to `/health.php`.

Set the prompted values in Render's environment settings:

| Variable | Value |
| --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | Connection details for the external MySQL database. Set `DB_HOST` to the database provider's hostname (not `localhost`) and allow connections from Render. |
| `DB_SSL` | `true` if the database provider requires TLS; otherwise `false` |
| `DB_SSL_CA` | Optional path to a CA certificate, if the provider requires a custom CA |
| `GOOGLE_CLIENT_ID` | OAuth client ID for this application |
| `GOOGLE_ALLOWED_DOMAIN` | Allowed student email domain |
| `S3_BUCKET`, `S3_REGION`, `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY` | Private object storage configuration |
| `S3_ENDPOINT` | Optional HTTPS endpoint for non-AWS S3-compatible providers |
| `S3_PATH_STYLE` | Set to `true` only if required by the provider |
| `LOST_FOUND_VAPID_PUBLIC_KEY` | Public VAPID key from the key generator |
| `LOST_FOUND_VAPID_PRIVATE_KEY_B64` | Base64-encoded private PEM from the key generator |
| `LOST_FOUND_VAPID_SUBJECT` | `mailto:` address monitored by the application team |

Generate a VAPID key pair once on a trusted machine with PHP and OpenSSL:

```powershell
php .\generate_vapid_keys.php
```

Copy both output values into Render. Keep the private value secret, and do not regenerate it after students have subscribed; subscriptions are tied to the public key.

## 3. Migrate existing item photos

Before switching traffic, install the PHP dependency in a project copy with Composer, configure the same S3 environment variables locally, and run:

```powershell
composer install
php .\migrate_item_images.php
```

The script uploads files in `uploads/` using their existing filenames, so database photo paths do not need to be changed. Keep the originals and database backup until the deployed site has been checked. The `id_uploads/` directory is intentionally not included or migrated.

## Notes

- The service uses Render's ephemeral container filesystem. Do not rely on local uploads for persistent production images.
- The legacy `reset_admin.php` and `fix_admin.php` credential-reset scripts are excluded from the Docker image.
- Web Push requires HTTPS and student permission in the browser or device.
- `/health.php` checks connectivity to MySQL. Check Render's logs and the external services' network allowlists if the health check fails.
