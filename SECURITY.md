# Security Policy

Thank you for helping keep CVolve and its users safe. 🔒

## Supported versions

CVolve is maintained by a single person, so security fixes are made only for the **latest version on the `main` branch**. Please update to the latest `main` before reporting.

| Version | Supported |
|---|---|
| Latest `main` | ✅ |
| Older commits and forks | ❌ |

## Reporting a vulnerability

**Please do not open a public issue, discussion or pull request for security problems.**

Report privately in one of these ways:

1. **Preferred:** GitHub's [private vulnerability reporting](https://github.com/future-site-ai/cvolve/security/advisories/new) (the **Security** tab → **Report a vulnerability**).
2. Email **contact@futuresite.it** with the subject `[SECURITY] CVolve`.

Please include:

- A description of the problem and its impact
- Steps to reproduce, or a proof of concept
- The affected version or commit, and your setup (Docker or local)
- Any ideas you have for a fix

## What to expect

CVolve is maintained in spare time by one person, so please be patient:

- **Acknowledgement:** within **7 days**.
- **First assessment:** within **14 days**, to confirm whether it is a vulnerability and how serious it is.
- **Fix:** as soon as reasonably possible, depending on severity and complexity. Critical issues come first.

I will keep you updated, and once a fix is released I will publish a security advisory. **You will be credited** in it, unless you prefer to stay anonymous.

Please give a reasonable time to fix the issue before any public disclosure. If I do not reply within 14 days, feel free to send a reminder.

## Scope

**In scope:** the code in this repository, including:

- Authentication, sessions and password reset
- Personal API tokens, the REST API (`/api/v1`) and the MCP server (`/mcp`)
- Access control between users (one user reading or changing another user's data)
- Public share links
- The template sandbox in `packages/template-kit` (escaping it, or running code or loading external resources from a template)
- File imports (PDF, DOCX, Markdown, template packages)
- AI paths that change a CV without the user's approval

**Out of scope:**

- Problems that need an attacker who already has admin access or access to the server
- The default demo accounts and example secrets (they are documented, and must be changed before going live)
- Self-hosted instances that are misconfigured or not updated
- Vulnerabilities in third-party dependencies with no demonstrated impact on CVolve (please report them upstream)
- Behaviour of external AI providers, and AI answers that are wrong but cause no security impact
- Denial of service through very high traffic, automated scanner output without a proof of concept, and missing security headers alone

## Running CVolve safely

If you self-host CVolve:

- Change the default admin and demo accounts (`CVOLVE_ADMIN_*`, `CVOLVE_SEED_DEMO=0`).
- Set a long, random `APP_SECRET`.
- Serve it over **HTTPS** only.
- Keep it updated to the latest `main`.
- Never commit `.env.local` or `.env.docker` with real keys.

Thank you for reporting responsibly! 💚
