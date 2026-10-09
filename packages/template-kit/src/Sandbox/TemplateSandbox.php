<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Sandbox;

use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Renders template.html.twig from a .cvolve package inside Twig's sandbox.
 *
 * Templates receive plain arrays only (no objects), may use only the tags, filters,
 * functions and tests allow-listed here, and are autoescaped. Imported templates can
 * therefore shape the HTML but cannot run code, read files or reach the network.
 */
final class TemplateSandbox
{
    public const TAGS = ['if', 'for', 'set', 'apply', 'macro', 'import', 'from', 'with', 'verbatim'];

    public const FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'default', 'e', 'escape', 'filter', 'first', 'format', 'join',
        'keys', 'last', 'length', 'lower', 'map', 'merge', 'nl2br', 'number_format', 'replace', 'reverse',
        'round', 'slice', 'sort', 'split', 'striptags', 'title', 'trim', 'upper', 'url_encode',
        // CVolve filters (TemplateHelpersExtension)
        'md', 'period', 'pretty_url', 'initials', 'as_list',
    ];

    public const FUNCTIONS = ['range', 'max', 'min', 'cycle'];

    public const TESTS = ['defined', 'empty', 'null', 'none', 'odd', 'even', 'iterable', 'same as', 'divisible by'];

    /**
     * Render a template source with the given context.
     *
     * @param array<string, mixed> $context plain arrays and scalars only
     */
    public function render(string $source, array $context): string
    {
        return $this->environment($source)->render('template', $context);
    }

    private function environment(string $source): Environment
    {
        $twig = new Environment(new ArrayLoader(['template' => $source]), [
            'autoescape' => 'html',
            'strict_variables' => false,
            'cache' => false,
        ]);
        $policy = new SecurityPolicy(self::TAGS, self::FILTERS, [], [], self::FUNCTIONS, self::TESTS);
        // Strict: Twig tests (e.g. "constant") are denied unless allow-listed
        $policy->setStrict(true);
        $twig->addExtension(new SandboxExtension($policy, true));
        $twig->addExtension(new TemplateHelpersExtension());

        return $twig;
    }
}
