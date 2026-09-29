# Testing plan

Status: **proposed**. This is the executable form of `docs/12_TEST_STRATEGY.md`. A feature is not done because a happy-path click worked.

## Layers

| Layer | Where it runs | Covers |
|---|---|---|
| Domain tests | PHPUnit, no WordPress bootstrap | Membership overlap, board date queries, meeting transitions, revision immutability, draft composition |
| Integration tests | PHPUnit with the WordPress test library | Capabilities, repositories, migrations, privacy exporter/eraser, REST permission callbacks |
| Browser checks | The local lab, later an automated browser if the team adds one | Secretary path from agenda to finalized minutes, public block output, denied access |
| Security regressions | Integration tests plus review | Nonces, IDOR, prepared SQL, escaping, private download |

Domain tests are mandatory before the corresponding use case is called complete. Browser checks supplement them.

## Cases that must exist before MVP is called done

- Ending a membership keeps the person and the closed period.
- Two membership periods for one person cannot overlap.
- "Who was treasurer on a given date?" survives a later replacement.
- Applying board changes from an annual meeting does nothing until the officer confirms.
- An invalid meeting transition is rejected.
- A user with `record_meeting` but without `finalize_minutes` cannot finalize.
- A finalized revision body does not change when notes or the live decision row change.
- A correction creates a new revision and sets `superseded_by` on the old one only after the new one is finalized.
- Regenerating a hand-edited draft without confirmation does not replace it.
- A signed document cannot be attached to a draft.
- Replacing a signed document writes an audit event and keeps the revision id.
- The public board block does not render a private email.
- A logged-out request for a board document download is denied.
- The privacy eraser anonymizes contact fields and reports retained minutes text.
- Install on an empty database and upgrade from the previous schema version both leave existing rows intact.
- A failed migration does not advance the stored schema version.

## Migration tests

For each schema change: clean install, upgrade from the previous version, second run is a no-op, and a forced failure before the version option is written leaves the old version in place.

## Lab

The current lab is the Docker environment in `docs/16_LAB_INVENTORY.md`. Use synthetic people only. Do not load a real member list.

PDF tests start only after a library spike. They must include Swedish characters, a multi-page agenda, and a signature block that is not split from its heading in a way that makes the page unusable. Modest memory is part of that spike, not a later surprise.

## Continuous integration that exists now

`.github/workflows/tests.yml` already runs the three jobs described in `docs/12_TEST_STRATEGY.md`, section **Continuous integration**: `phpunit`, `lab`, and `package-install`. The `phpunit` job uses `plugin/tests/bootstrap.php`. It does not boot the WordPress test library. WordPress-backed checks in the current workflow are the `lab` job and the `package-install` job.

The layers table above remains the proposed split. Its integration-test row, "PHPUnit with the WordPress test library", is not a description of the current `phpunit` job.

The sentence that a continuous-integration configuration could wait belonged to the original Phase B sequencing in `docs/15_RELEASE_ROADMAP.md`. It does not describe the repository now.

This plan stays **proposed**. The case list above is the proposed bar for calling MVP done. This note does not accept the plan and does not claim that every case already passes.
