<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Import;

/**
 * Converts Handlebars templates (as used by JSON Resume themes) into sandboxed Twig.
 *
 * Supported: {{path}}, {{{path}}}, {{#each}}, {{#if}}/{{else}}, {{#unless}}, {{#with}},
 * plain {{#section}} blocks, this, ../parent, @index/@first/@last/@key, @root, comments,
 * whitespace control (~), partials (inlined from the theme files) and common helpers.
 * The root context is the JSON Resume document: {{resume.basics.name}} and {{basics.name}} both work.
 */
final class HandlebarsConverter
{
    /** Helpers that only format a value: render the (first) argument with a Twig filter. */
    private const HELPERS = [
        'formatDate' => '', 'dateFormat' => '', 'date' => '', 'MY' => '', 'Y' => '', 'DMY' => '', 'formatDateRange' => '',
        'toLowerCase' => '|lower', 'lowercase' => '|lower', 'lower' => '|lower',
        'toUpperCase' => '|upper', 'uppercase' => '|upper', 'upper' => '|upper',
        'capitalize' => '|capitalize', 'join' => "|join(', ')", 'nl2br' => '|nl2br', 'paragraphSplit' => '|nl2br',
        'removeProtocol' => '|pretty_url', 'trimURL' => '|pretty_url', 'prettifyUrl' => '|pretty_url', 'spaceToDash' => "|replace({' ': '-'})",
        'markdown' => '|md', 'md' => '|md', 'safe' => '', 'trim' => '|trim',
    ];

    /** @var list<string> */
    private array $warnings = [];

    private int $counter = 0;

