# CVolve Template Kit

The template engine shared by [CVolve](https://github.com/future-site-ai/cvolve) and the [CVolve Template Maker](https://github.com/future-site-ai/cvolve_template_maker), by Future Site Di Haidar Anbar.

- `Sandbox\TemplateSandbox`: strict Twig sandbox and the CV filters (`md`, `period`, `pretty_url`, `initials`, `as_list`)
- `Package\TemplatePackageReader` / `TemplatePackageWriter`: validate, read and write `.cvolve` packages
- `Render\DocumentRenderer`, `ContextEnricher`, `JsonResumeMapper`, `SampleCv`: build print-ready HTML
- `Import\TemplateImporter`: Mustache HTML, Twig HTML, JSON Resume themes, HTML zips, AI conversion of static HTML
- `Ai\TemplateAi` + `Ai\Prompts`: convert, generate and edit templates with any LLM (pass a `fn (string $system, string $prompt): string`)
- `Presets`: the bundled preset templates

The full format specification is in the CVolve README. MIT licensed.
