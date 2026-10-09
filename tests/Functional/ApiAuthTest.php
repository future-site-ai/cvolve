<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\TemplateRepository;
use App\Service\Cv\CvBuilder;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiAuthTest extends WebTestCase
{
    use DatabaseTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep one kernel (and entity manager) across requests in a test
        $this->client->disableReboot();
        $this->resetDatabase();
        $this->seeder()->installPresets();
    }

    private function token(User $user, ?\DateTimeImmutable $expires = null): string
    {
        $plain = ApiToken::generatePlainToken();
        $em = static::getContainer()->get('doctrine')->getManager();
        // Requests reset the entity manager, so work with a managed copy
        $user = $em->find(User::class, $user->getId());
        $em->persist(new ApiToken($user, 'test', $plain, $expires));
        $em->flush();

        return $plain;
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function api(string $method, string $uri, ?string $token, ?array $json = null): int
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $uri, [], [], $headers, $json !== null ? (string) json_encode($json) : null);

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testRequestsWithoutValidTokenAreRejected(): void
    {
        $user = $this->createUser();

        self::assertSame(401, $this->api('GET', '/api/v1/me', null));
        self::assertSame(401, $this->api('GET', '/api/v1/me', 'cvk_invalid'));
        self::assertSame(401, $this->api('GET', '/api/v1/me', $this->token($user, new \DateTimeImmutable('-1 day'))));
        self::assertSame(401, $this->api('POST', '/mcp', null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->find(User::class, $user->getId())->setEnabled(false);
        $em->flush();
        self::assertSame(401, $this->api('GET', '/api/v1/me', $this->token($user)));
    }

    public function testValidTokenAuthenticates(): void
    {
        $token = $this->token($this->createUser());

        self::assertSame(200, $this->api('GET', '/api/v1/me', $token));
        self::assertSame('jane@example.com', $this->json()['email']);

        self::assertSame(201, $this->api('POST', '/api/v1/cvs', $token, ['title' => 'From API']));
        $cvId = $this->json()['cv_id'];
        self::assertSame(200, $this->api('GET', '/api/v1/cvs/'.$cvId, $token));
        self::assertSame('From API', $this->json()['title']);
    }

    public function testUsersCannotReachOtherUsersData(): void
    {
        $owner = $this->createUser();
        $template = static::getContainer()->get(TemplateRepository::class)->findPreset('classic');
        \assert($template !== null);
        $cv = static::getContainer()->get(CvBuilder::class)->create($owner, 'Private', $template);
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist($cv);
        $em->flush();

        $intruder = $this->token($this->createUser('mallory@example.com'));
        self::assertSame(403, $this->api('GET', '/api/v1/cvs/'.$cv->getId(), $intruder));
        self::assertSame(403, $this->api('DELETE', '/api/v1/cvs/'.$cv->getId(), $intruder));
        self::assertSame(422, $this->api('POST', '/api/v1/tools/get_cv', $intruder, ['cv_id' => $cv->getId()]));
        self::assertSame(403, $this->api('POST', '/api/v1/cvs/'.$cv->getId().'/exports', $intruder, ['format' => 'pdf']));
    }

    public function testMcpHandshakeAndToolCall(): void
    {
        $token = $this->token($this->createUser());

        self::assertSame(200, $this->api('POST', '/mcp', $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]));
        self::assertSame('2025-06-18', $this->json()['result']['protocolVersion']);

        self::assertSame(202, $this->api('POST', '/mcp', $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        self::assertSame(200, $this->api('POST', '/mcp', $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']));
        $names = array_column($this->json()['result']['tools'], 'name');
        self::assertContains('set_professional_summary', $names);
        self::assertContains('tailor_to_job_description', $names);

        self::assertSame(200, $this->api('POST', '/mcp', $token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_templates', 'arguments' => []]]));
        self::assertFalse($this->json()['result']['isError']);
        self::assertCount(3, $this->json()['result']['structuredContent']['templates']);
    }

    public function testWebPagesRequireLogin(): void
    {
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testAdminAreaIsForAdminsOnly(): void
    {
        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser('root@example.com', true));
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }
}