    /**
     * @param array<string, string> $partials name => template source
     *
     * @return array{twig: string, warnings: list<string>}
     */
    public function toTwig(string $source, array $partials = []): array
    {
        $this->warnings = [];
        $this->counter = 0;
        $source = $this->inlinePartials($source, $partials);
        // Whitespace control: {{~ … ~}} trims neighbouring whitespace
        $source = (string) preg_replace(['/\s*\{\{~/', '/~\}\}\s*/'], ['{{', '}}'], $source);
        $source = (string) preg_replace('/\{\{!--.*?--\}\}|\{\{![^}]*\}\}/s', '', $source);

        $out = '';
        $errors = [];
        /** @var list<array{block: string, var: ?string, line: int}> $stack */
        $stack = [];
        $offset = 0;
        preg_match_all('/\{\{\{\s*(.+?)\s*\}\}\}|\{\{\s*(.*?)\s*\}\}/s', $source, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        foreach ($matches as $m) {
            $pos = $m[0][1];
            $out .= MustacheConverter::protectText(substr($source, $offset, $pos - $offset));
            $offset = $pos + \strlen($m[0][0]);
            $line = substr_count(substr($source, 0, $pos), "\n") + 1;
            $scopes = array_values(array_filter(array_column($stack, 'var')));

            $raw = isset($m[1]) && $m[1][1] !== -1;
            $tag = $raw ? $m[1][0] : $m[2][0];

            if (!$raw && preg_match('/^#([\w.]+)\s*(.*)$/s', $tag, $b)) {
                [$block, $args] = [$b[1], trim($b[2])];
                $expr = $this->expression($args, $scopes, $line, $errors);
                switch ($block) {
                    case 'each':
                        $var = '_h'.(++$this->counter);
                        $out .= sprintf('{%% for %s in (%s)|as_list %%}', $var, $expr);
                        $stack[] = ['block' => 'each', 'var' => $var, 'line' => $line];
                        break;
                    case 'if':
                        $out .= sprintf('{%% if %s %%}', $expr);
                        $stack[] = ['block' => 'if', 'var' => null, 'line' => $line];
                        break;
                    case 'unless':
                        $out .= sprintf('{%% if not (%s) %%}', $expr);
                        $stack[] = ['block' => 'unless', 'var' => null, 'line' => $line];
                        break;
                    case 'with':
                        $var = '_h'.(++$this->counter);
                        $out .= sprintf('{%% set %s = %s %%}{%% if %s %%}', $var, $expr, $var);
                        $stack[] = ['block' => 'with', 'var' => $var, 'line' => $line];
                        break;
                    default:
                        if ($args !== '') {
                            $errors[] = sprintf('Line %d: block helper "#%s" is not supported.', $line, $block);
                        }
                        // Mustache-style section: {{#work}}…{{/work}}
                        $var = '_h'.(++$this->counter);
                        $out .= sprintf('{%% for %s in (%s)|as_list %%}', $var, $this->path($block, $scopes));
                        $stack[] = ['block' => $block, 'var' => $var, 'line' => $line];
                }
                continue;
            }
            if (!$raw && preg_match('/^\^(\w[\w.]*)$/', $tag, $b)) {
                $out .= sprintf('{%% if not ((%s)|as_list) %%}', $this->path($b[1], $scopes));
                $stack[] = ['block' => $b[1], 'var' => null, 'line' => $line];
                continue;
            }
            if (!$raw && preg_match('/^\/(\w[\w.]*)$/', $tag, $b)) {
                $open = array_pop($stack);
                if ($open === null || $open['block'] !== $b[1]) {
                    $errors[] = sprintf('Line %d: {{/%s}} closes %s.', $line, $b[1], $open === null ? 'nothing' : '{{#'.$open['block'].'}}');
                    continue;
                }
                $out .= \in_array($open['block'], ['if', 'unless', 'with'], true) || $open['var'] === null ? '{% endif %}' : '{% endfor %}';
                continue;
            }
            if (!$raw && ($tag === 'else' || $tag === '^')) {
                $open = end($stack);
                if ($open === false) {
                    $errors[] = sprintf('Line %d: {{else}} outside a block.', $line);
                } else {
                    // {% for %}…{% else %} and {% if %}…{% else %} have the same meaning as in Handlebars
                    $out .= '{% else %}';
                }
                continue;
            }
            if (!$raw && str_starts_with($tag, '>')) {
                $errors[] = sprintf('Line %d: partial "%s" was not found in the theme files.', $line, trim(substr($tag, 1)));
                continue;
            }

            $expr = $this->expression($tag, $scopes, $line, $errors);
            if ($expr === "''") {
                continue;
            }
            $out .= $raw ? '{{ ('.$expr.')|md }}' : '{{ '.$expr.' }}';
        }
        $out .= MustacheConverter::protectText(substr($source, $offset));

        foreach ($stack as $open) {
            $errors[] = sprintf('Line %d: {{#%s}} is never closed.', $open['line'], $open['block']);
        }
        if ($errors !== []) {
            throw new ConversionException(array_values(array_unique($errors)), $this->warnings);
        }

        return ['twig' => $out, 'warnings' => array_values(array_unique($this->warnings))];
    }

    /**
     * A value expression: a path, a literal, or a helper call "helper arg …".
     *
     * @param list<string> $scopes
     * @param list<string> $errors
     */
    private function expression(string $tag, array $scopes, int $line, array &$errors): string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return "''";
        }
        if (str_contains($tag, '(')) {
            $errors[] = sprintf('Line %d: sub-expressions like "%s" are not supported.', $line, mb_strimwidth($tag, 0, 40, '…'));

            return "''";
        }
        $parts = preg_split('/\s+/', $tag) ?: [];
        // Drop hash arguments (format="MMM YYYY") of formatting helpers
        $parts = array_values(array_filter($parts, static fn (string $p) => !str_contains($p, '=')));
        if (\count($parts) === 1) {
            return $this->operand($parts[0], $scopes);
        }

