# ADR-0025: The private storage root is recorded state

**Status:** Accepted  
**Date:** 2026-09-26

## Context

`FORENINGSPLUGIN_PRIVATE_DIR`, or the `foreningsplugin_private_directory` filter, picks the directory that holds documents, minutes PDFs, and signed copies. The database stores only file names, never a directory, so the directory is deployment state outside the database.

The first implementation moved files from the `wp-content/uploads/assoc-private` fallback into the configured directory on every read of the path. Changing from one configured directory to another was not covered: the files stayed in the old directory, the database kept pointing at names that no longer existed where the plugin looked, and downloads answered "not found" for an archive original that was still on disk. Restoring the old value brought the files back, but nothing said so.

A storage root can also be a mount that is temporarily unavailable, and a move can fail file by file on permissions. "The directory is empty" and "the directory cannot be read right now" must not look the same.

## Options considered

### Option A — Keep only the fallback migration and document that the directory must never change

Pros:
- No new state.

Cons:
- Silent data loss from the association's point of view: the archive original is unreachable and nothing explains why.
- A hosting move, which is exactly when the path changes, is the normal case.

### Option B — Store the absolute directory in the database next to every file name

Pros:
- Every row says where its file is.

Cons:
- Puts deployment paths in the database, so a restore on another host rewrites rows.
- A schema change and a migration for information that belongs to the installation, not to the association's records.

### Option C — Record the roots the installation uses, move what can be moved, and read from the earlier roots

Pros:
- The database keeps names only.
- Files move when they can, and a root that still holds files stays readable and recorded.
- An unreachable root is kept, not forgotten.

Cons:
- Two options to keep consistent.
- Reads can look in more than one directory.

## Decision

Use option C.

- `assoc_private_storage_root` holds the active root: the resolved directory new files are written to.
- `assoc_private_storage_earlier_roots` holds the roots that may still hold files.
- When the active root differs from the recorded one, every recorded root and the conventional fallback are emptied into the active root. A file is moved with `rename`, or copied and then removed only when the copy is byte for byte identical. A name that already exists in the active root with different bytes is never overwritten.
- A root that still holds private files after the move, and a recorded root whose directory is unreachable, stay in `assoc_private_storage_earlier_roots`. An unreachable root is an unknown state, not an empty one.
- Reads and deletes look in the active root first and then in the earlier roots. Writes only ever go to the active root.
- When the recorded root equals the active root and no earlier roots are recorded, no directory is scanned.
- Schema stays at 16. This is installation state, not association data.

## Consequences

Positive:
- Changing the private directory, in either direction, keeps every stored document, minutes PDF, and signed copy readable.
- A failed or partial move is visible in the recorded state instead of being silent.
- No database rows are rewritten when the host changes.

Negative/tradeoffs:
- A root that cannot be emptied is read on every request until it is empty, so a stuck file keeps the extra directory scan.
- Moving an archive original is still a filesystem move. The plugin verifies the copy before removing the source, but a host that fails mid-move leaves the file in the old root and says so.
- The options are per site. A multisite install records one root per site, which matches the per-site upload directory.

## Revisit triggers

Private files move to an object store or another non-filesystem backend, or the owner decides that the storage root must be immutable after the first write.
