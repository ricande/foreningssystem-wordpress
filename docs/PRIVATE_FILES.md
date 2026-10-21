# Private association files

Protected documents, minutes PDFs, and signed copies are not Media Library attachments. The plugin stores the bytes itself and serves them only after an authorization check.

## Where the files go

Set a directory outside the WordPress web root in `wp-config.php` when the host allows it:

```php
define('FORENINGSPLUGIN_PRIVATE_DIR', '/var/assoc-private');
```

The PHP user must be able to create that directory. It must not sit under the directory WordPress is served from. A filter named `foreningsplugin_private_directory` can supply the same path from code.

The plugin judges "under the web root" by the path the filesystem really means, not by how the path is spelled. Once the directory exists, its resolved path decides, so a symlink or a `..` segment that leads back under the web root is treated as being inside it and the warning stays. A path that does not exist yet is judged by its spelling until it is created.

If neither is set, or the directory cannot be created, the plugin uses `wp-content/uploads/assoc-private`. That directory is inside the public web root. Download links still require authorization, but that does not stop a web server from reading a file when the URL is known.

The plugin writes an Apache `.htaccess` deny rule and an `index.php` into the directory. Those files do nothing on Nginx, and they do nothing on Apache when `AllowOverride` is off.

## The check

While the directory is under the web root, the plugin checks once a day whether the web server serves it. It writes one test file per stored type (`.pdf`, `.jpg`, `.png`) with a random token, requests them from the site's own address, and removes them. If that address cannot be reached at all from the server, as inside Docker, the same path is asked from `127.0.0.1` with the site's host name. The Documents page always shows the result, when it was checked and through which address, with a "Check again" button. See `adr/0037-verify-private-directory-is-blocked.md` and `adr/0039-private-directory-check-local-fallback-and-status.md`.

- The server returns the file: the association screen shows an error and Site Health reports "critical". An earlier "The web server blocks the directory" does not hide this. Fix the server, then press "Check again". Until then, new documents and signed copies are refused, and minutes PDFs are delivered without being stored. Files already stored stay where they are. See `adr/0038-no-private-uploads-while-exposed.md`.
- The server refuses every test file (401, 403, 404 or 410): no warning, and the Documents page says the files are safe.
- The request fails or the answer is unclear, for example when the host blocks requests to its own address: the screen warns until an administrator confirms that the web server blocks the directory. Pressing that button runs the check first.

If the site's own address does not reach the web server, for example behind a proxy, the filter `foreningsplugin_private_storage_probe_url` can supply another address for the test file.

File names are SHA-256 hashes of their content, so a file cannot be guessed. A file whose name leaks, through a backup listing, a file manager or server logs, can still be read while the directory is served.

## Changing the directory later

The database stores file names, never a directory, so the plugin records which directories it uses in two options: `assoc_private_storage_root` for the directory new files are written to, and `assoc_private_storage_earlier_roots` for directories that may still hold files.

Changing the directory, in either direction and however many times, moves the stored document, signed-copy, and minutes PDF files into the new directory. A file is moved only when the copy in the new directory is byte for byte the same, and a name that already exists there with different content is never overwritten. Anything that could not be moved keeps its old directory in the recorded state, and the plugin keeps reading it from there, so a download never fails for a file that is still on disk. A directory that is temporarily unavailable, such as an unmounted volume, stays recorded instead of being treated as empty.

Do not delete an old private directory before the association screen and downloads show the files in the new one. See `adr/0025-private-storage-roots.md`.

If the configured directory cannot be used at all, for example because a volume is not mounted, the plugin falls back to `wp-content/uploads/assoc-private` as it always has, records the configured directory as an earlier root, and moves in the files it can reach. That puts protected files inside the web root, so the association notice and Site Health warning come back. Fix the directory rather than acknowledging the warning.

## Apache

Use this when `.htaccess` is ignored. Replace the path with the real directory:

```apache
<Directory "/var/www/html/wp-content/uploads/assoc-private">
    Require all denied
</Directory>
```

## Nginx

Nginx does not read `.htaccess`. Block the fallback directory in the server configuration:

```nginx
location ^~ /wp-content/uploads/assoc-private/ {
    deny all;
}
```

A directory outside the web root needs no location block, because the web server is not serving it.