        $helper = array_shift($parts);
        if (!\array_key_exists($helper, self::HELPERS)) {
            $this->warnings[] = sprintf('Helper "%s" is not available; its first argument is shown as is.', $helper);

            return $this->operand($parts[0], $scopes);
        }
        if (\in_array($helper, ['formatDateRange'], true) && \count($parts) >= 2) {
            return sprintf("(%s ~ ' – ' ~ %s)", $this->operand($parts[0], $scopes), $this->operand($parts[1], $scopes));
        }

        return '('.$this->operand($parts[0], $scopes).')'.self::HELPERS[$helper];
    }

    /**
     * @param list<string> $scopes
     */
    private function operand(string $token, array $scopes): string
    {
        if (preg_match('/^(["\']).*\1$/s', $token) || is_numeric($token)) {
            return $token;
        }
        if (\in_array($token, ['true', 'false', 'null'], true)) {
            return $token;
        }

        return $this->path($token, $scopes);
    }

    /**
     * @param list<string> $scopes loop/with variables, outermost first
     */
    private function path(string $path, array $scopes): string
    {
        $map = ['@index' => 'loop.index0', '@first' => 'loop.first', '@last' => 'loop.last', '@key' => 'loop.index0', '@number' => 'loop.index'];
        if (isset($map[$path])) {
            return $map[$path];
        }
        if (str_starts_with($path, '@root.')) {
            return $this->root(substr($path, 6));
        }

        $depth = 0;
        while (str_starts_with($path, '../')) {
            ++$depth;
            $path = substr($path, 3);
        }
        $path = (string) preg_replace('#^\./#', '', $path);
        $path = str_replace('/', '.', $path);

        $index = \count($scopes) - 1 - $depth;
        $base = $index >= 0 ? $scopes[$index] : null;

        if ($path === 'this' || $path === '.' || $path === '') {
            return $base ?? 'resume';
        }
        if (str_starts_with($path, 'this.')) {
            $path = substr($path, 5);
        }
        if (str_ends_with($path, '.length')) {
            return '('.$this->path(substr($path, 0, -7), $scopes).')|length';
        }
        if (!preg_match('/^[A-Za-z_][\w-]*(\.[\w-]+)*$/', $path)) {
            $this->warnings[] = sprintf('Could not read the path "%s".', $path);

            return "''";
        }
        $path = (string) preg_replace_callback('/\.(\d+)/', static fn ($m) => '['.$m[1].']', $path);
        if (str_contains($path, '-')) {
            $this->warnings[] = sprintf('Field "%s" contains a dash and cannot be read.', $path);

            return "''";
        }

        return $base !== null ? $base.'.'.$path : $this->root($path);
    }

    private function root(string $path): string
    {
        if (str_ends_with($path, '.length')) {
            return '('.$this->root(substr($path, 0, -7)).')|length';
        }
        if ($path === 'resume' || str_starts_with($path, 'resume.') || \in_array(explode('.', $path)[0], ['header', 'sections', 'regions', 'labels', 'cv', 'by_type'], true)) {
            return $path;
        }
        // Themes render with {resume, css}; some use the resume itself as root
        if (\in_array($path, ['css', 'style', 'styles'], true)) {
            return "''";
        }

        return '(resume.'.$path.' ?? '.$path.')';
    }

    /**
     * @param array<string, string> $partials
     */
    public function inlinePartials(string $source, array $partials, int $depth = 0): string
    {
        if ($depth > 5) {
            return $source;
        }

        return (string) preg_replace_callback('/\{\{>\s*([\w\/.-]+)[^}]*\}\}/', function (array $m) use ($partials, $depth) {
            $name = preg_replace('/\.(hbs|handlebars|html)$/', '', basename($m[1]));
            foreach ($partials as $key => $content) {
                if (preg_replace('/\.(hbs|handlebars|html)$/', '', basename($key)) === $name) {
                    return $this->inlinePartials($content, $partials, $depth + 1);
                }
            }

            return $m[0];
        }, $source);
    }
}
