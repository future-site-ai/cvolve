<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Profile\MasterProfile;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        #[Autowire('%env(bool:REQUIRE_EMAIL_VERIFICATION)%')]
        private readonly bool $requireVerification,
    ) {
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $profile = new MasterProfile();
            $profile->getPersonal()->setFullName($user->getFullName())->setEmail($user->getEmail());
            $user->setMasterProfile($profile);
            if (!$this->requireVerification) {
                $user->setIsVerified(true);
            }
            $em->persist($user);
            $em->flush();

            $this->sendVerification($user);
            $this->addFlash('success', $this->requireVerification
                ? sprintf('Account created. We sent a confirmation link to %s; open it to log in.', $user->getEmail())
                : 'Account created. You can log in now.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, UserRepository $users): Response
    {
        $user = $users->find((int) $request->query->get('id'));
        if ($user === null) {
            return $this->redirectToRoute('app_register');
        }

        try {
            $this->emailVerifier->handleConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $e) {
            $this->addFlash('error', $e->getReason());

            return $this->redirectToRoute('app_login');
        }

        $this->addFlash('success', 'Your email address is confirmed. Welcome to CVolve!');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/verify/resend', name: 'app_verify_resend', methods: ['POST'])]
    #[IsCsrfTokenValid('verify-resend')]
    public function resend(Request $request, UserRepository $users, #[Autowire(service: 'limiter.verify_resend')] RateLimiterFactory $limiter): Response
    {
        $email = (string) $request->request->get('email');
        if ($limiter->create($request->getClientIp().$email)->consume()->isAccepted()) {
            $user = $users->findOneByEmail($email);
            if ($user !== null && !$user->isVerified()) {
                $this->sendVerification($user);
            }
        }
        // Same answer whether or not the account exists
        $this->addFlash('success', 'If an unconfirmed account exists for this address, a new confirmation link is on its way.');

        return $this->redirectToRoute('app_login');
    }

    private function sendVerification(User $user): void
    {
        if (!$this->requireVerification) {
            return;
        }
        $this->emailVerifier->sendConfirmation($user, (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Confirm your CVolve account')
            ->htmlTemplate('email/confirmation_email.html.twig'));
    }
}
