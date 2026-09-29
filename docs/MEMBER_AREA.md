# Mina sidor

Status: **not a finished feature**. The owner has said Mina sidor is not built. This file records code that is already in the repository. It does not accept a member-portal design, and it does not move the deferred member portal in `docs/15_RELEASE_ROADMAP.md`.

`docs/MEMBER_ACCOUNTS.md` describes WordPress account provisioning. A linked account is not Mina sidor.

## What is not in the product

The plugin does not create a member page and does not register its own front-end route. There is no finished screen named Mina sidor. Profile editing, guardian access to another person's record, and member self-service requests are not part of this code. Guardian approval of an account for a minor remains future member-portal design and is OPEN in `DECISIONS.md`.

## Code that exists

These types are building blocks. They are not a finished Mina sidor.

`Foreningssystem\Application\MemberArea\MemberArea::open` takes a WordPress user id and an association date.

- A user id below 1, or a user the account port does not report as existing, returns state `logged_out`.
- No Person with that `wp_user_id` returns `unlinked`. Email, username, name, membership number, query parameters, and administrator capability are not used to choose a Person.
- A Person with status deceased returns `unavailable`.
- Otherwise the snapshot is `linked`. It carries the person's name, contact email, birth date when one is stored, whether a personal identity record exists, the WordPress account email, whether those two emails differ, whether `MemberCoverage::isActiveMember` is true, and coverage rows from `MemberCoverage::effectiveMemberCoverages`. The identity number itself is not on the snapshot.

`plugin/src/Infrastructure/WordPress/MemberAreaBlock.php` registers the dynamic block `foreningsplugin/member-area` on `init`, with the title "Member area". `Plugin::register` adds that registration. `render` ignores block attributes and content. It calls `PersonalizedOutput::doNotCache()` and uses `get_current_user_id()` as the only viewer. When the snapshot is linked and the person is an active member, it asks `WordpressDocuments::archive()->memberList()` for document titles and download URLs. `present` turns a snapshot into HTML: a login link when logged out, a short unlinked message, an unavailable message, or sections titled My details, My membership, My documents, My privacy, and My account. One privacy sentence in that HTML says "This is a summary from Mina sidor." That string is in the block template. It does not make the feature finished.

`plugin/tests/MemberAreaTest.php` calls the read model and `present` without WordPress. `plugin/tests/lab-member-area.php` is a lab script. It can create a page with the slug `mina-sidor-lab` and the block. `scripts/test-lab.sh` runs that script. The plugin does not create that page on activation.
