<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AiProposal;
use App\Entity\ChatConversation;
use App\Repository\TemplateRepository;
use App\Service\Ai\ChatAgent;
use App\Service\Ai\ProviderFactory;
use App\Service\Cv\CvBuilder;
use App\Tests\DatabaseTestTrait;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The chat agent wired to Neuron AI with a scripted provider: the model calls a CV tool,
 * the tool creates a proposal, and the CV itself stays unchanged.
 */
final class ChatAgentTest extends KernelTestCase
{
    use DatabaseTestTrait;

    public function testAgentToolCallsBecomeProposals(): void
    {
        self::bootKernel();
        $em = $this->resetDatabase();
        $this->seeder()->installPresets();
        $user = $this->createUser();
        $template = static::getContainer()->get(TemplateRepository::class)->findPreset('cv-software');
        \assert($template !== null);
        $cv = static::getContainer()->get(CvBuilder::class)->create($user, 'Chat CV', $template);
        $conversation = new ChatConversation($cv);
        $em->persist($cv);
        $em->persist($conversation);
        $em->flush();

        static::getContainer()->get(ProviderFactory::class)->override(new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('set_professional_summary', 'call_1', ['text' => 'Written by the agent.'])]),
            new AssistantMessage('I proposed a new summary. Review it and click Apply.'),
        ));

        $reply = static::getContainer()->get(ChatAgent::class)->respond($conversation, $cv->getDefaultVariant(), 'Write me a summary');

        self::assertSame('I proposed a new summary. Review it and click Apply.', $reply->getContent());
        self::assertContains(['tool' => 'set_professional_summary', 'ok' => true], $reply->getToolCalls());

        $proposals = $em->getRepository(AiProposal::class)->findBy(['cv' => $cv]);
        self::assertCount(1, $proposals);
        self::assertSame('chat', $proposals[0]->getSource());
        self::assertSame('Written by the agent.', $proposals[0]->getOperations()[0]['content']['text']);
        self::assertNull($cv->getDefaultVariant()->findSectionByType('summary'));
        self::assertCount(2, $conversation->getMessages());
    }

    public function testOfflineModeUnderstandsBasicCommands(): void
    {
        self::bootKernel();
        $em = $this->resetDatabase();
        $this->seeder()->installPresets();
        $user = $this->createUser();
        $this->profileOf($user)->addSummary((new \App\Entity\Profile\ProfileSummary())->setLabel('Main')->setContent('Seasoned engineer.'));
        $em->flush();
        $template = static::getContainer()->get(TemplateRepository::class)->findPreset('classic');
        \assert($template !== null);
        $cv = static::getContainer()->get(CvBuilder::class)->create($user, 'CV', $template, 'en', ['section_types' => ['experience']]);
        $conversation = new ChatConversation($cv);
        $em->persist($cv);
        $em->persist($conversation);
        $em->flush();

        $reply = static::getContainer()->get(ChatAgent::class)->respond($conversation, $cv->getDefaultVariant(), 'Add professional summary from my master info');

        self::assertStringContainsString('Apply', $reply->getContent());
        $proposal = $em->getRepository(AiProposal::class)->findOneBy(['cv' => $cv]);
        self::assertSame('Seasoned engineer.', $proposal?->getOperations()[0]['content']['text']);
    }
}
