<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Ai;

use Cvolve\TemplateKit\Import\ConversionException;
use Cvolve\TemplateKit\Import\MustacheConverter;
use Cvolve\TemplateKit\Package\InvalidTemplatePackageException;
use Cvolve\TemplateKit\Package\TemplatePackage;
use Cvolve\TemplateKit\Package\TemplatePackageReader;

/**
 * AI-driven template work: convert static HTML, generate from a description, edit on request.
 * The model is any text completion: a closure (system prompt, user prompt) => answer.
 * Results are validated with the package reader; the model gets one chance to repair errors.
 */
final class TemplateAi
{
    private string $syntax = 'twig';

    public function __construct(
        private readonly TemplatePackageReader $reader,
        private readonly MustacheConverter $mustache,
    ) {
    }

    /**
     * @param \Closure(string, string): string $complete
     * @param array<string, mixed>             $manifest base manifest (name, slug, fonts…)
     * @param array<string, string>            $assets   bundled files (fonts) the template may use
     */
    public function convertHtml(\Closure $complete, string $body, string $css, array $manifest, array $assets = []): TemplatePackage
    {
        $prompt = "STATIC HTML (body):\n".$body."\n\nCSS:\n".$css;

        return $this->createPackage($complete, Prompts::convertHtml(), $prompt, $manifest, $assets);
    }

    /**
     * @param \Closure(string, string): string $complete
     * @param array<string, mixed>             $manifest
     */
    public function generate(\Closure $complete, string $description, array $manifest): TemplatePackage
    {
        return $this->createPackage($complete, Prompts::generate(), "DESIGN BRIEF:\n".$description, $manifest);
    }

