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

The plugin writes an Apache `.htaccess` deny rule and an `index.php` into the directory. Those files do nothing on Nginx, and they do nothing on Apache when `AllowOverride` is off. The association screen and Site Health warn until an administrator confirms that the web server blocks the directory, or until the files are moved outside the web root.

Moving an existing install to `FORENINGSPLUGIN_PRIVATE_DIR` copies the stored document, signed-copy, and minutes PDF files into the new directory.

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
