<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AiProposal;
use App\Entity\ChatConversation;
use App\Entity\Cv;
use App\Repository\ChatConversationRepository;
use App\Security\OwnerVoter;
use App\Service\Ai\ChatAgent;
use App\Service\Cv\ProposalException;
use App\Service\Cv\ProposalManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The chat panel of the CV editor and the Apply / Discard buttons of proposals.
 * Called with fetch() by assets/controllers/chat_controller.js; answers carry rendered HTML.
 */
class ChatController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProposalManager $proposals,
    ) {
    }

    #[Route('/cvs/{id}/chat', name: 'chat_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(Cv $cv, Request $request, ChatAgent $agent, ChatConversationRepository $conversations): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf($request, 'chat-'.$cv->getId());
        $text = trim((string) $request->request->get('message'));
        if ($text === '') {
            return new JsonResponse(['error' => 'Type a message first.'], 422);
        }
        $text = mb_substr($text, 0, 8000);
        $locale = (string) $request->request->get('locale');
        $variant = ($locale !== '' ? $cv->getVariant($locale) : null) ?? $cv->getDefaultVariant();

        $conversation = $conversations->findOneBy(['cv' => $cv], ['id' => 'DESC']);
        if ($conversation === null) {
            $conversation = new ChatConversation($cv);
            $this->em->persist($conversation);
        }

        $reply = $agent->respond($conversation, $variant, $text);
        $proposalIds = array_values(array_filter(array_map(static fn (array $c) => $c['proposal'] ?? null, $reply->getToolCalls())));
        $proposals = $proposalIds === [] ? [] : $this->em->getRepository(AiProposal::class)->findBy(['id' => $proposalIds], ['id' => 'ASC']);

        return new JsonResponse([
            'message' => $this->renderView('chat/_message.html.twig', ['message' => $reply]),
            'proposals' => array_map(fn (AiProposal $p) => $this->renderView('chat/_proposal.html.twig', ['proposal' => $p, 'preview' => $this->proposals->preview($p)]), $proposals),
        ]);
    }

    #[Route('/cvs/{id}/chat/clear', name: 'chat_clear', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function clear(Cv $cv, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf($request, 'chat-'.$cv->getId());
        // Start a fresh conversation; old ones stay for the record
        $this->em->persist(new ChatConversation($cv));
        $this->em->flush();

        return $this->redirectToRoute('cv_editor', ['id' => $cv->getId()]);
    }

    #[Route('/proposals/{id}/{action}', name: 'proposal_resolve', requirements: ['id' => '\d+', 'action' => 'apply|discard'], methods: ['POST'])]
    public function resolve(AiProposal $proposal, string $action, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $proposal);
        $this->checkCsrf($request, 'chat-'.$proposal->getCv()->getId());

        $error = null;
        $locale = $proposal->getVariant()?->getLocale();
        try {
            if ($action === 'apply') {
                $locale = $this->proposals->apply($proposal)->getLocale();
            } else {
                $this->proposals->discard($proposal);
            }
        } catch (ProposalException $e) {
            $error = $e->getMessage();
        }

        if ($request->getPreferredFormat() !== 'json' && !$request->isXmlHttpRequest()) {
            $error !== null ? $this->addFlash('error', $error) : $this->addFlash('success', $action === 'apply' ? 'Change applied.' : 'Proposal discarded.');

            return $this->redirectToRoute('cv_editor', ['id' => $proposal->getCv()->getId(), 'locale' => $locale]);
        }

        return new JsonResponse([
            'ok' => $error === null,
            'error' => $error,
            'status' => $proposal->getStatus(),
            'locale' => $locale,
            'html' => $this->renderView('chat/_proposal.html.twig', ['proposal' => $proposal, 'preview' => $this->proposals->preview($proposal), 'error' => $error]),
        ], $error === null ? 200 : 409);
    }

    private function checkCsrf(Request $request, string $id): void
    {
        $token = (string) ($request->headers->get('X-CSRF-Token') ?? $request->request->get('_token'));
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token. Reload the page.');
        }
    }
}
