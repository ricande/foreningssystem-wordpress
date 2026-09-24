# Test strategy

## Objective

Critical association workflows must be provable, not just manually demoed.

## Test layers

### Pure domain/unit tests
Test logic without requiring full WordPress where practical:
- membership lifecycle
- board assignment validity
- meeting state machine
- minutes revision rules
- decision/action validation

### WordPress integration tests
Test:
- capabilities
- hooks
- database repositories
- migrations
- REST permission callbacks
- privacy exporter/eraser hooks
- Media Library integration

### Browser/E2E
Critical flows:
- create/edit member
- board replacement/history
- create meeting from template
- take notes/add decisions
- finalize minutes
- print/PDF action availability
- upload signed copy
- public block rendering
- unauthorized user denial

### Security regression tests
- CSRF/nonces
- privilege escalation
- IDOR/object authorization
- SQL injection regression around custom queries
- stored/reflected XSS escaping
- REST access leakage
- private document direct access model

## Meeting invariants to test

- invalid state transition fails
- unauthorized finalization fails
- finalized revision cannot be silently edited
- correction creates a new explicit revision
- signed document links to one specific revision
- later board/member changes do not retroactively alter finalized historical minutes

## Migration tests

Every schema change:
- installs from clean DB
- upgrades from supported previous schema
- is idempotent where intended
- preserves existing rows
- handles partial failure safely
