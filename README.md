# CVolve (CV + Evolve)

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony 7.4](https://img.shields.io/badge/Symfony-7.4_LTS-000000.svg?logo=symfony)](https://symfony.com/)
[![PostgreSQL 16](https://img.shields.io/badge/PostgreSQL-16-4169E1.svg?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED.svg?logo=docker&logoColor=white)](docker-compose.yml)
[![GitHub stars](https://img.shields.io/github/stars/future-site-ai/cvolve?style=social)](https://github.com/future-site-ai/cvolve)

CVolve is an open-source, self-hostable CV builder by **Future Site Di Haidar Anbar**. You keep your whole career in one **master profile**, create as many **CVs** as you need from it, render them through **templates** to A4 **PDF** and **DOCX**, and let an **AI assistant** draft, tailor and translate the content. The assistant only proposes changes; you approve each one.

- 🗂 **Master profile**: structured forms, or import a Markdown file / an existing CV (PDF, DOCX) parsed by AI, with a review step before anything is saved.
- 📄 **Many CVs per user**: pick which summary, jobs, entries and bullets to show; edit wording per CV without touching the master profile.
- 🎨 **Templates own the design**: content and styling are separate. The chat changes words, never CSS, and never steps outside the template's schema.
- 🤖 **Propose → approve**: every AI change arrives as a before/after diff with **Apply** / **Discard**. A version snapshot is taken before applying.
- 🌍 **Multilingual**: language versions of one CV (EN, IT, FR, DE, ES, PT, NL, AR), with AI translation.
- 🎯 **Job tailoring & checks**: paste a job ad to tailor the CV; the checker reports template limits, page count, date consistency, weak bullets and keyword coverage.
- 🕓 **Version history**: automatic and manual snapshots, side-by-side diff, one-click restore.
- 📦 **Templates in many formats**: native `.cvolve` packages, HTML with Mustache placeholders, Twig HTML, JSON Resume themes, HTML zips, and finished HTML CVs converted by AI. Build templates with the companion **[CVolve Template Maker](https://github.com/future-site-ai/cvolve_template_maker)**.
- 🔌 **REST API + MCP server**: connect Claude Desktop, Cursor or any MCP client; automate with personal API tokens.
- 🔗 **Public share links** that you can switch on and off.

<p align="center">
  <img src="docs/screenshots/editor.png" alt="CVolve CV Editor with Live A4 Preview and AI Assistant" width="100%">
</p>

---

## Quick start (Docker)

```bash
git clone https://github.com/future-site-ai/cvolve.git && cd cvolve
cp .env.example .env.docker      # optional: set APP_SECRET, AI provider, SMTP...
docker compose up -d --build
```

Open **http://localhost:8080**. First start runs the migrations, installs the preset templates and creates:

| Account | Email | Password |
|---|---|---|
| Admin | `admin@cvolve.local` | `admin1234` |
| Demo user (profile + 2 CVs) | `demo@cvolve.local` | `demo1234` |

Change these before exposing the app (see `CVOLVE_ADMIN_*` and `CVOLVE_SEED_DEMO` below). Outgoing email (sign-up confirmation, password reset) is caught by Mailpit at **http://localhost:8025**.

| Service | Purpose |
|---|---|
| `app` | Symfony app (PHP 8.3 + Apache); runs migrations and seeding on start |
| `messenger-worker` | Background jobs: PDF/DOCX exports, AI import parsing, emails |
| `database` | PostgreSQL 16 |
| `gotenberg` | Chromium that renders the HTML templates to PDF (only inline resources may load) |
| `mailer` | Mailpit (development SMTP catcher) |

Useful commands (see `make help`):

```bash
make logs                              # follow app + worker logs
make admin EMAIL=you@example.com       # create or promote an administrator
docker compose exec app php bin/console app:templates:install   # reinstall presets
```

> **Demo data:** `fixtures/demo_profile.json` is a fictional profile ("Alex Morgan") used to show what CVolve does. To give the demo user a photo, put a JPEG at `assets/photos/demo.jpg` (gitignored). Set `CVOLVE_SEED_DEMO=0` for a deployment without the demo user, or replace that file with your own.

## Configuration

Docker reads `docker/defaults.env`, then your overrides in **`.env.docker`** (copy of `.env.example`). When running PHP locally, use `.env.local` instead.

| Variable | Default | Meaning |
|---|---|---|
| `APP_SECRET` | – | **Set a random value in production** (`php -r 'echo bin2hex(random_bytes(16));'`) |
| `DEFAULT_URI` | `http://localhost:8080` | Public URL (links in emails and API answers) |
| `DATABASE_URL`, `POSTGRES_*` | `cvolve`/`cvolve` | Database credentials (change both together) |
| `MAILER_DSN`, `MAILER_FROM` | Mailpit | SMTP for verification and reset emails |
| `REQUIRE_EMAIL_VERIFICATION` | `1` | Users must confirm their email before logging in |
| `GOTENBERG_URL` | `http://gotenberg:3000` | PDF renderer |
| `STORAGE_DIR` | `var/storage` | Exports, photos, template assets (a Docker volume) |
| `CVOLVE_SEED_DEMO` | `1` | On an empty database: `1` = admin + demo user, `0` = admin only |
| `CVOLVE_ADMIN_EMAIL` / `_PASSWORD` | `admin@cvolve.local` / `admin1234` | First administrator |
| `AI_PROVIDER` … | `gemini` | See below |
| `CVOLVE_PORT`, `MAILPIT_PORT` | `8080`, `8025` | Host ports (shell environment) |

### Choosing an AI provider

The assistant is built on [Neuron AI](https://github.com/neuron-core/neuron-ai), configured exactly like Plate Plan:

```dotenv
AI_PROVIDER=anthropic          # gemini | openai | anthropic | ollama | mistral | deepseek | grok | openai_like | none
AI_MODEL=                      # optional; defaults: gemini-2.5-flash, gpt-4o-mini, claude-sonnet-5-5, llama3.1, ...
AI_API_KEY=sk-...              # or GEMINI_API_KEY / OPENAI_API_KEY / ANTHROPIC_API_KEY / MISTRAL_API_KEY ...
AI_BASE_URL=                   # ollama (default http://host.docker.internal:11434/api) or any OpenAI-compatible server
```

**Without a provider, CVolve still works fully**: forms, templates, exports, versions, sharing and CVolve-JSON import. The chat then falls back to a few plain commands (“add professional summary from my master info”, “add experience”, “check my CV”, “export pdf”). Markdown/PDF/DOCX import and the generative tools need a provider.

## Using CVolve

1. **Master profile** (`/profile`): fill in the tabs or **Import…** a Markdown file such as [`md/master_info.md`](md/master_info.md), an old CV, or a CVolve profile JSON. Review what was found, then add it to or replace parts of your profile.
2. **New CV**: choose a template, language, summary, jobs and sections. Content is copied from the profile.
3. **Editor**: sections on the left (edit, reorder, hide entries/bullets, add from profile), a live A4 preview in the middle (red guides show page ends; *PDF preview* shows the exact output), the assistant on the right.
4. **Assistant**: ask in plain language: *“Suggest 3 summaries for a fintech backend role”*, *“Make my 3Tech bullets more quantified”*, paste a job ad to tailor, *“Translate to Italian”*. Each change is a proposal with a diff.
5. **Export** to PDF or DOCX, save named versions, or share a read-only public link.

## Screenshots

### Interactive CV Editor & AI Assistant
The core workspace: structured section tree on the left, live sandboxed A4 preview with real-time template styling in the center, and an AI assistant on the right that proposes changes as diffs with instant **Apply** / **Discard** controls.

![CVolve CV Editor](docs/screenshots/editor.png)

### Multi-CV Dashboard
Manage multiple tailored CV versions for specific positions, industries, or languages—all dynamically sourced from your master profile.

![Multi-CV Dashboard](docs/screenshots/dashboard.png)

### Master Career Profile
A single source of truth for your entire career: contact details, professional summaries, work experience, education, skills, and certifications, with import support for Markdown, PDF, and DOCX resumes.

![Master Career Profile](docs/screenshots/master-profile.png)

### Template Gallery
Browse and switch between native templates separating layout from content. Supports `.cvolve` packages, Mustache HTML, Twig, and JSON Resume themes.

![Template Gallery](docs/screenshots/templates.png)

### Version History & Side-by-Side Diff
Automatic snapshots before every applied AI change, side-by-side unified diff comparison, and one-click rollback.

![Version History & Diff](docs/screenshots/version-diff.png)

## AI tools, REST API and MCP

One tool layer (`src/Service/Tool/CvToolbox.php`) serves the in-app chat, the MCP server and the REST API:

| Read | Propose a change (user applies) | AI-generated proposals | Direct actions |
|---|---|---|---|
| `list_cvs`, `get_cv`, `get_master_profile`, `list_templates`, `get_template_schema`, `list_proposals`, `check_cv` | `add_section` (incl. `from_master`), `update_section`, `remove_section`, `reorder_sections`, `update_header`, `set_professional_summary`, `add_experience_from_master`, `select_items`, `deselect_items`, `switch_template` | `generate_summary_from_master`, `suggest_summaries` (3 proposals), `rewrite_bullets`, `tailor_to_job_description`, `translate_cv` | `create_cv`, `export_cv` |

Change tools validate against the template's `schema.json` (supported sections, `maxLength`, `maxItems`, `maxBullets`) and return errors the AI can correct. Generative tools use the AI provider configured in CVolve.

### Personal API tokens

Create one under **Account → API tokens** (shown once, stored hashed, optional expiry). Send it as `Authorization: Bearer cvk_...`.

### REST API (`/api/v1`)

Interactive docs: **`/api/doc`** (OpenAPI JSON at `/api/doc.json`).

```bash
TOKEN=cvk_...
curl -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/v1/cvs
curl -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -X POST http://localhost:8080/api/v1/tools/set_professional_summary \
     -d '{"cv_id": 1, "text": "Backend engineer with **8+ years**..."}'
curl -H "Authorization: Bearer $TOKEN" -X POST http://localhost:8080/api/v1/proposals/1/apply
```

Endpoints: `GET /me` · `GET|PUT /profile` · `GET|POST /cvs` · `GET|DELETE /cvs/{id}` · `POST /cvs/{id}/exports` · `GET /exports/{id}` · `GET /exports/{id}/download` · `GET /proposals?cv_id=` · `GET /proposals/{id}` · `POST /proposals/{id}/apply|discard` · `GET /templates` · `POST /templates/import` · `GET /templates/{id}/export` · `GET /tools` · `POST /tools/{name}`.

### MCP server (`/mcp`)

A JSON-RPC 2.0 MCP endpoint (streamable HTTP, JSON responses; protocol versions 2025-06-18, 2025-03-26, 2024-11-05) exposing the same tools, acting as the token's owner.

**Claude Desktop** (`claude_desktop_config.json`), through the `mcp-remote` bridge:

```json
{
  "mcpServers": {
    "cvolve": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "http://localhost:8080/mcp", "--header", "Authorization: Bearer cvk_..."]
    }
  }
}
```

**Cursor** (`.cursor/mcp.json`):

```json
{ "mcpServers": { "cvolve": { "url": "http://localhost:8080/mcp", "headers": { "Authorization": "Bearer cvk_..." } } } }
```

Changes made through MCP appear as proposals in the CV editor (or apply them with the REST API).

## Template formats CVolve imports

Every format is converted into a sandboxed `.cvolve` package and validated before installation (Templates → Import, or `POST /api/v1/templates/import`). Missing `manifest.json` and `schema.json` are inferred.

| Format | What to upload | Notes |
|---|---|---|
| **.cvolve** | the native zip (below) | used as is |
| **CVolve HTML** | one `.html`/`.mustache` file with Mustache placeholders | easiest for designers, see below |
| **Twig HTML** | `.html`/`.twig` using the Twig variables below | no manifest needed |
| **JSON Resume theme** | the theme's zip (npm package) with a Handlebars or Mustache template (`resume.hbs`, `resume.template`) + CSS | rendered against the [JSON Resume schema](https://jsonresume.org/schema); the theme's JavaScript is ignored, but common computed fields (`workBool`, `capitalName`, `startDateYear`…) are provided. Themes that render with JSX/Pug/React are not supported |
| **HTML zip** | `index.html` + CSS files (+ `fonts/`) in any HTML flavour above | linked local stylesheets and `<style>` blocks are merged |
| **Finished HTML CV** | a static CV with no placeholders | converted by the configured AI provider; without one, use the Template Maker |

During import, `<script>`, event handlers, remote stylesheets/fonts/images and `@import` are removed (with warnings).

### CVolve HTML (Mustache placeholders)

```html
<style> h1 { color: #0f766e; } </style>
<h1>{{header.name}}</h1>
<p>{{header.headline}} · {{header.email}}</p>
{{#regions.main}}
  <section>
    <h2>{{title}}</h2>
    {{#is_summary}}<p>{{{text}}}</p>{{/is_summary}}
    {{#items}}
      <h3>{{title}}{{name}}{{degree}} <small>{{period}}</small></h3>
      <ul>{{#bullets}}<li>{{{.}}}</li>{{/bullets}}</ul>
    {{/items}}
  </section>
{{/regions.main}}
```

`{{x}}` prints escaped text, `{{{x}}}` prints formatted text (`**bold**`, `*italic*`, never raw HTML), `{{#x}}…{{/x}}` loops over a list or shows the block when `x` is set, `{{^x}}…{{/x}}` shows it when `x` is empty, and `{{.}}` is the current list value. Names are looked up from the innermost block outwards. Without a `schema.json`, regions are detected from `regions.<name>` and a column called `sidebar`/`aside`/`left`/`right` receives skills, languages, certifications and custom sections.

## The `.cvolve` template format

A template is a zip file with the extension `.cvolve`:

```
my-template.cvolve
├── manifest.json        required
├── schema.json          required
├── template.html.twig   required  sandboxed Twig, renders the <body> content
├── style.css            required  print CSS
├── thumbnail.png        optional  gallery image (794×1123 recommended)
├── fonts/*.woff2|woff|ttf|otf     optional bundled fonts
└── README.md, LICENSE.txt         optional
```

**manifest.json**

```json
{
  "format": "cvolve-template",
  "formatVersion": 1,
  "name": "My Template",
  "slug": "my-template",
  "version": "1.0.0",
  "author": "You",
  "licence": "MIT",
  "description": "One sentence for the gallery.",
  "page": "A4",
  "maxPages": 2,
  "fonts": [{"family": "Inter", "file": "fonts/inter-400.woff2", "weight": "400", "style": "normal"}],
  "docx": {"accent": "#0984E3", "font": "Calibri"}
}
```

**schema.json** declares the layout regions and which section types the template supports, with optional limits that both the editor and the AI respect:

```json
{
  "regions": ["sidebar", "main"],
  "sections": {
    "summary":    {"region": "main", "maxLength": 900},
    "experience": {"region": "main", "maxItems": 6, "maxBullets": 8},
    "skills":     {"region": "sidebar"}
  }
}
```

Section types: `summary`, `experience`, `projects`, `education`, `skills`, `languages`, `certifications`, `publications`, `custom`.

**Template variables** (plain arrays only; text is autoescaped):

| Variable | Content |
|---|---|
| `header` | `name`, `headline`, `email`, `phone`, `location`, `website`, `linkedin`, `github`, `nationality`, `dateOfBirth`, `workAuthorization`, `drivingLicence`, `extra`, `photo` (data URI or null) |
| `regions.<name>` | visible sections for that region, in order |
| `sections` | all visible sections, in order |
| section | `id`, `type`, `title`, `region`, `text` (summary), `items` (visible entries; `bullets` is a list of visible strings) |
| `labels` | translated fixed words: `contact`, `personal`, `present`, `nationality`, `born`, `work_authorization`, `driving_licence`, `tech`, `grade` |
| `cv` | `title`, `locale`, `dir` (`ltr`/`rtl`) |
| `by_type.<type>` | the first visible section of a type |
| section extras | `has_items`, `is_<type>` (e.g. `is_experience`); every item also has `period` |
| `resume` | the CV in [JSON Resume](https://jsonresume.org/schema) shape (`resume.basics.name`, `resume.work`…) |

Entry fields: experience `title, organization, location, start, end, description, tech, bullets` · projects `name, role, start, end, url, tech, description, bullets` · education `degree, institution, location, start, end, grade, description` · skills `group, skills` · languages `name, level, note` · certifications `name, issuer, date, url` · publications `title, authors, venue, date, url` · custom `title, subtitle, date, description`.

**Allowed Twig**: tags `if for set apply macro import from with verbatim`; filters `abs batch capitalize column default e escape filter first format join keys last length lower map merge nl2br number_format replace reverse round slice sort split striptags title trim upper url_encode` plus CVolve's `md` (renders `**bold**` / `*italic*` safely), `period` (`start – end`), `pretty_url`, `initials`, `as_list`; functions `range max min cycle`; tests `defined empty null none odd even iterable same as divisible by`. Macros can be called through `_self`. No `raw`, `include`, `source`, object access or method calls.

**Validation on import** rejects: scripts, event handlers, `javascript:` URLs, `<iframe|object|embed|form|link|meta|style|base>` in the template; `@import`, remote `url()` (only `fonts/…` and `data:image/…` are allowed, CSS escapes are decoded first) in the CSS; unknown files (e.g. `.js`); unsafe zip paths (zip-slip); oversized packages; and anything the sandbox refuses during a trial render with sample data. Previews are served with a strict Content-Security-Policy inside a sandboxed iframe, and Gotenberg only loads inline resources.

**Print tips**: design for `@page { size: A4; margin: 0 }` and pad your columns; use `box-decoration-break: clone` so padding repeats on every page, a root `linear-gradient` background for full-height sidebars, and `break-after: avoid` on headings. Look at `packages/template-kit/resources/presets/` for three complete examples. `make pack-templates` writes the presets as `.cvolve` files to start from.

### DOCX export

PDF is a pixel-accurate Chromium rendering of the template. **DOCX is generated separately** with PHPWord from the same content, because Word cannot reproduce arbitrary CSS layouts. It is an editable single-column document using the template's `docx.accent` colour and `docx.font`, with all regions included (main first). Expect the same content and emphasis, not the same visual layout.

## Architecture

```
src/
├── Entity/                User, ApiToken, Template, Cv, CvVariant (language), CvSection (JSONB content),
│   └── Profile/           CvVersion, AiProposal, ChatConversation/Message, ExportJob, ImportJob
│                          MasterProfile + Experience/Project/Bullet, Education, Skill..., CustomSection
├── Service/
│   ├── Cv/                SectionTypes (content shapes), CvBuilder (profile → CV), CvRenderer,
│   │                      ProposalManager (propose/apply/discard + diffs), VersionManager, CvChecker
│   ├── Template/          TemplateInstaller (import any format via the kit, export .cvolve)
│   ├── Tool/CvToolbox     every AI/MCP/API tool, defined once
│   ├── Ai/                ProviderFactory + CallbackTool (from Plate Plan), ChatAgent, AiTextService, Prompts
│   ├── Mcp/McpServer      JSON-RPC 2.0 MCP server
│   ├── Export/            GotenbergClient (PDF), DocxExporter (PHPWord), ExportService
│   └── Import/            TextExtractor (MD/PDF/DOCX), ProfileImporter
├── Controller/            web UI, Admin/, Api/ (REST v1 + MCP)
└── Security/              API token auth, ownership voters, user checker
packages/template-kit/     shared template engine (Composer package, also used by the Template Maker):
                           sandbox, package reader/writer, DocumentRenderer, sample CVs, presets,
                           importers (Mustache HTML, Handlebars/JSON Resume, HTML zip, AI conversion)
fixtures/demo_profile.json demo master profile (CVolve profile JSON)
```

The UI is server-rendered Twig with Symfony UX (Stimulus + Turbo) and AssetMapper, so there is no Node build step. Ownership is enforced by voters on every controller and by the toolbox for API, MCP and chat calls.

## Development

Run PHP on your machine against containerised services:

```bash
composer install
make local-deps                      # PostgreSQL :5432, Gotenberg :3000, Mailpit :8025
cat > .env.local <<'ENV'
DATABASE_URL="postgresql://cvolve:cvolve@127.0.0.1:5432/cvolve?serverVersion=16&charset=utf8"
GOTENBERG_URL=http://127.0.0.1:3000
MAILER_DSN=smtp://127.0.0.1:1025
AI_PROVIDER=none
ENV
php bin/console doctrine:migrations:migrate -n
php bin/console app:seed-demo        # or: php bin/console doctrine:fixtures:load
symfony serve                        # or: php -S 127.0.0.1:8000 -t public
make worker                          # in another terminal, for exports and imports
```

Or run everything in Docker with live code: `make dev`.

Quality checks (also in GitHub Actions):

```bash
make test      # PHPUnit on SQLite: sandbox, package validation, proposals, exports (Gotenberg mocked), API/MCP auth, chat agent
make stan      # PHPStan level 5
```

## 📄 License

CVolve is open source under the [MIT License](LICENSE), © Future Site Di Haidar Anbar. You are free to use, modify, and distribute it, including commercially, as long as the copyright and license notice are kept. Bundled fonts are under the SIL Open Font License 1.1 (see each template's `LICENSE.txt`).

## 🙌 Using This Project?

If you use CVolve or build on its source code, please **credit the project by linking back to this repository**:

```markdown
Based on [CVolve](https://github.com/future-site-ai/cvolve) by Future Site Di Haidar Anbar.
```

---

## ⭐ Support the Project

If you find CVolve useful, please kindly:

- ⭐ **[Star this repository](https://github.com/future-site-ai/cvolve)**. It helps others discover the project.
- 👤 **[Follow me on GitHub](https://github.com/future-site-ai)** for more open-source projects.
- 🔗 **Link back to [this repo](https://github.com/future-site-ai/cvolve)** whenever you use or share this source code.

Thank you for your support! 💚
