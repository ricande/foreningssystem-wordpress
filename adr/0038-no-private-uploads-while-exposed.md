# ADR-0038: No private files are stored while the web server serves the private directory

**Status:** Accepted. The project owner decided on 2026-10-21 that nothing may be uploaded while the web server serves the protected directory.  
**Date:** 2026-10-21

## Context

ADR-0037 makes the plugin check, with a test file, whether the web server serves the private directory. It left storing unchanged and asked in open question 60 whether uploads should be refused while the directory is exposed. The project owner answered yes.

## Decision

While the check reports the directory as served:

- **Documents and signed copies are refused.** `WpDocumentFileStore::put` and `WpSignedFileStore::put` throw `PrivateStorageExposed` before writing. The upload runs a fresh check, so a server that was fixed a minute ago accepts the file, and a server that became exposed today refuses it. The screens say why the file was not saved: "storage_exposed", not "wrong file type". No document row or signed-copy row is created.
- **Minutes PDFs are delivered but not stored.** A PDF is not an upload. It is rendered when someone who may read it downloads it. `WpMinutesPdfStore::put` refuses the write using the check result kept for the day, and `MinutesPdf::bytes` delivers the rendered bytes anyway. Downloads keep working, and no new copy is left where it could be fetched.
- **Files already stored stay where they are.** Deleting them would destroy records. Moving them requires a directory the plugin can trust, which is what `FORENINGSPLUGIN_PRIVATE_DIR` is for. The error notice tells the administrator to block or move the directory, and says that uploads are stopped until then.
- **An unclear check answer does not block,** as in ADR-0037. A host that refuses requests to its own address would otherwise lose documents altogether on a guess.

## Consequences

- An association on a host it cannot configure cannot add documents or signed copies until it sets `FORENINGSPLUGIN_PRIVATE_DIR` to a directory outside the web root, or the host blocks the directory. That is the intended trade-off: no new protected file is published by mistake.
- Each document or signed-copy upload costs one local HTTP request for the check while the directory is under the web root.
