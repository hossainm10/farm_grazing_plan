# farmOS Attachments

`farm_attachments` adds private, multi-value file attachments to farmOS asset and log records. Images and documents are stored using Drupal's private file scheme.

## Supported records and files

Attachments are available on asset and log bundles, including asset and log create and edit forms.

Supported file extensions:

- JPG and JPEG
- PNG
- WebP
- PDF
- DOCX

Each file is limited to 10 MB. Multiple files can be attached to one record. Images are rendered with thumbnails, while documents are rendered as private download links.

## Install from the Grazing Plan fork

Clone the fork into the farmOS Composer project. Replace `OWNER` with the GitHub account or organization that owns the fork:

```bash
cd /path/to/farmOS
git clone https://github.com/OWNER/farm_grazing_plan.git repos/farm_grazing_plan
cd repos/farm_grazing_plan
git checkout feature/attachments
```

The module must then be available at:

```text
repos/farm_grazing_plan/modules/farm_attachments
```

From the Drupal project root, configure the local Composer path repository and install the module:

```bash
cd /path/to/farmOS/www
composer config repositories.farm_attachments path repos/farm_grazing_plan/modules/farm_attachments
composer require drupal/farm_attachments:*
vendor/bin/drush en farm_attachments -y
vendor/bin/drush cr
```

For Docker-based development, run Composer and Drush inside the existing Drupal container instead:

```bash
docker compose exec -T www composer config repositories.farm_attachments path repos/farm_grazing_plan/modules/farm_attachments
docker compose exec -T www composer require drupal/farm_attachments:*
docker compose exec -T www vendor/bin/drush en farm_attachments -y
docker compose exec -T www vendor/bin/drush cr
```

## Private file storage

Private storage must be outside the public Drupal web root. Configure it in an environment-local settings file such as `settings.local.php`; do not commit machine-specific paths:

```php
$settings['file_private_path'] = '/opt/drupal/private';
```

Ensure the local settings file is included by `settings.php`. Create the directory and grant access to the PHP/web-server user without making it world-writable. For example:

```bash
mkdir -p /opt/drupal/private
chown www-data:www-data /opt/drupal/private
chmod 770 /opt/drupal/private
```

In a host/container setup, `/opt/drupal/private` must map to a persistent host directory outside the web root. Verify the configured path and permissions before enabling the module:

```bash
vendor/bin/drush php:eval 'print \\Drupal::config("system.file")->get("path.private");'
ls -ld /opt/drupal/private
```

## Verification

1. Create a temporary asset and a temporary log.
2. Upload a JPG and a PDF to each record.
3. Save and edit the records to confirm multiple attachments persist.
4. Confirm the image thumbnail and PDF download link render.
5. Try a `.txt` or `.exe` file; it should be rejected.
6. Try a file larger than 10 MB; it should be rejected.
7. Confirm the stored files are under `private://` and outside the public web root.
8. Request a private download URL while authenticated with record view permission; it should succeed.
9. Request the same URL anonymously; it should be denied.

## Security limitations

Private storage alone does not provide record-level authorization. Download access depends on Drupal's file access checks and the associated asset or log view permissions. A file referenced by multiple records may be accessible if any referenced record and field is viewable.

Before deploying, test with an authenticated user who does not have view permission for the associated asset or log. That user must be denied the private download URL. Also test anonymous access and access after removing a record's view permission.

Do not commit private uploads, credentials, database files, generated directories, or local settings containing machine-specific paths.