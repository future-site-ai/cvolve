# Contributing to CVolve

Thank you for your interest in CVolve! 💚 Contributions of every size are welcome: bug reports, ideas, documentation, translations, templates and code.

You do not need to ask for permission to help. If something here is unclear, open an issue and we will improve this guide.

## Ways to help

- 🐛 **Report a bug**: open an [issue](https://github.com/future-site-ai/cvolve/issues) with the steps to reproduce it, what you expected, and what happened instead.
- 💡 **Suggest a feature**: open an issue that describes the problem you want to solve, not only the solution.
- 📝 **Improve the docs**: fix typos, clarify the README, or add examples.
- 🌍 **Translate**: the UI strings live in `translations/`.
- 🎨 **Create a template**: build one with the [CVolve Template Maker](https://github.com/future-site-ai/cvolve_template_maker) and propose it as a preset.
- 🧑‍💻 **Write code**: pick an issue labelled `good first issue` or `help wanted`.

## Before you start coding

1. **Look for an existing issue** first. If there is none, open one so we can agree on the approach before you spend time on it. Small fixes (typos, obvious bugs) can go straight to a pull request.
2. **Comment on the issue** to say you are working on it, so two people do not do the same work.

## Development setup

The full setup is in the [README](README.md#development). In short:

```bash
git clone https://github.com/<your-username>/cvolve.git && cd cvolve
composer install
make local-deps        # PostgreSQL, Gotenberg and Mailpit in Docker
# create .env.local as shown in the README, then:
php bin/console doctrine:migrations:migrate -n
php bin/console app:seed-demo
symfony serve          # or: php -S 127.0.0.1:8000 -t public
```

Or run everything in Docker with live code: `make dev`.

## Project rules

These keep CVolve consistent. Please follow them in your changes:

- **The template engine lives in the shared kit.** Changes to the sandbox, package reader/writer, renderer, sample CVs or importers go in `packages/template-kit` (namespace `Cvolve\TemplateKit`), never in the app. The [Template Maker](https://github.com/future-site-ai/cvolve_template_maker) uses the same kit, so templates must behave the same in both apps.
- **The AI only proposes, the user approves.** Every AI path that changes a CV goes through `CvToolbox` → `ProposalManager`. The AI never writes directly and never changes styling (CSS).
- **Templates own the design.** Content and styling stay separate.
- **Never commit secrets.** Keep API keys and passwords in `.env.local` or `.env.docker`, which are not tracked.

## Code style

- PHP 8.3, [Symfony coding standards](https://symfony.com/doc/current/contributing/code/standards.html), 4-space indentation (see `.editorconfig`).
- Keep pull requests focused: one fix or feature per PR.
- Add or update tests for the behaviour you change.

## Checks before you open a pull request

The same checks run in GitHub Actions on every pull request:

```bash
make test      # PHPUnit (SQLite, no services needed)
make stan      # PHPStan level 5
php bin/console lint:twig templates
php bin/console lint:yaml config
php bin/console lint:container
```

## Pull request process

1. **Fork** the repository and create a branch from `main`, for example `fix/pdf-page-break` or `feat/italian-sample-cv`.
2. Make your changes and commit them with clear messages, for example `Fix page count in the CV checker`.
3. **Push** your branch and open a **pull request** against `main`.
4. In the description, explain **what** you changed and **why**, link the issue (`Closes #123`), and add screenshots for UI changes.
5. Make sure CI is green. A maintainer will review your PR, may ask for changes, and will merge it when it is ready.

Please be patient: this project is maintained in our spare time, and we try to reply within a week.

## Reporting security issues

**Do not open a public issue for security vulnerabilities.** Use GitHub's [private vulnerability reporting](https://github.com/future-site-ai/cvolve/security/advisories/new) or email **contact@futuresite.it**.

## Code of conduct

Be kind and respectful. We want everyone to feel welcome, whatever their experience level. Harassment or abusive behaviour is not tolerated, and maintainers may remove comments or block users who break this rule.

## License

By contributing, you agree that your contributions are licensed under the project's [MIT License](LICENSE).

Thank you for helping make CVolve better! ⭐
