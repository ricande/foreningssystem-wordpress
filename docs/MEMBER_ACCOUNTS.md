# Member accounts

A Person is not a WordPress user. `assoc_person.wp_user_id` is an optional login identity. Schema 16 already stores that link. This version does not add a provisioning-status column, and it does not implement Mina sidor.

## Who receives an account

Automatic provisioning is for a Person who exists, is not deceased, has a usable email address, is an active individual member on the relevant date, is not known to be under 18, has no WordPress link yet, and has no unresolved email collision.

Active individual membership uses `MemberCoverage::isActiveMember`. A company contact does not count. A guardian-only Person does not receive an account merely by being a guardian. Adult participants on a family membership can each receive an account when they have their own usable email address.

Age comes only from the recorded birth date through `Domain\Membership\Age`. A known age under 18 is not provisioned automatically. An age of exactly 18 is eligible when the other rules pass. A missing birth date is not treated as proof of minority, so the Person may be provisioned. That missing-birth-date rule is current implementation behavior, not a legal conclusion about the Person's age. The plugin does not infer age from a personal identity number and does not read the protected identity store to decide eligibility.

A membership that starts in the future does not receive an account before coverage begins. A daily WordPress cron hook, `assoc_provision_member_accounts`, reconciles eligible People, including someone who has turned 18 and someone whose coverage has started. The same service runs immediately after the member operations that can make a Person newly eligible. Repeated runs do not create a second account or send a second new-account message for a Person who is already linked.

Guardian approval of an account for a minor is future member-portal design. It is not implemented here and it is not a locked consent model.

## WordPress owns the login

A new member account is a WordPress `subscriber`. The plugin asks WordPress for a random password, does not store it, display it, log it, or put it in a message, and sends WordPress's normal new-user notification only after `wp_user_id` has been saved. If that save fails, only the user just created is removed. An existing WordPress user is never deleted by unlinking or by a failed link.

An explicit link can attach an existing WordPress user, including an officer, without copying the Person's email and without changing that user's roles. Unlinking clears only the Person link. Ending a membership leaves both the Person and the WordPress user in place. Member-only document access still requires the logged-in user, the explicit Person link, and current active individual coverage. The same email address alone never grants that access.

A WordPress user can be deleted in WordPress while the Person record still stores that user id. The plugin treats that as a broken link. It does not clear the link or create a replacement account in the background, because the plugin cannot tell an intentional deletion from a mistake or a partial restore. An officer with member-edit permission can explicitly clear the stale reference. After that, ordinary provisioning may create a new subscriber account, or the officer can link a different existing WordPress user. This is an identity-safety rule for the account link, not a privacy or legal requirement.

Two unlinked People who share an email are both left unlinked. A WordPress user who already has that email is not linked automatically and a second user is not created. Changing a linked Person's contact email does not change the WordPress account email.

Public WordPress registration is left unchanged. These accounts are created from membership data.
