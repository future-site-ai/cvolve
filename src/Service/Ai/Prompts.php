<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Cv;
use App\Entity\CvVariant;
use Cvolve\TemplateKit\SectionTypes;

/**
 * Prompt texts for the CVolve assistant and its generative tools.
 */
final class Prompts
{
    private const HONESTY = 'Use only facts found in the provided profile or CV. Never invent employers, dates, numbers, degrees or skills. If a metric is not given, describe the impact without a number.';

    public static function chatSystem(Cv $cv, CvVariant $variant, bool $canGenerate): string
    {
        $template = $cv->getTemplate();
        $sections = implode(', ', $template->getSupportedSectionTypes());
        $language = SectionTypes::LOCALES[$variant->getLocale()] ?? $variant->getLocale();
        $generate = $canGenerate
            ? 'generate_summary_from_master, suggest_summaries, rewrite_bullets, tailor_to_job_description and translate_cv call a second AI to write text; prefer them for those jobs.'
            : 'You may also write text yourself and propose it with set_professional_summary or update_section.';
        $honesty = self::HONESTY;

        return <<<TXT
            You are the CVolve assistant. You help the user build and improve their CV.

            Open CV: #{$cv->getId()} "{$cv->getTitle()}", {$language} version ({$variant->getLocale()}), template "{$template->getName()}".
            The template supports these section types: {$sections}.

            How you work:
            - You change CONTENT only. Fonts, colours and layout come from the template and cannot be changed in chat. If asked for a style change, say it is not possible and suggest switch_template.
            - Never step outside the template: only use the supported section types, and respect its limits (get_template_schema).
            - Every change is a PROPOSAL. Tools that edit the CV return a proposal id; the user reviews a before/after diff and clicks Apply. Never say a change was made: say it is proposed and ready to review.
            - Call get_cv first to learn section ids and the current content before proposing edits. Entry and bullet indexes start at 0.
            - For content from the user's career history, call get_master_profile and use add_section with from_master, add_experience_from_master, or write content based on it.
            - {$generate}
            - Text values may use **bold** and *italic*, nothing else.
            - {$honesty}
            - Write CV text in {$language} unless asked otherwise. Talk to the user in the language they write in.
            - Keep replies short: one or two sentences about what you proposed, or a direct answer.
            TXT;
    }

    public static function summaryWriter(string $locale, int $maxLength): string
    {
        $language = SectionTypes::LOCALES[$locale] ?? $locale;

        return "You are an expert CV writer. Write a professional summary for the top of a CV in {$language}.\n"
            ."Rules: first person implied (no \"I\"), no headings, 3 to 5 sentences, at most {$maxLength} characters. "
            ."Lead with role and years of experience, then the strongest proof points, then what the candidate brings. "
            ."You may highlight up to four key phrases with **bold**.\n".self::HONESTY;
    }

