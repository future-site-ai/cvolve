<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AiProposal;
use App\Entity\Cv;
use App\Entity\ExportJob;
use App\Entity\Template;
use App\Entity\User;
use App\Security\OwnerVoter;
use App\Security\TemplateVoter;
use App\Service\Cv\ProfileSerializer;
use App\Service\Cv\ProposalException;
use App\Service\Cv\ProposalManager;
use Cvolve\TemplateKit\SectionTypes;
use App\Service\Export\ExportService;
use Cvolve\TemplateKit\Import\ConversionException;
use App\Service\Template\TemplateInstaller;
use App\Service\Tool\CvToolbox;
use App\Service\Tool\ToolContext;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST API v1. Authenticated with personal API tokens (Authorization: Bearer cvk_...).
 */
#[Route('/api/v1', format: 'json')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CvToolbox $toolbox,
    ) {
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    #[OA\Tag(name: 'Account')]
    #[OA\Response(response: 200, description: 'The authenticated user')]
    public function me(): JsonResponse
    {
        $user = $this->user();

        return $this->json(['id' => $user->getId(), 'email' => $user->getEmail(), 'name' => $user->getFullName(), 'roles' => $user->getRoles()]);
    }

    // ---------------------------------------------------------------- profile

    #[Route('/profile', name: 'api_profile_get', methods: ['GET'])]
    #[OA\Tag(name: 'Master profile')]
    #[OA\Response(response: 200, description: 'The master profile as CVolve profile JSON (with ids)')]
    public function profile(ProfileSerializer $serializer): JsonResponse
    {
        $profile = $this->user()->getMasterProfile();

        return $this->json($profile !== null ? $serializer->toArray($profile, true) : null);
    }

    #[Route('/profile', name: 'api_profile_put', methods: ['PUT'])]
    #[OA\Tag(name: 'Master profile')]
    #[OA\RequestBody(description: 'CVolve profile JSON. Sections present in the body replace the existing ones (or are appended with ?mode=merge).', required: true, content: new OA\JsonContent(type: 'object'))]
    #[OA\Parameter(name: 'mode', in: 'query', schema: new OA\Schema(type: 'string', enum: ['replace', 'merge']))]
    #[OA\Response(response: 200, description: 'The updated profile')]
    public function updateProfile(Request $request, ProfileSerializer $serializer): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->error('The body must be a JSON object.', 400);
        }
        $profile = $this->user()->getMasterProfile() ?? throw $this->createNotFoundException();
        $sections = array_values(array_intersect(array_keys($data), ['personal', ...array_keys(ProfileSerializer::SECTION_KEYS)]));
        $serializer->apply($profile, $data, $sections, $request->query->getString('mode') === 'merge' ? 'merge' : 'replace');
        $this->em->flush();

        return $this->json($serializer->toArray($profile, true));
    }

    // ---------------------------------------------------------------- CVs

    #[Route('/cvs', name: 'api_cvs', methods: ['GET'])]
    #[OA\Tag(name: 'CVs')]
    #[OA\Response(response: 200, description: 'The user\'s CVs')]
    public function cvs(): JsonResponse
    {
        return $this->json($this->toolbox->call('list_cvs', [], $this->context()));
    }

    #[Route('/cvs', name: 'api_cv_create', methods: ['POST'])]
    #[OA\Tag(name: 'CVs')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['title'], properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'template_id', type: 'integer'),
        new OA\Property(property: 'locale', type: 'string', example: 'en'),
        new OA\Property(property: 'summary_id', type: 'integer'),
        new OA\Property(property: 'experience_ids', type: 'array', items: new OA\Items(type: 'integer')),
    ]))]
    #[OA\Response(response: 201, description: 'The created CV')]
    public function createCv(Request $request): JsonResponse
    {
        $result = $this->toolbox->call('create_cv', $this->body($request), $this->context());

        return isset($result['error']) ? $this->error($result['error'], 422) : $this->json($result, 201);
    }

    #[Route('/cvs/{id}', name: 'api_cv_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[OA\Tag(name: 'CVs')]
    #[OA\Parameter(name: 'locale', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Header and sections of one language version')]
    public function cv(Cv $cv, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);
        $result = $this->toolbox->call('get_cv', array_filter(['cv_id' => $cv->getId(), 'locale' => $request->query->getString('locale')]), $this->context());

        return isset($result['error']) ? $this->error($result['error'], 404) : $this->json($result);
    }

    #[Route('/cvs/{id}', name: 'api_cv_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[OA\Tag(name: 'CVs')]
    #[OA\Response(response: 204, description: 'Deleted')]
    public function deleteCv(Cv $cv): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->em->remove($cv);
        $this->em->flush();

        return new Response(status: 204);
    }

    #[Route('/cvs/{id}/exports', name: 'api_cv_export', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[OA\Tag(name: 'Export')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['format'], properties: [
        new OA\Property(property: 'format', type: 'string', enum: ['pdf', 'docx']),
        new OA\Property(property: 'locale', type: 'string'),
    ]))]
    #[OA\Response(response: 202, description: 'Export job queued')]
    public function exportCv(Cv $cv, Request $request, ExportService $exports): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);
        $body = $this->body($request);
        $format = (string) ($body['format'] ?? 'pdf');
        if (!\in_array($format, ExportJob::FORMATS, true)) {
            return $this->error('format must be "pdf" or "docx".', 422);
        }
        $variant = $cv->getVariant(isset($body['locale']) ? (string) $body['locale'] : null) ?? $cv->getDefaultVariant();

        return $this->json($this->exportJson($exports->request($variant, $format)), 202);
    }

    #[Route('/exports/{id}', name: 'api_export_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[OA\Tag(name: 'Export')]
    #[OA\Response(response: 200, description: 'Export job status')]
    public function export(ExportJob $job): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $job);

        return $this->json($this->exportJson($job));
    }

    #[Route('/exports/{id}/download', name: 'api_export_download', requirements: ['id' => '\d+'], methods: ['GET'], format: 'html')]
    #[OA\Tag(name: 'Export')]
    #[OA\Response(response: 200, description: 'The PDF or DOCX file')]
    public function download(ExportJob $job, ExportService $exports): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $job);
        $path = $exports->filePath($job);
        if ($path === null) {
            return $this->error('The file is not ready yet.', 409);
        }

        return $this->file($path, $job->getDownloadName());
    }

    // ---------------------------------------------------------------- proposals

    #[Route('/proposals', name: 'api_proposals', methods: ['GET'])]
    #[OA\Tag(name: 'Proposals')]
    #[OA\Parameter(name: 'cv_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'applied', 'discarded']))]
    #[OA\Response(response: 200, description: 'Proposals of one CV')]
    public function proposals(Request $request): JsonResponse
    {
        $result = $this->toolbox->call('list_proposals', array_filter(['cv_id' => $request->query->getInt('cv_id'), 'status' => $request->query->getString('status')]), $this->context());

        return isset($result['error']) ? $this->error($result['error'], 404) : $this->json($result);
    }

    #[Route('/proposals/{id}', name: 'api_proposal_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[OA\Tag(name: 'Proposals')]
    #[OA\Response(response: 200, description: 'A proposal with its operations and before/after diff')]
    public function proposal(AiProposal $proposal, ProposalManager $manager): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $proposal);

        return $this->json($this->proposalJson($proposal) + ['operations' => $proposal->getOperations(), 'preview' => $manager->preview($proposal)]);
    }

    #[Route('/proposals/{id}/{action}', name: 'api_proposal_resolve', requirements: ['id' => '\d+', 'action' => 'apply|discard'], methods: ['POST'])]
    #[OA\Tag(name: 'Proposals')]
    #[OA\Response(response: 200, description: 'The proposal after applying or discarding it')]
    public function resolveProposal(AiProposal $proposal, string $action, ProposalManager $manager): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $proposal);
        try {
            $action === 'apply' ? $manager->apply($proposal) : $manager->discard($proposal);
        } catch (ProposalException $e) {
            return $this->error($e->getMessage(), 409);
        }

        return $this->json($this->proposalJson($proposal));
    }

    // ---------------------------------------------------------------- templates

    #[Route('/templates', name: 'api_templates', methods: ['GET'])]
    #[OA\Tag(name: 'Templates')]
    #[OA\Response(response: 200, description: 'Templates available to the user')]
    public function templateList(): JsonResponse
    {
        return $this->json($this->toolbox->call('list_templates', [], $this->context()));
    }

    #[Route('/templates/import', name: 'api_template_import', methods: ['POST'])]
    #[OA\Tag(name: 'Templates')]
    #[OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(properties: [
        new OA\Property(property: 'package', type: 'string', format: 'binary', description: '.cvolve, .zip (HTML or JSON Resume theme), .html, .twig, .mustache or .hbs'),
        new OA\Property(property: 'scope', type: 'string', enum: ['private', 'preset'], description: 'preset requires ROLE_ADMIN'),
    ])))]
    #[OA\Response(response: 201, description: 'Installed template')]
    #[OA\Response(response: 422, description: 'Validation errors')]
    public function importTemplate(Request $request, TemplateInstaller $installer): JsonResponse
    {
        $file = $request->files->get('package');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error('Upload the .cvolve file in the "package" field.', 400);
        }
        $preset = $request->request->get('scope') === 'preset';
        if ($preset && !$this->isGranted('ROLE_ADMIN')) {
            return $this->error('Only administrators can install presets.', 403);
        }
        try {
            $result = $installer->importFile($file->getPathname(), (string) $file->getClientOriginalName(), $preset ? null : $this->user());
        } catch (ConversionException $e) {
            return $this->json(['error' => 'The template could not be imported.', 'errors' => $e->errors, 'warnings' => $e->warnings], 422);
        }
        $template = $result['template'];

        return $this->json(['id' => $template->getId(), 'slug' => $template->getSlug(), 'name' => $template->getName(), 'preset' => $template->isPreset(), 'format' => $result['format'], 'warnings' => $result['warnings']], 201);
    }

    #[Route('/templates/{id}/export', name: 'api_template_export', requirements: ['id' => '\d+'], methods: ['GET'], format: 'html')]
    #[OA\Tag(name: 'Templates')]
    #[OA\Response(response: 200, description: 'The .cvolve package')]
    public function exportTemplate(Template $template, TemplateInstaller $installer): Response
    {
        $this->denyAccessUnlessGranted(TemplateVoter::USE, $template);
        $response = new BinaryFileResponse($installer->export($template));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $template->getSlug().'.cvolve');
        $response->deleteFileAfterSend();

        return $response;
    }

    // ---------------------------------------------------------------- tools

    #[Route('/tools', name: 'api_tools', methods: ['GET'])]
    #[OA\Tag(name: 'Tools')]
    #[OA\Response(response: 200, description: 'Every CV tool with its JSON Schema (the same tools the chat and MCP server use)')]
    public function tools(): JsonResponse
    {
        $tools = [];
        foreach ($this->toolbox->definitions() as $tool) {
            $tools[] = ['name' => $tool->name, 'description' => $tool->description, 'uses_ai' => $tool->usesAi, 'input_schema' => $tool->inputSchema()];
        }

        return $this->json(['tools' => $tools, 'section_types' => array_keys(SectionTypes::TYPES), 'locales' => SectionTypes::LOCALES]);
    }

    #[Route('/tools/{name}', name: 'api_tool_call', requirements: ['name' => '[a-z_]+'], methods: ['POST'])]
    #[OA\Tag(name: 'Tools')]
    #[OA\RequestBody(description: 'Tool arguments', content: new OA\JsonContent(type: 'object'))]
    #[OA\Response(response: 200, description: 'Tool result')]
    #[OA\Response(response: 422, description: 'The tool reported an error')]
    public function callTool(string $name, Request $request): JsonResponse
    {
        if ($this->toolbox->get($name) === null) {
            return $this->error(sprintf('Unknown tool "%s".', $name), 404);
        }
        $result = $this->toolbox->call($name, $this->body($request), $this->context());

        return $this->json($result, isset($result['error']) ? 422 : 200);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function exportJson(ExportJob $job): array
    {
        return [
            'id' => $job->getId(),
            'format' => $job->getFormat(),
            'status' => $job->getStatus(),
            'error' => $job->getError(),
            'download_url' => $job->getStatus() === ExportJob::STATUS_DONE ? $this->generateUrl('api_export_download', ['id' => $job->getId()], 0) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function proposalJson(AiProposal $p): array
    {
        return [
            'id' => $p->getId(),
            'cv_id' => $p->getCv()->getId(),
            'locale' => $p->getVariant()?->getLocale(),
            'title' => $p->getTitle(),
            'description' => $p->getDescription(),
            'status' => $p->getStatus(),
            'source' => $p->getSource(),
            'created_at' => $p->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true);

        return \is_array($data) ? $data : [];
    }

    private function context(): ToolContext
    {
        return new ToolContext($this->user(), 'api');
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
