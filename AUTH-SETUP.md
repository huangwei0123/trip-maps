# Email authentication setup

This branch (`feature/email-auth`) replaces the username/recovery-key login with
email + password, adds email confirmation, and moves password reset to emailed
single-use links.

## What changed

| File | Purpose |
|---|---|
| `config.sample.php` | Template for DB + SMTP settings. Copy it, never commit the real one. |
| `lib/settings.php` | Finds and loads the config file. |
| `lib/db.php` | PDO connection (MySQL, `utf8mb4`, exceptions). |
| `lib/bootstrap.php` | Error handling, UTC time, JSON helpers, hardened session. |
| `lib/auth.php` | Identity, CSRF, rate limiting, tokens, folder slugs. |
| `lib/password.php` | Policy, HIBP breach check, Argon2id hashing. |
| `lib/smtp.php` | Dependency-free SMTP client (STARTTLS + AUTH). |
| `lib/mailer.php` | Verification and reset email templates. |
| `lib/page.php` | HTML shell for `verify.php` / `reset.php`. |
| `auth.php` | JSON API: status, register, login, logout, forgot, change_password, resend_verification. |
| `verify.php` | Confirmation landing page. |
| `reset.php` | Password reset form (works without JavaScript). |
| `schema.sql` | `users`, `auth_tokens`, `auth_throttle`. |
| `save_trip.php`, `my_trips.php` | Now use the session's stored `folder_slug`; saving requires a confirmed address. |

## Requirements

- PHP **8.0+** with `pdo_mysql` and `openssl`. `curl` is used if present (better
  CA handling); `mbstring` is optional. Stripe billing also requires `curl`.
- MySQL 5.7+ / 8.0.
- An SMTP relay account.

## 1. Local development

```powershell
# PHP and MySQL are not installed on this machine yet.
winget install --id PHP.PHP.8.4 --exact
winget install MariaDB.Server
```

Then:

1. Create a database and import the schema:
   ```bash
   mysql -u root -e "CREATE DATABASE photoonmaps CHARACTER SET utf8mb4"
   mysql -u root photoonmaps < schema.sql
   ```
2. `Copy-Item config.sample.php config.php` and fill in the DB credentials.
   Set `mail.transport` to `mail` locally, or point it at a real SMTP relay.
3. Serve the app (PHP's built-in server handles the PHP endpoints):
   ```powershell
   php -S localhost:8000
   ```
4. Open <http://localhost:8000>.

For local email testing, use a local SMTP catcher such as Mailpit, or point the
config at a real SMTP relay. The app intentionally never logs raw verification
or reset tokens.

## 2. Production (HostGator / cPanel)

1. **Database** — cPanel → *MySQL Databases*: create a database and a user, grant
   all privileges. Note the names cPanel prefixes (`account_dbname`).
2. **Schema** — phpMyAdmin → select the DB → *Import* → `schema.sql`.
3. **Config outside the web root** — upload the config as
   `/home/USER/photoonmaps-config.php` (one level above `public_html`). It is
   never served over HTTP. Set:
   ```php
   'app' => ['base_url' => 'https://www.photoonmaps.com', 'debug' => false],
   ```
4. **Files** — build the archive and upload it:
   ```bash
   bash gen-zip.sh
   ```
   The script excludes `config.php`, `photoonmaps-config.php`, `users/` and
   `trip/`. Extract into `public_html`.
5. **PHP version** — cPanel → *MultiPHP Manager*: PHP 8.0 or newer for the domain.
   Confirm `pdo_mysql` and `openssl` are enabled in *Select PHP Version*.
6. **Permissions** — `trip/` must be writable by PHP (usually `0755` is enough
   on HostGator; if uploads fail, try `0755` on the parent and check the owner).
7. **Mail DNS** — for Brevo (or your provider), add the SPF and DKIM records they
   give you to the `photoonmaps.com` DNS zone. Without DKIM, reset emails will
   often land in spam. Verify with a test send to a Gmail address.
8. **Delete the old accounts directory** — this branch does not read
   `users/*.json`. Once you are happy, remove it from the server.

## 3. Test checklist

Run these against the deployed site, in order:

- [ ] Register with a real address → "check your inbox" appears.
- [ ] The email arrives; the link opens `verify.php` and says *Email confirmed*.
- [ ] Log in → the badge shows your address, the banner disappears.
- [ ] Save a trip → it lands in `/trip/<folder>-<hash>/`.
- [ ] Log out, then *Forgot password* → the email arrives.
- [ ] The reset link works once; reusing it says the link is invalid.
- [ ] After resetting, a session opened before the reset is logged out.
- [ ] Register the same address twice → no hint about whether it exists.
- [ ] Try a breached password (`password123`) → rejected.
- [ ] Try a password containing your email → rejected.
- [ ] Try 6 wrong logins → rate limited (HTTP 429).

## 4. Design notes

**No "two users cannot share a password".** Detecting that requires storing a
deterministic fingerprint of every password, which turns one file leak into a
bulk-crackable index of the whole user base, and it lets anyone probe whether a
given password is in use. Instead we reject breached/common passwords
(Have I Been Pwned k-anonymity + a local list), which stops the genuinely
dangerous choices without ever comparing users to each other.

**Duplicate registration** is prevented by the `UNIQUE KEY uq_email` index — an
atomic database constraint, not a `file_exists()` check. Responses are identical
whether or not an address exists, so the endpoint cannot enumerate accounts.

**Email confirmation** is required before saving trips, so throwaway accounts
cannot use the site as free storage. Login itself works before confirmation.

**Password hashing** uses Argon2id when available (32 MB, 3 passes); the bcrypt
fallback pre-hashes with SHA-256 so passwords longer than bcrypt's 72-byte limit
are not silently truncated.

**Sessions** are invalidated everywhere on password change via
`users.session_version`, which is compared on every request.

**Rate limits** (per IP and per account, stored in `auth_throttle`):
registration 5/hour, login 20/15 min per IP and 5/15 min per account, password
reset 10/hour per IP and 3/hour per address.

**Folder names** are `local-part-hash6`, e.g. `huangwei5934-a0fdcd`. The hash
suffix means `john.smith@x.com` and `johnsmith@x.com` can never collide, and
because the slug is stored once, existing share links keep working.

## 5. Rollback

The branch is isolated. To abandon it:

```bash
git switch feature/reset-password
```

No production data is migrated by this branch, so rollback only means restoring
the previous files.
