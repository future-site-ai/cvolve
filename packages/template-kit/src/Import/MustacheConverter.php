<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Import;

/**
 * Converts "CVolve HTML": plain HTML with Mustache tags, into sandboxed Twig.
 *
 *   {{header.name}}            escaped value (dot paths)
 *   {{{text}}} / {{&text}}     formatted text: **bold** and *italic* (never raw HTML)
 *   {{#regions.main}}…{{/regions.main}}   loop over a list, or show once if truthy
 *   {{^items}}…{{/items}}      show when empty / false
 *   {{.}}                      the current list value (e.g. a bullet)
 *   {{! comment }}
 *
 * Names resolve from the innermost loop outwards, like Mustache.
 */
final class MustacheConverter
{
    private int $counter = 0;

    private ?string $root = null;

    /**
     * @param string|null $root object that bare names fall back to (JSON Resume themes use "resume")
     */
    public function toTwig(string $source, ?string $root = null): string
    {
        $this->counter = 0;
        $this->root = $root;
        $out = '';
        /** @var list<array{name: string, var: string, inverted: bool, line: int}> $stack */
        $stack = [];
        $errors = [];
        $offset = 0;

        preg_match_all('/\{\{\{\s*(.+?)\s*\}\}\}|\{\{\s*([#^\/!&>=]?)\s*(.*?)\s*\}\}/s', $source, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            $pos = $m[0][1];
            $out .= self::protectText(substr($source, $offset, $pos - $offset));
            $offset = $pos + \strlen($m[0][0]);
            $line = substr_count(substr($source, 0, $pos), "\n") + 1;
            $scopes = array_values(array_filter(array_map(static fn (array $s) => $s['inverted'] ? null : $s['var'], $stack)));

            if (isset($m[1]) && $m[1][1] !== -1) {
                $out .= '{{ '.$this->resolve($m[1][0], $scopes, $line, $errors).'|md }}';
                continue;
            }

            $sigil = $m[2][0];
            $name = $m[3][0];
            switch ($sigil) {
                case '!':
                    break;
                case '&':
                    $out .= '{{ '.$this->resolve($name, $scopes, $line, $errors).'|md }}';
                    break;
                case '#':
                case '^':
                    $expr = $this->resolve($name, $scopes, $line, $errors);
                    if ($sigil === '#') {
                        $var = '_m'.(++$this->counter);
                        $out .= sprintf('{%% for %s in (%s)|as_list %%}', $var, $expr);
                    } else {
                        $var = '';
                        $out .= sprintf('{%% if not ((%s)|as_list) %%}', $expr);
                    }
                    $stack[] = ['name' => $name, 'var' => $var, 'inverted' => $sigil === '^', 'line' => $line];
                    break;
                case '/':
                    $open = array_pop($stack);
                    if ($open === null || $open['name'] !== $name) {
                        $errors[] = sprintf('Line %d: {{/%s}} closes %s.', $line, $name, $open === null ? 'nothing' : '{{#'.$open['name'].'}}');
                        break;
                    }
                    $out .= $open['inverted'] ? '{% endif %}' : '{% endfor %}';
                    break;
                case '>':
                    $errors[] = sprintf('Line %d: partials ({{> %s}}) are not supported; put the markup inline.', $line, $name);
                    break;
                case '=':
                    $errors[] = sprintf('Line %d: changing delimiters is not supported.', $line);
                    break;
                default:
                    $out .= '{{ '.$this->resolve($name, $scopes, $line, $errors).' }}';
            }
        }
        $out .= self::protectText(substr($source, $offset));

        foreach ($stack as $open) {
            $errors[] = sprintf('Line %d: {{%s%s}} is never closed.', $open['line'], $open['inverted'] ? '^' : '#', $open['name']);
        }
        if ($errors !== []) {
            throw new ConversionException($errors);
        }

        return $out;
    }

    /**
     * @param list<string> $scopes loop variables, outermost first
     * @param list<string> $errors
     */
    private function resolve(string $name, array $scopes, int $line, array &$errors): string
    {
        if ($name === '.' || $name === 'this') {
            if ($scopes === []) {
                $errors[] = sprintf('Line %d: {{.}} is only valid inside a section.', $line);

                return "''";
            }

            return end($scopes);
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*$/', $name)) {
            $errors[] = sprintf('Line %d: "%s" is not a valid name (use letters, digits, _ and dots).', $line, $name);

            return "''";
        }
        // JavaScript's array.length becomes Twig's |length
        $length = str_ends_with($name, '.length');
        if ($length) {
            $name = substr($name, 0, -7);
        }
        $twigPath = (string) preg_replace('/\.(\d+)/', '[$1]', $name);
        $chain = [];
        foreach (array_reverse($scopes) as $scope) {
            $chain[] = $scope.'.'.$twigPath;
        }
        if ($this->root !== null && !str_starts_with($twigPath, $this->root.'.') && $twigPath !== $this->root) {
            $chain[] = $this->root.'.'.$twigPath;
        }
        $chain[] = $twigPath;
        $expr = \count($chain) === 1 ? $chain[0] : '('.implode(' ?? ', $chain).')';

        return $length ? '('.$expr.')|length' : $expr;
    }

    /**
     * Literal text must not be read as Twig syntax.
     */
    public static function protectText(string $text): string
    {
        if (!preg_match('/\{[{%#]/', $text)) {
            return $text;
        }

        return (string) preg_replace_callback('/\{([{%#])/', static fn (array $m) => "{{ '{".$m[1]."' }}", $text);
    }
}
