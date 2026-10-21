# ADR-0034: Login brute-force protection is left to the site, and documented

**Status:** Proposed (documentation only, waiting for owner review)  
**Date:** 2026-10-21

## Context

The plugin creates WordPress subscriber accounts for members (ADR-0033, `docs/MEMBER_ACCOUNTS.md`), so a site that uses it has more accounts than before. Officers who hold `view_members`, `edit_members` or `export_members` can read the member register, and their accounts are the valuable ones.

The plugin has no login code. WordPress core authenticates through `wp-login.php`, XML-RPC (`xmlrpc.php`) and application passwords over REST. A security review of the member register found in the local lab (WordPress 7.1.2) that:

- `wp-login.php` accepted 60 wrong passwords for one account in 6 seconds. Core has no lockout or delay.
- XML-RPC `system.multicall` accepted 50 login calls in one request. Core marks the request as failed after the first wrong password and does not check the passwords in the remaining calls (`wp_xmlrpc_server::login`, `auth_failed`). So one request is one real guess, but XML-RPC is still a second unthrottled login endpoint, and it is easy to forget when a server only limits `wp-login.php`.

Login limiting is a site-wide WordPress concern. It applies to every account, not only to members.

## Options considered

### Option A — Build login limiting into the plugin

Count failures per account and per IP, lock out, and expose settings.

Pros: works without anything else installed.  
Cons:
- Rebuilds a well-served system that is unrelated to association data. AGENTS.md: prefer integration over rebuilding unrelated systems.
- Storing IP addresses and failure counts is new personal data, with retention, export and erasure obligations.
- The client IP behind a reverse proxy or CDN depends on trusted proxy headers. Getting that wrong lets an attacker lock out officers, or bypass the limit.
- Two limiters on one site (the plugin and a security plugin the site already has) conflict and confuse administrators.

### Option B — An opt-in setting that turns off XML-RPC authentication

One switch that adds `add_filter( 'xmlrpc_enabled', '__return_false' )`.

Pros: one line of code, uses core's own filter, off by default.  
Cons:
- `wp-login.php` stays unthrottled, so the switch can give a false sense of protection.
- Disabling XML-RPC is a site policy. It can break the WordPress mobile app, Jetpack and older publishing tools, which an association-management plugin cannot know about.
- A security switch on the Association settings page makes the plugin look responsible for site hardening it does not provide, and overlaps with security plugins that already offer the same switch.
- An administrator who finds the switch could just as easily install a login-limiting plugin, which covers both endpoints.

### Option C — No code. Document what the site should do

Pros:
- Keeps the plugin's scope to association data. The protection comes from tools built for it, at the layer that sees every request.
- No new personal data and no proxy-header logic in the plugin.
- The plugin still does what is in its own scope: random member logins (ADR-0033), no password created, shown or sent by the plugin, subscriber role only, and capabilities instead of broad roles.

Cons:
- A site that follows no advice has no limiting. That was already true before the plugin.

## Decision

Option C. The plugin ships no brute-force code and no XML-RPC setting. `plugin/readme.txt`, `README.md` and `docs/MEMBER_ACCOUNTS.md` tell administrators to:

- limit login attempts with a login-limiting or security plugin, or with rate limiting in the web server or proxy, for both `wp-login.php` and `xmlrpc.php`;
- turn off XML-RPC authentication if nothing on the site uses it, with the security plugin or a three-line must-use plugin shown in `docs/MEMBER_ACCOUNTS.md`;
- require strong passwords, and two-factor authentication through a plugin, for every account that holds `view_members`, `edit_members`, `export_members`, `erase_member_data` or `manage_association`.

## Consequences

Positive:
- No overlap with security plugins, and no new personal data.
- The advice covers every account on the site, not only member accounts.

Negative/tradeoffs:
- Protection depends on the administrator reading the documentation.

## Revisit triggers

- WordPress core gains login throttling. The documentation then shortens.
- The member portal adds its own sign-in form. That form must not become a third unthrottled endpoint.
- Owners report that associations commonly run without any login limiting even after reading the documentation. A visible admin notice could then be considered, still without building a limiter.
