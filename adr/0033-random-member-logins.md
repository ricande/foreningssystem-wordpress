# ADR-0033: New member accounts get a random login name

**Status:** Proposed (implemented, waiting for owner review)  
**Date:** 2026-10-21

## Context

`MemberAccountProvisioning` creates a WordPress `subscriber` for each eligible member (see `docs/MEMBER_ACCOUNTS.md`). The login name was `assoc-member-{personId}`. Person ids are small consecutive integers, so anyone who learned one login, or simply guessed that the plugin is installed, could list every member login: `assoc-member-1`, `assoc-member-2`, and so on. A security review of the member register confirmed in the local lab that WordPress core accepted 60 wrong passwords for one login in 6 seconds without any lockout. A known list of valid logins is the first half of a password-guessing attack.

The Person ↔ `wp_user` link is `assoc_person.wp_user_id`. Nothing in the plugin finds a member's account by its login name. The login is read in two places only:

- `MemberAccountProvisioning` asks whether the login is free before creating the user, so an existing user is never taken over.
- The officer's "link existing account" search (`WordpressMemberAccounts::findUser`) accepts any login or email. It does not depend on the format.

Members sign in with the login or with their email address. WordPress accepts both. The new-user notification links to a password-reset page, which also shows the login.

## Options considered

### Option A — Keep `assoc-member-{personId}`

Pros: readable, no change.  
Cons: every member login is known in advance. That is the finding.

### Option B — A login built from the name or email, like `anna.andersson`

Pros: easy to remember.  
Cons: just as guessable from a member list or the website, and the login is shown to the account holder in places they do not expect (password-reset page, some themes and plugins). It puts personal data into a column that WordPress treats as non-sensitive and cannot change later. Collisions need numbering, which is enumerable again.

### Option C — `assoc-member-` plus a random suffix

16 lowercase hex characters from `random_bytes(8)` (64 bits), for example `assoc-member-3f9a0c1e7b24d658`.

Pros:
- One login says nothing about another, and the Person id is not in it.
- The prefix keeps plugin-created accounts recognizable for officers and for the lab cleanup scripts.
- No personal data in the login.
- 29 characters fit `user_login` (60) and `user_nicename` (50), which WordPress derives from the login.

Cons:
- Not memorable. Members can sign in with their email, so this matters little.
- If a random login is already taken, a new one is drawn. Three attempts are made before provisioning reports `Failed`. With 64 bits that should never happen, and an existing user is never taken over.

### Option D — Random login and rename existing accounts

Pros: old accounts would also stop being guessable.  
Cons: WordPress has no API for changing `user_login`. It would need direct SQL on `wp_users`, would have to update `user_nicename`, would sign out the member and break any login they have saved or written down, and would touch accounts the plugin may not even have created (an officer can link any existing user). It is a one-way change to a table the plugin does not own.

## Decision

Option C for new accounts. `RandomMemberLogins` implements the `MemberLogins` interface, and `MemberAccountProvisioning` takes it as an optional constructor argument so the tests can script collisions.

Existing accounts keep their login. The link was never by login, so nothing needs to migrate for the plugin to keep working. Old `assoc-member-{n}` logins stay predictable. Administrators who care can rename them by hand with a user-management plugin or WP-CLI after telling the member. The plugin does not do this automatically.

## Consequences

Positive:
- New member logins cannot be listed by counting.
- Existing links, unlinking, the broken-link flow, and the officer search behave as before.
- Checked in the local lab (WordPress 7.1.2): a provisioned account got `assoc-member-a4c6e59133057ca5`. `/?author={id}` did not redirect to the login-derived slug, and `/wp-json/wp/v2/users` listed no subscriber, because core only exposes authors with published posts.

Negative/tradeoffs:
- An email address is also a valid login in WordPress, and member emails are often easy to guess. A random login removes the free list of logins. It is not brute-force protection. That is ADR-0033.
- Accounts created before this change keep their predictable login.

## Revisit triggers

- WordPress gains a supported way to rename a login.
- The member portal needs a login the member chooses.
- The plugin starts showing the login somewhere public, such as an author page. It must not.
