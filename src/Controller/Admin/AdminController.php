<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AiProposal;
use App\Entity\ChatMessage;
use App\Entity\Cv;
use App\Entity\ExportJob;
use App\Entity\Template;
use App\Entity\User;
use App\Repository\TemplateRepository;
use App\Repository\UserRepository;
use App\Service\Cv\CvRenderer;
use App\Service\Export\GotenbergClient;
use App\Service\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'admin_dashboard')]
    public function dashboard(): Response
    {
        $count = fn (string $class, array $criteria = []) => $this->em->getRepository($class)->count($criteria);
        $since = new \DateTimeImmutable('-30 days');
        $recent = fn (string $class, string $field) => (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')->from($class, 'e')->where("e.$field >= :since")->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'Users' => $count(User::class),
                'New users (30 days)' => $recent(User::class, 'createdAt'),
                'CVs' => $count(Cv::class),
                'Templates' => $count(Template::class),
                'Exports (30 days)' => $recent(ExportJob::class, 'createdAt'),
                'Chat messages (30 days)' => $recent(ChatMessage::class, 'createdAt'),
                'AI proposals applied' => $count(AiProposal::class, ['status' => AiProposal::STATUS_APPLIED]),
                'AI proposals pending' => $count(AiProposal::class, ['status' => AiProposal::STATUS_PENDING]),
            ],
        ]);
    }

    #[Route('/users', name: 'admin_users')]
    public function users(Request $request, UserRepository $users): Response
    {
        $query = trim($request->query->getString('q'));

        return $this->render('admin/users.html.twig', ['users' => $users->search($query ?: null), 'q' => $query]);
    }

    #[Route('/users/{id}/{action}', name: 'admin_user_action', requirements: ['action' => 'enable|disable|promote|demote|verify'], methods: ['POST'])]
    public function userAction(User $user, string $action, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-user-'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($user === $this->getUser() && \in_array($action, ['disable', 'demote'], true)) {
            $this->addFlash('error', 'You cannot disable or demote yourself.');

            return $this->redirectToRoute('admin_users');
        }
        match ($action) {
            'enable' => $user->setEnabled(true),
            'disable' => $user->setEnabled(false),
            'promote' => $user->setAdmin(true),
            'demote' => $user->setAdmin(false),
            'verify' => $user->setIsVerified(true),
            default => throw $this->createNotFoundException(),
        };
        $this->em->flush();
        $this->addFlash('success', sprintf('%s: %s done.', $user->getEmail(), $action));

        return $this->redirectToRoute('admin_users', ['q' => $request->request->get('q')]);
    }

    #[Route('/templates', name: 'admin_templates')]
    public function templates(TemplateRepository $templates): Response
    {
        $usage = [];
        foreach ($this->em->createQuery('SELECT IDENTITY(c.template) AS t, COUNT(c.id) AS n FROM App\Entity\Cv c GROUP BY c.template')->getArrayResult() as $row) {
            $usage[(int) $row['t']] = (int) $row['n'];
        }

        return $this->render('admin/templates.html.twig', ['templates' => $templates->findPresets(), 'usage' => $usage]);
    }

    #[Route('/templates/{id}/{action}', name: 'admin_template_action', requirements: ['action' => 'publish|unpublish|thumbnail'], methods: ['POST'])]
    public function templateAction(Template $template, string $action, Request $request, CvRenderer $renderer, GotenbergClient $gotenberg, Storage $storage): Response
    {
        if (!$template->isPreset() || !$this->isCsrfTokenValid('admin-template-'.$template->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($action === 'thumbnail') {
            try {
                $png = $gotenberg->screenshot($renderer->renderSample($template, CvRenderer::MODE_PRINT));
                $storage->write('templates/'.$template->getStorageKey().'/thumbnail.png', $png);
                $this->addFlash('success', 'Thumbnail regenerated from the sample CV.');
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Gotenberg is not reachable: '.$e->getMessage());
            }
        } else {
            $template->setPublished($action === 'publish');
            $this->em->flush();
            $this->addFlash('success', sprintf('"%s" is now %s.', $template->getName(), $action === 'publish' ? 'published' : 'hidden from users'));
        }

        return $this->redirectToRoute('admin_templates');
    }
}
