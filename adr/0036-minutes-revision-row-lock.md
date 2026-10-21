# ADR-0036: A minutes revision is checked and written under one row lock

**Status:** Accepted. The project owner approved it on 2026-10-21.  
**Date:** 2026-10-21

## Context

`WpdbMinutesRepository::saveRevision` protects a finalized revision with `FinalizedRevisionChange::assertAllowed`. The check read the stored row with a plain `SELECT` and then ran the `UPDATE`. A plain read inside `START TRANSACTION` takes no lock, so another request could finalize the revision between the check and the write.

The security review on 2026-10-21 reproduced two cases with a second database connection (`plugin/tests/lab-minutes-race.php`):

1. **Un-finalize.** The secretary saves text while the chair locks the revision. The save had already checked the revision as "under adjustment", and its `UPDATE` wrote `state = under_adjustment` and the new text over the locked revision. MariaDB 11 in the lab refused that write only because its snapshot isolation is on by default ("Record has changed since last read"). MySQL and older MariaDB versions accept it. A locked, maybe published, record would silently become editable again, which breaks product principle 11.
2. **Stale lock.** The chair's request reads the revision before the secretary's save commits, and then locks the text it read. The revision was locked with text that was no longer stored, and the secretary's last save was lost without a message.

## Decision

- `saveRevision` reads the stored row with `SELECT … FOR UPDATE`. Every caller runs inside the transaction wrapper, so the lock lasts until the write is committed. The check and the write see the same row, and a request that wants the same row waits for it.
- `FinalizedRevisionChange::assertAllowed` refuses a change that finalizes a revision with a body or payload different from the stored one. It throws `MeetingRuleException`, so the screen shows the ordinary "minutes blocked" notice and the chair can review again.
- The submit, send back, regenerate and finalize handlers also catch `InvalidArgumentException`, which the finalized-revision guard throws. Before, that guard turned a lost race into an error page.

## Options considered

- **Row lock (chosen).** One existing row, so the concerns ADR-0031 lists for row locks (rows that do not exist yet, lock order, deadlocks) do not apply. Unrelated meetings never wait.
- **Advisory lock per revision,** as signed copies do. It works, but it is a lock to release by hand around every writer, for a problem a single-row lock already solves inside the transaction.
- **Compare-and-set `UPDATE … WHERE state = <read state>`.** Catches the un-finalize case but not the stale lock, because the state does not change when only the text does.

## Consequences

- Two saves of the same revision now run one after the other. The second waits at most `innodb_lock_wait_timeout`.
- A chair who locks while the secretary saves gets "blocked" and locks again after reviewing the latest text. Nothing is lost.
