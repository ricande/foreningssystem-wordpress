# Mina sidor v1

Mina sidor is a read-only view of the logged-in person's own relationship with the association. It is a dynamic Gutenberg block, `foreningsplugin/member-area`, titled Member area. The association places that block on an ordinary WordPress page, such as `/mina-sidor/`. The plugin does not create that page and does not register its own front-end route.

WordPress owns the page, menu, theme, login, logout, and password. The block does not contain a login form, a password field, or a way to edit the Person.

## Identity

The block asks WordPress who is logged in and passes that user id to the member-area read model. A snapshot exists only when that WordPress user currently exists and exactly one Person stores the same `wp_user_id`. Email, username, name, membership number, query parameters, and administrator capability do not select a Person.

A logged-out visitor sees a login link back to the current page. A live account with no Person link sees a short unlinked message and no suggested matches. A linked Person marked deceased does not receive the normal portal. The account is left in place for an officer to handle.

## What the member sees

The linked view shows the person's own name, contact email, birth date when one is recorded, and the WordPress account email. Those two email addresses stay separate. A difference is noted and neither address is changed.

Membership dates come from `MemberCoverage::effectiveMemberCoverages`: the overlap of a membership period and this person's own participation. A family member therefore sees the date they joined, not the date the family membership began, and does not see the other participants. Current status comes from `MemberCoverage::isActiveMember`. Ended and future coverage remain visible as history. A company contact relationship is not shown as an individual membership.

Member documents use the existing member-document check: a live WordPress user, the explicit Person link, and active individual coverage. A former member can see their own history and cannot open member-only documents.

The privacy section says whether a birth date and a personal identity number are recorded. It does not show the number, a masked number, or the protected storage. It is a summary of this screen, not a statutory disclosure. There is no export or erasure request on the page.

## Not in this version

Mina sidor does not edit the Person, change either email address, open another person's record, or act for a child. Guardian relationships do not grant access to someone else's data. That delegation is future work.
