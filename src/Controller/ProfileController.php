<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ImportJob;
use App\Entity\Profile\MasterProfile;
use App\Entity\User;
use App\Form\Profile\PersonalInfoType;
use App\Form\Profile\ProfileSectionType;
use App\Form\ProfileImportFormType;
use App\Security\OwnerVoter;
use App\Service\Ai\AiTextService;
use App\Service\Cv\PhotoStorage;
use App\Service\Cv\ProfileSerializer;
use App\Service\Import\ProfileImporter;
use App\Service\Import\TextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/profile')]
class ProfileController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'profile_edit')]
    #[Route('/{section}', name: 'profile_section', requirements: ['section' => 'summaries|experiences|educations|skillGroups|languages|certifications|projects|publications|customSections'])]
    public function edit(Request $request, PhotoStorage $photos, string $section = 'personal'): Response
    {
        $profile = $this->profile();

        if ($section === 'personal') {
            $form = $this->createFormBuilder(['personal' => $profile->getPersonal()])
                ->add('personal', PersonalInfoType::class, ['label' => false])
                ->add('photo', FileType::class, [
                    'label' => 'Profile photo',
                    'required' => false,
                    'mapped' => false,
                    'help' => 'JPEG, PNG or WebP. It is cropped to a square.',
                    'constraints' => [new Assert\Image(maxSize: '8M')],
                    'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                ])
                ->getForm();
        } else {
            $form = $this->createForm(ProfileSectionType::class, $profile, ['section' => $section]);
        }

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($section === 'personal') {
                $upload = $form->get('photo')->getData();
                if ($upload instanceof UploadedFile) {
                    try {
                        $old = $profile->getPersonal()->getPhoto();
                        $profile->getPersonal()->setPhoto($photos->store($this->user(), $upload->getPathname()));
                        $photos->remove($old);
                    } catch (\InvalidArgumentException $e) {
                        $this->addFlash('error', $e->getMessage());
                    }
                }
            }
            $profile->touch();
            $this->em->flush();
            $this->addFlash('success', 'Master profile saved.');

            return $this->redirectToRoute($section === 'personal' ? 'profile_edit' : 'profile_section', $section === 'personal' ? [] : ['section' => $section]);
        }

        return $this->render('profile/edit.html.twig', [
            'profile' => $profile,
            'section' => $section,
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/photo/delete', name: 'profile_photo_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('photo-delete')]
    public function deletePhoto(PhotoStorage $photos): Response
    {
        $profile = $this->profile();
        $photos->remove($profile->getPersonal()->getPhoto());
        $profile->getPersonal()->setPhoto(null);
        $this->em->flush();

        return $this->redirectToRoute('profile_edit');
    }

    #[Route('/photo', name: 'profile_photo', methods: ['GET'])]
    public function photo(PhotoStorage $photos): Response
    {
        $name = $this->profile()->getPersonal()->getPhoto();
        $path = $name !== null ? $photos->path($name) : null;
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        return $this->file($path, 'photo.jpg', ResponseHeaderBag::DISPOSITION_INLINE);
    }

    #[Route('/import', name: 'profile_import', priority: 10)]
    public function import(Request $request, TextExtractor $extractor, ProfileImporter $importer, AiTextService $ai): Response
    {
        $form = $this->createForm(ProfileImportFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $file */
            $file = $form->get('file')->getData();
            $text = trim((string) $form->get('text')->getData());
            try {
                if ($file instanceof UploadedFile) {
                    $kind = TextExtractor::kindFor($file->getClientOriginalName()) ?? throw new \InvalidArgumentException('Unsupported file type. Use .md, .txt, .json, .pdf or .docx.');
                    $job = $importer->start($this->user(), $kind, $extractor->extract($file->getPathname(), $kind), $file->getClientOriginalName());
                } elseif ($text !== '') {
                    $job = $importer->start($this->user(), str_starts_with($text, '{') ? 'json' : 'markdown', mb_substr($text, 0, TextExtractor::MAX_CHARS));
                } else {
                    throw new \InvalidArgumentException('Upload a file or paste some text.');
                }

                return $this->redirectToRoute('profile_import_show', ['id' => $job->getId()]);
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('profile/import.html.twig', ['form' => $form, 'ai_available' => $ai->isAvailable()]);
    }

    #[Route('/import/{id}', name: 'profile_import_show', requirements: ['id' => '\d+'], priority: 10)]
    public function importShow(ImportJob $job, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $job);

        if ($request->query->getBoolean('status')) {
            return new JsonResponse(['status' => $job->getStatus(), 'busy' => $job->isBusy()]);
        }

        return $this->render('profile/import_review.html.twig', [
            'job' => $job,
            'data' => $job->getResult() ?? [],
            'counts' => ProfileSerializer::counts($job->getResult() ?? []),
            'labels' => ['personal' => 'Personal information', 'summaries' => 'Summaries', 'experiences' => 'Work experience', 'education' => 'Education', 'skills' => 'Skill groups', 'languages' => 'Languages', 'certifications' => 'Certifications', 'projects' => 'Projects', 'publications' => 'Publications', 'custom_sections' => 'Other sections'],
        ]);
    }

    #[Route('/import/{id}/apply', name: 'profile_import_apply', methods: ['POST'], priority: 10)]
    #[IsCsrfTokenValid('import-apply')]
    public function importApply(ImportJob $job, Request $request, ProfileImporter $importer): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $job);
        if ($job->getStatus() !== ImportJob::STATUS_READY) {
            $this->addFlash('error', 'This import is not ready to apply.');

            return $this->redirectToRoute('profile_import_show', ['id' => $job->getId()]);
        }
        $sections = array_values(array_filter((array) $request->request->all('sections'), 'is_string'));
        if ($sections === []) {
            $this->addFlash('error', 'Select at least one part to import.');

            return $this->redirectToRoute('profile_import_show', ['id' => $job->getId()]);
        }
        $importer->apply($job, $this->profile(), $sections, (string) $request->request->get('mode', 'merge'));
        $this->addFlash('success', 'Imported into your master profile. Review each tab and adjust as needed.');

        return $this->redirectToRoute('profile_edit');
    }

    #[Route('/export.json', name: 'profile_export', priority: 10)]
    public function export(ProfileSerializer $serializer): Response
    {
        $response = new JsonResponse($serializer->toArray($this->profile()), 200, [], false);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', 'attachment; filename="cvolve-profile.json"');

        return $response;
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function profile(): MasterProfile
    {
        $user = $this->user();
        if ($user->getMasterProfile() === null) {
            $profile = new MasterProfile();
            $profile->getPersonal()->setFullName($user->getFullName())->setEmail($user->getEmail());
            $user->setMasterProfile($profile);
            $this->em->persist($profile);
            $this->em->flush();
        }

        return $user->getMasterProfile();
    }
}
