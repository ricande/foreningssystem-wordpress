# WordPress VM lab plan

The project owner may provide Cursor with a dedicated VM running WordPress.

Do not assume the VM layout until it is inspected.

## First VM inventory

Record:
- OS/distribution/version
- CPU/RAM/disk
- web server
- PHP version and extensions
- database engine/version
- WordPress version
- document root
- vhost/domain
- HTTPS state
- WP-CLI availability
- Node/npm availability if block tooling is required
- Composer availability
- filesystem ownership/permissions
- mail capture/dev mail solution
- debug configuration
- current plugins/themes
- whether snapshots exist

Do not print or commit passwords/secrets.

## Desired lab properties

- disposable/recoverable
- snapshot before risky changes
- local/dev mail capture rather than sending real mail
- `WP_DEBUG` appropriate for development
- log access
- WP-CLI
- database backup/restore procedure
- plugin symlink or deploy workflow from repository
- HTTPS if practical
- test accounts with different capabilities

## Suggested environments

At minimum:
- current supported WordPress/PHP baseline
- one minimum-supported WordPress/PHP combination before release

Exact supported versions remain a design decision.

## Development install

Once implementation begins, Cursor should automate repeatable setup where practical:
- dependency install
- build assets
- install/activate plugin
- run unit/integration tests
- run lint/static analysis
- seed safe synthetic demo data

Never use real member personal data for development fixtures.

## Mail

If any email feature is later tested:
- use local mail capture such as Mailpit or equivalent
- do not send to real association members

## PDF testing

When PDF implementation starts:
- verify server requirements on the VM
- test non-ASCII Swedish characters
- test multi-page minutes
- test page breaks around agenda sections and signatures
- test modest memory limits
