<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Ai;

use Cvolve\TemplateKit\Sandbox\TemplateSandbox;
use Cvolve\TemplateKit\SectionTypes;

/**
 * Prompts for AI models that write CVolve templates (conversion, generation, editing).
 */
final class Prompts
{
    /**
     * Everything a model must know to write a valid template.
     */
    public static function contract(): string
    {
        $tags = implode(', ', TemplateSandbox::TAGS);
        $filters = implode(', ', TemplateSandbox::FILTERS);
        $functions = implode(', ', TemplateSandbox::FUNCTIONS);
        $tests = implode(', ', TemplateSandbox::TESTS);
        $types = implode(', ', array_keys(SectionTypes::TYPES));
        $shapes = <<<'TXT'
            experience: title, organization, location, start, end, period, description, tech, bullets (list of strings)
            projects: name, role, start, end, period, url, tech, description, bullets
            education: degree, institution, location, start, end, period, grade, description
            skills: group, skills (comma separated string)
            languages: name, level, note
            certifications: name, issuer, date, period, url
            publications: title, authors, venue, date, period, url
            custom: title, subtitle, date, period, description
            TXT;

        return <<<TXT
            # CVolve template contract

            A template is: template.html.twig (renders the CONTENT OF <body> only), style.css, schema.json, manifest.json.
            It is rendered by Twig in a strict sandbox and printed to A4 by Chromium.

            ## Variables (plain arrays; all text is autoescaped)
            - header: name, headline, email, phone, location, website, linkedin, github, nationality, dateOfBirth, workAuthorization, drivingLicence, extra, photo (data URI or null)
            - regions.<region>: the visible sections assigned to that region, in order (regions are declared in schema.json, e.g. "sidebar", "main")
            - sections: all visible sections in order; by_type.<type>: first section of a type
            - section: id, type, title, region, text (summary only), items (visible entries), has_items, is_<type> (true)
            - item fields by section type:
            {$shapes}
            - labels: contact, personal, present, nationality, born, work_authorization, driving_licence, tech, grade (already translated)
            - cv: title, locale, dir ("ltr" or "rtl")
            - every item also has: period ("start – end"), heading (its main label) and subheading (organization/institution/issuer…, location)
            Section types: {$types}. Empty fields are "" (test with {% if item.tech %}). Every section and item may be missing: always guard.

            ## Allowed Twig (anything else fails)
            - tags: {$tags}
            - filters: {$filters}
              md = renders **bold**/*italic* safely: use it for section.text, descriptions and bullets ({{ bullet|md }})
              period = "start – end" of an item; pretty_url strips https://www.; as_list wraps a value as a list
            - functions: {$functions}; tests: {$tests}
            - Macros are allowed: {% macro x(s) %}…{% endmacro %} then {{ _self.x(s) }}
            - NOT allowed: raw, include, source, block, attribute, method calls, <script>, <style>, <link>, <iframe>, event handlers (onclick=…), url() in markup, external images. Images: only <img src="{{ header.photo }}"> guarded by {% if header.photo %}.

            ## style.css rules
            - Print CSS for A4: start with @page { size: A4; margin: 0; } and use padding inside the page.
            - No @import, no remote url(). Fonts only via files bundled in fonts/ (declared in manifest.json "fonts"); otherwise use system stacks ("Helvetica Neue", Arial, sans-serif / Georgia, serif).
            - Multi-page: use box-decoration-break: clone on columns so padding repeats per page; full-height sidebars via html, body { background: linear-gradient(to right, COLOR 0 30%, #fff 30%) }; h2 { break-after: avoid }; avoid break-inside on long blocks (only on li).
            - Sizes in pt/mm. Body text 9–10.5pt. Keep it ATS-friendly: real text, logical order.

            ## schema.json
            {"regions": ["sidebar", "main"], "sections": {"summary": {"region": "main", "maxLength": 900}, "experience": {"region": "main", "maxItems": 6, "maxBullets": 8}, …}}
            List every section type the template renders, each with a region from "regions". Limits are optional positive integers.

            ## manifest.json
            {"format": "cvolve-template", "formatVersion": 1, "name": "…", "slug": "lowercase-dashes", "version": "1.0.0", "author": "…", "licence": "MIT", "description": "one sentence", "page": "A4", "maxPages": 2, "fonts": [], "docx": {"accent": "#RRGGBB", "font": "Calibri"}}
            TXT;
    }

    /**
     * Turn a finished static HTML CV into a template.
     */
    public static function convertHtml(): string
    {
        return self::contract()."\n\n".<<<'TXT'
            # Task
            You receive a finished, static HTML CV (body markup and its CSS). Turn it into a CVolve template that looks the same
            but is driven by the variables above: replace the person's details with header.*, and every repeated block
            (jobs, schools, skills, languages, certificates…) with loops over the matching section items. Keep the design, colours,
            spacing and layout. Map columns to regions (e.g. a sidebar column becomes region "sidebar").
            Render section headings from section.title, not hard-coded text. Use labels.* for fixed words like "Contact".

            Answer with ONE JSON object only (no markdown fences):
            {"name": "Template name", "description": "one sentence", "template": "<twig markup>", "css": "<css>", "schema": {…}, "accent": "#RRGGBB"}
            TXT;
    }

    /**
     * Create a template from a description.
     */
    public static function generate(): string
    {
        return self::contract()."\n\n".<<<'TXT'
            # Task
            Design a complete, polished, print-ready CVolve template from the designer's description.
            Make it look professional: clear hierarchy, consistent spacing, at most 2 accent colours, readable on paper.
            Support all section types unless the description says otherwise. Use macros to avoid repetition.

            Answer with ONE JSON object only (no markdown fences):
            {"name": "Template name", "description": "one sentence", "template": "<twig markup>", "css": "<css>", "schema": {…}, "accent": "#RRGGBB"}
            TXT;
    }

    /**
     * Edit an existing template following the designer's request.
     */
    public static function edit(string $syntax = 'twig'): string
    {
        return self::contract()."\n\n".($syntax === 'mustache' ? self::mustache()."\n\n" : '').<<<'TXT'
            # Task
            You are a template designer's assistant. You receive the current template files and a request.
            Change only what the request needs and keep everything else exactly as it is.

            Answer with ONE JSON object only (no markdown fences):
            {"message": "1–3 sentences explaining what you changed", "files": {"template": "<full new markup>", "css": "<full new css>", "schema": {…}, "manifest": {…}}}
            Include in "files" only the files you changed, each COMPLETE (not a diff). If you only answer a question, return "files": {}.
            TXT;
    }

    /**
     * The Mustache dialect ("CVolve HTML") some designers write instead of Twig.
     */
    public static function mustache(): string
    {
        return <<<'TXT'
            # This template's markup is MUSTACHE ("CVolve HTML"), not Twig
            Write the "template" file in Mustache, using the same variables:
            {{header.name}} escaped value · {{{text}}} formatted text (**bold**/*italic*) · {{#regions.main}}…{{/regions.main}} loop or condition ·
            {{^items}}…{{/items}} when empty · {{.}} current list value · {{#is_experience}}…{{/is_experience}} type checks ·
            {{#header.photo}}<img src="{{header.photo}}">{{/header.photo}} · names resolve from the innermost block outwards.
            No Twig tags or filters, no partials. CSS rules are the same.
            TXT;
    }

    /**
     * Ask the model to fix validation errors in its own output.
     *
     * @param list<string> $errors
     */
    public static function repair(array $errors): string
    {
        return "Your template does not pass validation:\n- ".implode("\n- ", $errors)
            ."\nFix these problems and answer again with the same JSON structure, complete files only.";
    }
}