    /**
     * Apply a request to existing files. Returns the assistant message and the changed files
     * (only those the model changed), already validated together with the unchanged ones.
     *
     * @param \Closure(string, string): string                                                               $complete
     * @param array{template: string, css: string, schema: array<string, mixed>, manifest: array<string, mixed>} $files
     * @param array<string, string>                                                                          $assets
     * @param list<array{role: string, content: string}>                                                     $history
     *
     * @return array{message: string, files: array<string, mixed>, errors: list<string>}
     */
    public function edit(\Closure $complete, array $files, string $request, array $assets = [], array $history = [], string $syntax = 'twig'): array
    {
        $this->syntax = $syntax;
        $context = '';
        foreach (\array_slice($history, -6) as $turn) {
            $context .= strtoupper($turn['role']).': '.mb_substr($turn['content'], 0, 1500)."\n";
        }
        $prompt = ($context !== '' ? "EARLIER CONVERSATION:\n".$context."\n" : '')
            ."CURRENT FILES:\n".json_encode($files, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
            ."\n\nREQUEST:\n".$request;

        $answer = $this->decode($complete(Prompts::edit($syntax), $prompt));
        $changed = $this->changedFiles($answer['files'] ?? []);
        $errors = $changed === [] ? [] : $this->errors(array_merge($files, $changed), $assets, $syntax);
        if ($errors !== []) {
            $retry = $this->decode($complete(Prompts::edit($syntax), $prompt."\n\nYOUR PREVIOUS ANSWER:\n".json_encode($answer, \JSON_UNESCAPED_UNICODE)."\n\n".Prompts::repair($errors)));
            $retryChanged = $this->changedFiles($retry['files'] ?? []);
            $retryErrors = $this->errors(array_merge($files, $changed, $retryChanged), $assets, $syntax);
            if (\count($retryErrors) <= \count($errors)) {
                [$answer, $changed, $errors] = [$retry + $answer, array_merge($changed, $retryChanged), $retryErrors];
            }
        }

        return [
            'message' => \is_string($answer['message'] ?? null) ? $answer['message'] : 'Done.',
            'files' => $changed,
            'errors' => $errors,
        ];
    }

    /**
     * @param \Closure(string, string): string $complete
     * @param array<string, mixed>             $manifest
     * @param array<string, string>            $assets
     */
    private function createPackage(\Closure $complete, string $system, string $prompt, array $manifest, array $assets = []): TemplatePackage
    {
        $answer = $this->decode($complete($system, $prompt));
        $this->syntax = 'twig';
        $files = $this->filesFromAnswer($answer, $manifest);
        $errors = $this->errors($files, $assets);
        if ($errors !== []) {
            $answer = $this->decode($complete($system, $prompt."\n\nYOUR PREVIOUS ANSWER:\n".json_encode($answer, \JSON_UNESCAPED_UNICODE)."\n\n".Prompts::repair($errors)));
            $files = $this->filesFromAnswer($answer, $manifest);
        }

        return $this->reader->fromFiles($this->toPackageFiles($files) + $assets);
    }

    /**
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $manifest
     *
     * @return array{template: string, css: string, schema: array<string, mixed>, manifest: array<string, mixed>}
     */
    private function filesFromAnswer(array $answer, array $manifest): array
    {
        $name = \is_string($answer['name'] ?? null) && trim($answer['name']) !== '' ? trim($answer['name']) : ($manifest['name'] ?? 'AI template');
        $manifest = array_merge([
            'format' => TemplatePackageReader::FORMAT,
            'formatVersion' => TemplatePackageReader::FORMAT_VERSION,
            'version' => '1.0.0',
            'page' => 'A4',
            'maxPages' => 2,
        ], $manifest, ['name' => $name]);
        $manifest['slug'] ??= self::slug($name);
        if (\is_string($answer['description'] ?? null)) {
            $manifest['description'] = $answer['description'];
        }
        if (\is_string($answer['accent'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $answer['accent'])) {
            $manifest['docx'] = ['accent' => $answer['accent'], 'font' => $manifest['docx']['font'] ?? 'Calibri'];
        }

        return [
            'template' => \is_string($answer['template'] ?? null) ? $answer['template'] : '',
            'css' => \is_string($answer['css'] ?? null) ? $answer['css'] : '',
            'schema' => \is_array($answer['schema'] ?? null) ? $answer['schema'] : ['regions' => ['main'], 'sections' => ['summary' => [], 'experience' => [], 'education' => [], 'skills' => []]],
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function changedFiles(mixed $files): array
    {
        $out = [];
        if (!\is_array($files)) {
            return $out;
        }
        foreach (['template', 'css'] as $key) {
            if (\is_string($files[$key] ?? null) && trim($files[$key]) !== '') {
                $out[$key] = $files[$key];
            }
        }
        foreach (['schema', 'manifest'] as $key) {
            if (\is_array($files[$key] ?? null) && $files[$key] !== []) {
                $out[$key] = $files[$key];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>  $files
     * @param array<string, string> $assets
     *
     * @return list<string>
     */
    public function errors(array $files, array $assets = [], ?string $syntax = null): array
    {
        if (($syntax ?? $this->syntax) === 'mustache') {
            try {
                $files['template'] = $this->mustache->toTwig((string) ($files['template'] ?? ''));
            } catch (ConversionException $e) {
                return $e->errors;
            }
        }
        try {
            $this->reader->fromFiles($this->toPackageFiles($files) + $assets);

            return [];
        } catch (InvalidTemplatePackageException $e) {
            return $e->errors;
        }
    }

    /**
     * @param array<string, mixed> $files template/css/schema/manifest
     *
     * @return array<string, string>
     */
    public function toPackageFiles(array $files): array
    {
        return [
            'manifest.json' => (string) json_encode($files['manifest'] ?? [], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'schema.json' => (string) json_encode($files['schema'] ?? [], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'template.html.twig' => (string) ($files['template'] ?? ''),
            'style.css' => (string) ($files['css'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $answer): array
    {
        $answer = trim($answer);
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $answer, $m)) {
            $answer = trim($m[1]);
        }
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('The AI did not answer with JSON.');
        }
        $data = json_decode(substr($answer, $start, $end - $start + 1), true);
        if (!\is_array($data)) {
            throw new \RuntimeException('The AI answer is not valid JSON: '.json_last_error_msg());
        }

        return $data;
    }

    public static function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name)), '-');

        return substr($slug !== '' && \strlen($slug) >= 2 ? $slug : 'template-'.bin2hex(random_bytes(3)), 0, 60);
    }
}
