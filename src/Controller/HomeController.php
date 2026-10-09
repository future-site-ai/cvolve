<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AiProposal;
use App\Entity\User;
use App\Repository\AiProposalRepository;
use App\Repository\CvRepository;
use App\Repository\TemplateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(TemplateRepository $templates): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('home/landing.html.twig', ['templates' => $templates->findBy(['owner' => null, 'published' => true], ['name' => 'ASC'])]);
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(CvRepository $cvs, AiProposalRepository $proposals): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $list = $cvs->findBy(['owner' => $user], ['updatedAt' => 'DESC']);
        $pending = [];
        foreach ($proposals->findBy(['cv' => $list, 'status' => AiProposal::STATUS_PENDING]) as $proposal) {
            $pending[$proposal->getCv()->getId()] = ($pending[$proposal->getCv()->getId()] ?? 0) + 1;
        }

        return $this->render('home/dashboard.html.twig', [
            'cvs' => $list,
            'pending' => $pending,
            'profile' => $user->getMasterProfile(),
        ]);
    }
}
