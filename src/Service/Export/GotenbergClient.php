<?php

declare(strict_types=1);

namespace App\Service\Export;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to a Gotenberg 8 container: Chromium renders the CV HTML into a PDF
 * (or a PNG screenshot for template thumbnails).
 */
final class GotenbergClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(GOTENBERG_URL)%')]
        private readonly string $baseUrl,
    ) {
    }

    public function htmlToPdf(string $html): string
    {
        return $this->post('/forms/chromium/convert/html', $html, [
            'paperWidth' => '8.27',
            'paperHeight' => '11.7',
            'marginTop' => '0',
            'marginBottom' => '0',
            'marginLeft' => '0',
            'marginRight' => '0',
            'printBackground' => 'true',
            'preferCssPageSize' => 'true',
            'emulatedMediaType' => 'print',
        ]);
    }

    public function screenshot(string $html, int $width = 794, int $height = 1123): string
    {
        return $this->post('/forms/chromium/screenshot/html', $html, [
            'width' => (string) $width,
            'height' => (string) $height,
            'clip' => 'true',
            'format' => 'png',
            'emulatedMediaType' => 'print',
        ]);
    }

    public function isAvailable(): bool
    {
        try {
            return $this->httpClient->request('GET', rtrim($this->baseUrl, '/').'/health', ['timeout' => 2])->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function countPages(string $pdf): int
    {
        return max(1, preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf));
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $route, string $html, array $fields): string
    {
        $form = new FormDataPart([
            ...$fields,
            'files' => new DataPart($html, 'index.html', 'text/html'),
        ]);

        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').$route, [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
            'timeout' => 60,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Gotenberg returned HTTP %d: %s', $response->getStatusCode(), mb_substr($response->getContent(false), 0, 300)));
        }

        return $response->getContent();
    }
}
