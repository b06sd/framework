# Storage

`trunk package:install storage` (it installs `league/flysystem`). Files live on named disks in `config/storage.php`, and every disk is a [Flysystem](https://flysystem.thephpleague.com/docs/usage/filesystem-api/) filesystem, so the API is Flysystem's own.

## Using a disk

Inject `Storage`:

```php
use Trunk\Storage\Storage;

$disk = $this->storage->disk();               // the default disk (STORAGE_DISK)
$disk->write('reports/2026-10.csv', $csv);
$csv = $disk->read('reports/2026-10.csv');
$stream = $disk->readStream('exports/big.zip');  // a stream resource: never the whole file in memory
$disk->delete('reports/2026-10.csv');

$this->storage->disk('s3')->writeStream('backups/db.sql.gz', $resource);
```

Everything else (`fileExists`, `listContents`, `move`, `copy`, `fileSize`, `mimeType`, `lastModified`, `temporaryUrl`) is on the disk as Flysystem documents it.

Files are **private** unless written with `['visibility' => 'public']`. On the local disk that means `0640` files and `0750` directories. A path may not contain `..` at all (not even one that would stay inside the disk), and a symbolic link inside a local disk is refused rather than followed.

## Disks

| Driver | For | Settings |
| --- | --- | --- |
| `local` | the application's own files, in `storage/app` (outside `public/`, so never reachable by URL) | `root` (absolute) |
| `s3` | Amazon S3, or anything S3-compatible: MinIO, Cloudflare R2, DigitalOcean Spaces | `bucket`, `region`, `key`, `secret` (a secret: `S3_SECRET`, never built), `endpoint` (for non-AWS services), `path_style` (MinIO), `prefix`. Needs `composer require league/flysystem-aws-s3-v3`. Without a key, the AWS SDK's own credentials are used (an IAM role, for instance). |
| `memory` | tests | needs `composer require --dev league/flysystem-memory` |

`trunk build` checks every disk and fails when the default one is incomplete (an `s3` disk without a bucket, say); a disk nobody uses yet may stay empty. Set `STORAGE_DISK=s3` and the `S3_*` variables to move the default to S3 without touching code.

## Uploads

Never keep an uploaded file under the name, or with the type, the client gave it: both are whatever the sender wants them to be. `storeUpload()` does it safely:

```php
$file = $request->getUploadedFiles()['avatar'] ?? null;

if (!$file instanceof UploadedFileInterface) {
    throw new HttpException(422, 'Choose a picture to upload.');
}

$path = $this->storage->storeUpload($file, 'avatars', ['image/png', 'image/jpeg', 'image/webp']);
// "avatars/3f2a...9c.png": a random name, the extension of what the file really contains
```

The type is read from the file's contents, so an HTML page sent as `photo.jpg` is `text/html` and refused by that list (`['image/*']` accepts any image). The file is streamed to the disk, never read into memory; size limits are `http.max_file_bytes` and PHP's own. `StorageException` explains a refusal; keep the path you get back.

## Serving files

Send stored files, uploads above all, as **downloads**:

```php
return $this->responses->download($this->storage->disk()->readStream($path), 'October report.csv', 'text/csv');
```

`download()` sets `Content-Disposition: attachment` (with the name made safe and any Unicode encoded), `X-Content-Type-Options: nosniff`, and a sandboxing `Content-Security-Policy`. An uploaded HTML or SVG file shown inline on your own domain could run script as your site; as a download it cannot. The file is streamed, with a `Content-Length` when the size is known. Check that the user may see the file before sending it.

For large files on S3, a short-lived signed link sends the browser straight to the bucket:

```php
$url = $this->storage->disk('s3')->temporaryUrl($path, new DateTimeImmutable('+5 minutes'));
```

Related: [HTTP](http.md) (upload limits), [Configuration](configuration.md).