    /**
     * @param array<string, mixed> $profile
     */
    public static function summaryContext(array $profile, CvVariant $variant, string $instructions): string
    {
        unset($profile['personal']['dateOfBirth'], $profile['personal']['address'], $profile['personal']['phone'], $profile['personal']['email']);
        $current = $variant->findSectionByType('summary')?->getContent()['text'] ?? '';

        return "Target / instructions: ".($instructions !== '' ? $instructions : 'general, based on the CV headline: '.($variant->getHeader()['headline'] ?? ''))
            ."\n\nCurrent summary (may be empty):\n".$current
            ."\n\nMaster profile (JSON):\n".json_encode($profile, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    public static function bulletRewriter(string $locale, string $style): string
    {
        $language = SectionTypes::LOCALES[$locale] ?? $locale;
        $guide = match ($style) {
            'quantified' => 'Make results measurable where the source gives numbers; otherwise state concrete scope and outcome.',
            'action-oriented' => 'Start every bullet with a strong past-tense action verb; focus on outcomes.',
            'formal' => 'Use a formal, precise register.',
            'simpler' => 'Use plain words and short sentences; remove jargon.',
            default => 'Make every bullet concise: one line where possible, cut filler words.',
        };

        return "You rewrite CV bullet points in {$language}. {$guide}\n"
            .'Keep the same number of bullets and the same order, one bullet per source bullet. Keep a leading **Project name:** prefix if present. '
            .self::HONESTY."\nReturn a JSON array of strings.";
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function bulletContext(array $item, string $instructions): string
    {
        $bullets = array_map(static fn (array $b) => $b['text'], $item['bullets']);
        unset($item['bullets'], $item['visible']);

        return ($instructions !== '' ? "Extra instructions: {$instructions}\n\n" : '')
            .'Entry: '.json_encode($item, \JSON_UNESCAPED_UNICODE)."\n\nBullets:\n".json_encode($bullets, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }

    /**
     * @param array<string, array<string, mixed>> $rules
     */
    public static function tailor(string $locale, array $rules): string
    {
        $language = SectionTypes::LOCALES[$locale] ?? $locale;

        return "You tailor a CV to a job advertisement. The CV is written in {$language}.\n"
            ."You may: write a new headline and summary; for experience/projects/skills sections, reorder entries, set \"visible\" on entries and bullets to emphasise relevant experience, reword bullets to use the job's terminology where truthful, and move matching skills first.\n"
            ."You may also bring in relevant facts from the master profile. ".self::HONESTY."\n"
            .'Template limits per section: '.json_encode($rules)."\n"
            ."Return JSON: {\"headline\": \"...\", \"summary\": \"...\", \"sections\": [{\"section_id\": <id>, \"content\": <full new content for that section, same shape as given>}], \"notes\": \"2-3 sentences for the user on what you changed and any gaps vs. the job\"}.\n"
            .'Only include sections you change. Never include the summary section in "sections".';
    }

    /**
     * @param array<string, mixed> $cv
     * @param array<string, mixed> $profile
     */
    public static function tailorContext(array $cv, array $profile, string $jobDescription): string
    {
        unset($profile['personal']);

        return "JOB ADVERTISEMENT:\n".$jobDescription
            ."\n\nCURRENT CV (JSON):\n".json_encode(['header' => $cv['header'], 'sections' => $cv['sections']], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            ."\n\nMASTER PROFILE (JSON):\n".json_encode($profile, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    public static function translator(string $from, string $to): string
    {
        $source = SectionTypes::LOCALES[$from] ?? $from;
        $target = SectionTypes::LOCALES[$to] ?? $to;

        return "Translate this CV from {$source} to {$target} for a professional CV in the target market.\n"
            ."Return the SAME JSON structure: {\"header\": {...}, \"sections\": [{\"type\", \"title\", \"visible\", \"region\", \"content\"}]}, same number and order of sections, entries and bullets.\n"
            ."Translate section titles and all human text (headline, descriptions, bullets, degree names where there is a common equivalent, month names in dates, \"Present\").\n"
            .'Do not translate: person, company, product and institution names, URLs, email, phone, technology names. Keep **bold** and *italic* markers. Keep every "visible" flag unchanged.';
    }

    public static function profileParser(): string
    {
        return <<<TXT
            You convert a CV or career profile (plain text or Markdown) into CVolve profile JSON. Extract everything; do not summarise or drop entries. Do not invent anything.
            Schema (omit nothing that exists in the source; use null or [] when absent):
            {
              "personal": {"fullName", "headline", "email", "phone", "address", "nationality", "dateOfBirth", "website", "linkedin", "github", "workAuthorization", "drivingLicence", "extra"},
              "summaries": [{"label": "short label", "content": "summary text"}],
              "experiences": [{"title", "company", "location", "start", "end", "description", "tech", "bullets": ["..."],
                               "projects": [{"name", "url", "period", "description", "tech", "bullets": ["..."]}]}],
              "education": [{"degree", "institution", "location", "start", "end", "grade", "description"}],
              "skills": [{"group": "Back-end", "skills": [{"name": "PHP", "level": null}]}],
              "languages": [{"name", "level", "note"}],
              "certifications": [{"name", "issuer", "date", "credentialId", "url"}],
              "projects": [{"name", "role", "start", "end", "url", "tech", "description", "highlights": ["..."]}],
              "publications": [{"title", "authors", "venue", "date", "url"}],
              "custom_sections": [{"title": "Volunteering", "kind": "volunteering|awards|interests|soft_skills|references|custom", "body": "one entry per line"}]
            }
            Dates: keep the source wording (e.g. "Apr 2023", "Present"). Put projects done within a job inside that job's "projects"; personal or side projects go in top-level "projects".
            Anything that fits nowhere else goes into custom_sections.
            TXT;
    }

    public static function stripQuotes(string $text): string
    {
        return trim($text, " \t\n\r\0\x0B\"“”");
    }
}
