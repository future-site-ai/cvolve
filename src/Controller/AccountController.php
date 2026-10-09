<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Form\ApiTokenFormType;
use App\Form\ChangePasswordFormType;
use App\Repository\ApiTokenRepository;
use App\Security\OwnerVoter;
use App\Service\Ai\ProviderFactory;
use App\Service\Export\GotenbergClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/account')]
class AccountController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'account')]
    public function index(Request $request, UserPasswordHasherInterface $hasher, ApiTokenRepository $tokens, ProviderFactory $ai, GotenbergClient $gotenberg): Response
    {
        $user = $this->user();

        $nameForm = $this->createFormBuilder(['fullName' => $user->getFullName()])
            ->add('fullName', TextType::class, ['label' => 'Full name', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->getForm();
        $nameForm->handleRequest($request);
        if ($nameForm->isSubmitted() && $nameForm->isValid()) {
            $user->setFullName($nameForm->get('fullName')->getData());
            $this->em->flush();
            $this->addFlash('success', 'Name updated.');

            return $this->redirectToRoute('account');
        }

        $passwordForm = $this->createForm(ChangePasswordFormType::class, null, ['require_current' => true]);
        $passwordForm->handleRequest($request);
        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $user->setPassword($hasher->hashPassword($user, (string) $passwordForm->get('plainPassword')->getData()));
            $this->em->flush();
            $this->addFlash('success', 'Password changed.');

            return $this->redirectToRoute('account');
        }

        $tokenForm = $this->createForm(ApiTokenFormType::class, null, ['action' => $this->generateUrl('account_token_create')]);

        return $this->render('account/index.html.twig', [
            'name_form' => $nameForm,
            'password_form' => $passwordForm,
            'token_form' => $tokenForm,
            'tokens' => $tokens->findBy(['owner' => $user], ['id' => 'DESC']),
            'new_token' => $request->getSession()->remove('new_api_token'),
            'ai' => ['configured' => $ai->isConfigured(), 'label' => $ai->label()],
            'gotenberg' => $gotenberg->isAvailable(),
            'mcp_url' => $this->generateUrl('mcp', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    #[Route('/tokens', name: 'account_token_create', methods: ['POST'])]
    public function createToken(Request $request): Response
    {
        $form = $this->createForm(ApiTokenFormType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $expires = $form->get('expires')->getData();
            $plain = ApiToken::generatePlainToken();
            $this->em->persist(new ApiToken($this->user(), $form->get('name')->getData(), $plain, $expires === 'never' ? null : new \DateTimeImmutable($expires)));
            $this->em->flush();
            // Shown once on the next page, never stored in plain text
            $request->getSession()->set('new_api_token', $plain);
        } else {
            $this->addFlash('error', 'Give the token a name.');
        }

        return $this->redirectToRoute('account', ['_fragment' => 'tokens']);
    }

    #[Route('/tokens/{id}/revoke', name: 'account_token_revoke', methods: ['POST'])]
    public function revokeToken(ApiToken $token, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $token);
        if ($this->isCsrfTokenValid('token-revoke-'.$token->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($token);
            $this->em->flush();
            $this->addFlash('success', 'Token revoked.');
        }

        return $this->redirectToRoute('account', ['_fragment' => 'tokens']);
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
