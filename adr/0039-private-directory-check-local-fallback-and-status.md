# ADR-0039: The private directory check asks 127.0.0.1 when the site address cannot be reached, tests every file type, and always shows its result

**Status:** Accepted. The project owner approved it on 2026-10-26.  
**Date:** 2026-10-21

## Context

The check from ADR-0037 requests a test file from the site's own address. In the Docker labs, and on hosts where the site's own name does not resolve or route from the server itself, that request fails. The check then answers "unclear", and the administrator sees the yellow warning even when the directory is protected. That happened on the clean lab (8091), where Apache refused direct requests with 403.

The check also tested only `.pdf`. A server rule that blocks some file endings and not others could pass the test while `.jpg` or `.png` files were served.

The project owner asked for the check to work on its own, and for information both when the directory is unsafe and when it is protected.

## Decision

1. **Every stored file type is tested.** One test file each for `.pdf`, `.jpg` and `.png`. The directory is exposed if any of them is served, and blocked only if all are refused (`PrivateStorageExposure::combine`).
2. **Local fallback, only when the site address cannot be reached at all.** If every request to the site's own address fails with a connection error, the same path is requested from `http://127.0.0.1`, then `https://127.0.0.1` without certificate checks, with the site's host name in the `Host` header. Every answer the site address gives, including errors such as 500, is used as it is and is never replaced by a local answer.
3. **The result is always shown.** A status box on the Documents page shows the current state, when it was checked and through which address:
   - outside the web root
   - safe (every test file refused)
   - not safe (uploads stopped, ADR-0038)
   - could not be asked, either confirmed by an administrator or not confirmed.

   Administrators with `manage_association` get a "Check again" button, which returns them to the page they came from. Site Health says "safe" in the same words when the directory is blocked.

## Risk accepted

A local answer can differ from what visitors get when something in front of the site, such as a CDN, a load balancer on another machine, or a proxy, serves files that the local web server refuses. The fallback is used only when the site's own address is unreachable from the server. The status box names the address that answered (for example `http://127.0.0.1 (Host: example.org)`), so an administrator can see that the answer came from the local server. The filter `foreningsplugin_private_storage_probe_url` still points the check at any address the administrator chooses.

## Consequences

- The lab and hosts without a route to their own name get a real answer instead of the yellow warning.
- A check now makes up to three requests to the site address, and up to six more locally when that address is unreachable, once a day and on uploads, Site Health and "Check again".
- `plugin/tests/lab-private-storage-selfcheck.php` exercises this end to end: it signs in, presses "Check again" in the real web server without any address filter, and reads the Documents page in both the safe and the unsafe state.
