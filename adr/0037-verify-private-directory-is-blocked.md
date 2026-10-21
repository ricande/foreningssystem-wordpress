# ADR-0037: Check that the web server refuses the private directory

**Status:** Accepted. The project owner approved it on 2026-10-21.  
**Date:** 2026-10-21

## Context

Without `FORENINGSPLUGIN_PRIVATE_DIR`, protected documents, minutes PDFs and signed copies go to `wp-content/uploads/assoc-private`, which is under the web root. The plugin writes an Apache `.htaccess` deny rule there. Nginx ignores that file, and so does Apache with `AllowOverride None`.

The security review on 2026-10-21 confirmed in the lab:

- With the `.htaccess` honoured, a direct request for a board document returns 403.
- With the guard file ignored, the same request returns 200 and the file, to a visitor without a login. A JPEG with PHP after the magic bytes is served as `image/jpeg` and not run.

File names are `document-<sha256 of the bytes>`, `signed-<revision>-<sha256 of the bytes>` and `revision-<revision>-<sha256 of text and payload>`. Nobody can guess them without the content, and the directory has an `index.php`. The exposure is therefore one leaked file name away (a backup listing, a file manager plugin, server logs, autoindex), not one guess away.

Until now, an administrator clicked "The web server blocks the directory" and the warning disappeared, whether or not anything had been configured.

## Decision

- The plugin checks the claim. It writes a test file with a random token (`probe-<random>.pdf`, nothing from the association) into the private directory, requests it from the site's own address with `wp_remote_get`, and removes it at once. The answer is kept in a transient for a day.
  - **Served** (2xx with the token): the directory is exposed. An error notice without an acknowledgement button, and a Site Health result of "critical". An earlier acknowledgement does not hide it. A "Check again" button repeats the request after the server is fixed and never records an acknowledgement.
  - **Refused** (401, 403, 404, 410): the directory is blocked. No warning, and no acknowledgement needed.
  - **Unclear** (request failed, redirect, server error, page without the token): the old behaviour, a warning until acknowledged.
- Pressing "The web server blocks the directory" runs the check first and does not record the acknowledgement when the file is served.
- The filter `foreningsplugin_private_storage_probe_url` can change the address, for a site behind a proxy whose own address does not reach the web server. The lab test uses it, because WP-CLI runs in another container.
- **The plugin does not refuse to store files** in an exposed directory. See Options.

The request goes only to the site's own address and sends nothing but the request for the test file. It is not telemetry.

## Options considered

1. **Keep the acknowledgement only.** Cheap, but it lets an administrator silence a real exposure by mistake. Rejected.
2. **Check, and warn loudly (chosen).**
3. **Check, and refuse new private uploads while exposed.** The strongest protection, but a small association on a host it cannot configure would lose documents, signed copies and minutes PDFs entirely, and the minutes PDFs are generated when someone downloads them. With unguessable names, the cost is out of proportion. Left as open question 60.

## Consequences

- An administrator on Nginx without a deny rule sees an error, not a warning that can be dismissed.
- The check costs one local HTTP request per day per site, and one more on Site Health and on "Check again".
- A host that blocks loopback requests gets the unclear answer and keeps the acknowledgement flow.
