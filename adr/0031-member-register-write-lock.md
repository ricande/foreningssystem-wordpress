# ADR-0031: One advisory lock serializes member register writes

**Status:** Proposed (implemented, waiting for owner review)  
**Date:** 2026-10-21

## Context

Membership rules are checked by reading the register and then writing to it. `MembershipLedger::add` rejects a period that overlaps another period of the same membership. `ParticipantAdmission::assertNoOverlap` rejects coverage that overlaps the same person's coverage in another membership. `openMembership` and the imports reject a membership number that is already used. `endMembership`, `endParticipation` and `markDeceased` shorten board assignments so they stay covered by membership.

Each check ran inside a plain `START TRANSACTION`. Under REPEATABLE READ, InnoDB reads without locks, so two requests can read the same state, both pass, and both write. In the lab, 8 parallel `PeopleService::addPeriod()` calls on one ended membership all succeeded and left 8 overlapping active periods that started on the same day. A double-click on Save, or two officers at the same time, is enough. Historical membership data is a LOCKED requirement, so the register must not depend on luck here.

The signed-copy upload already had the same problem and solved it with a MySQL advisory lock and a `SignedCopyBusy` state (hardening batch 2).

## Options considered

### Option A — `SELECT … FOR UPDATE` on parent rows inside the transaction

Lock the `assoc_membership` row, and the `assoc_person` rows whose coverage is checked, before reading.

Pros:
- Locks end with the transaction. Nothing to release by hand.
- Row granularity. Unrelated memberships never wait.

Cons:
- It works only inside a transaction, on InnoDB tables, and on rows that exist. A new membership number has no row to lock, so the number check still races unless a unique index is added (a migration).
- The rows to lock depend on data read before they are locked. A person's other memberships, or a membership's participants, can change between that read and the lock.
- Several row locks taken in data order can deadlock. InnoDB then rolls back one request in the middle of its work with a deadlock error, which the screens would have to recognize.
- The wait is `innodb_lock_wait_timeout` (50 seconds by default). Shortening it per statement uses `NOWAIT`/`WAIT n` syntax that MySQL and MariaDB spell differently.
- A second locking pattern next to the advisory lock the signed copies already use.

### Option B — Advisory locks per membership and per person

`GET_LOCK` on `membership:<id>` and `person:<id>`, acquired in a fixed order.

Pros:
- Same mechanism as signed copies. Only writes to the same membership or person wait.

Cons:
- The same read-then-lock problem as Option A: which people and memberships a write touches is only known after reading. `markDeceased` needs every membership of the person, and an import row needs a lock for a membership number and an email that have no id yet.
- Fixed lock order across memberships and people, retries when the set changed, and many names per import row. Each is a place to get wrong.
- Board assignments, membership numbers and emails would each need their own lock names.

### Option C — One advisory lock for the whole member register

`GET_LOCK('assoc_register_<site>', 10)` around every member register write: the checks, the transaction, and the commit.

Pros:
- Every rule that reads the register is covered at once, including the ones that cross memberships and people, number uniqueness, and board coverage. No lock order, no deadlocks, no read-then-lock gap.
- Same mechanism, wait and busy handling as signed copies.
- The lock is taken before `START TRANSACTION` and released after `COMMIT`, so the checks always read every write that finished before them.
- The server releases the lock if the PHP process dies.

Cons:
- Unrelated writes wait for each other. A write holds the lock for milliseconds. An association has a few officers, so a wait is rare and short.
- A file import holds the lock once per row, not for the whole file, so officers can save between rows. A row that waits more than 10 seconds is reported and not imported.
- Advisory locks belong to the MySQL server, not to one database, so the name carries a hash of the database name and table prefix. Two WordPress installations on one server do not block each other.

## Decision

Option C. `MemberRegisterLock::hold()` is an application interface. `WordpressMemberRegisterLock` implements it with `GET_LOCK` on the WordPress database connection, waits up to 10 seconds, and throws `MemberRegisterBusy` when it gets no lock. Nothing is saved in that case, and the screens say the register is busy and to try again.

These writes run inside the lock, with every read their checks depend on:

- `PeopleService`: `register`, `openForExistingPerson`, `addPersonToMembership`, `addParticipant`, `endParticipation`, `addPeriod`, `endMembership`, `markDeceased`, `updateContact`.
- `CompanyMemberships::register`.
- `MemberExchange::import`, both file versions, one row per hold.
- `MemberRegisterImport::commit`, one row per hold. The membership number is checked again inside the hold.

`rememberPerson` adds a person with no membership and checks no register rule, so it does not take the lock.

### Contact edits

`updateContact` was last-write-wins. Two officers editing the same person both saw "saved", and the first edit disappeared without a trace.

The edit form now carries `contact_version`, a SHA-256 of the first name, last name, email and birth date it was opened with (`Person::contactVersion()`). `updateContact` compares it with the stored person inside the register lock and throws `ContactChanged` when they differ. The screen keeps the stored details and asks the officer to check them and save again.

A version column was considered. It would need a migration and would count changes the form does not show. A hash of the shown values needs no schema change and only refuses the edit when a value the officer saw has changed. Schema stays 17.

## Consequences

Positive:
- The overlap, number and coverage rules hold when requests arrive at the same time. `tests/lab-membership-race.sh` starts parallel PHP workers on one start time and asserts that exactly one period is created.
- One mechanism for concurrent writes in the plugin, with the same busy state.
- A contact edit cannot silently undo another officer's edit.

Negative/tradeoffs:
- Every member register write waits for every other one. If writes ever take long or there are many officers, this is the first thing to revisit.
- The plugin depends on `GET_LOCK`. MySQL and MariaDB have it. A database that only emulates MySQL may not.
- Writes outside the member register that read membership coverage, such as board assignments in `BoardService`, do not take this lock yet.
- A contact form opened before this change has no `contact_version` and is refused once. Reloading the page fixes it.

## Revisit triggers

- Write traffic where the single lock causes visible waits.
- Hosting where `GET_LOCK` is unavailable or unreliable, such as a proxy that moves one PHP request across database connections.
- A unique index on the membership number, or another schema change that lets the database enforce a rule by itself.
