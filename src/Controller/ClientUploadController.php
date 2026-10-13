<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\DocumentType;
use App\Upload\DocumentUploader;
use App\Upload\UploadPolicy;
use App\Upload\UploadRejected;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_CLIENT)]
final class ClientUploadController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'upload';

    public function __construct(
        private readonly DocumentUploader $uploader,
        private readonly UploadPolicy $policy,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/espace/depot', name: 'client_upload', methods: ['GET'])]
    public function page(): Response
    {
        return $this->render('client/upload.html.twig', [
            'policy' => $this->policy,
            'typeGroups' => DocumentType::groupedByFamily(),
        ]);
    }

    /**
     * One file per request: used by the Stimulus controller, which needs per-file progress and outcome.
     */
    #[Route('/espace/depot/fichier', name: 'client_upload_file', methods: ['POST'])]
    public function receiveFile(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyUnlessValidCsrfToken($request);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['error' => 'Aucun fichier reçu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $document = $this->uploader->upload($file, $user, $this->type($request));
        } catch (UploadRejected $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'id' => $document->getId()->toRfc4122(),
            'name' => $document->getOriginalFilename(),
            'status_label' => $document->getStatus()->label(),
            // Server-rendered (escaped by Twig): the page shows it in the file's line, where live updates replace it.
            'status_html' => $this->renderView('client/_upload_status.html.twig', ['document' => $document]),
            // Document pages come later: the dashboard lists the received documents for now.
            'url' => $this->generateUrl('client_dashboard'),
        ], Response::HTTP_CREATED);
    }

    /**
     * Fallback without JavaScript: classic multipart form, each file is processed independently.
     */
    #[Route('/espace/depot', name: 'client_upload_batch', methods: ['POST'])]
    public function receiveBatch(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        // When post_max_size is exceeded PHP drops the whole body: no token, no files.
        if ($request->server->getInt('CONTENT_LENGTH') > 0 && 0 === $request->request->count() && 0 === $request->files->count()) {
            $this->addFlash('warning', \sprintf(
                'L\'envoi dépasse la taille maximale autorisée (%d Mo au total sans JavaScript). Envoyez moins de fichiers à la fois.',
                UploadPolicy::MAX_BATCH_SIZE_MB,
            ));

            return $this->redirectToRoute('client_upload');
        }

        $this->denyUnlessValidCsrfToken($request);

        try {
            $type = $this->type($request);
        } catch (UploadRejected $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('client_upload');
        }

        $files = array_values(array_filter(
            $request->files->all('files'),
            static fn (mixed $file): bool => $file instanceof UploadedFile && \UPLOAD_ERR_NO_FILE !== $file->getError(),
        ));

        if ([] === $files) {
            $this->addFlash('warning', 'Aucun fichier sélectionné.');

            return $this->redirectToRoute('client_upload');
        }

        $received = 0;
        foreach ($files as $index => $file) {
            $name = $file->getClientOriginalName();

            if ($index >= UploadPolicy::MAX_FILES_PER_BATCH) {
                $this->addFlash('warning', \sprintf('%s : au-delà de %d fichiers par envoi.', $name, UploadPolicy::MAX_FILES_PER_BATCH));
                continue;
            }

            try {
                $this->uploader->upload($file, $user, $type);
                ++$received;
            } catch (UploadRejected $e) {
                $this->addFlash('warning', \sprintf('%s : %s', $name, $e->getMessage()));
            } catch (\Throwable $e) {
                // Storage or database failure: the next files would most likely fail the same way
                // (a failed transaction closes the entity manager), so the batch stops here.
                $this->logger->error('Batch upload interrupted by a technical error.', ['exception' => $e]);
                $this->addFlash('danger', \sprintf(
                    'Une erreur technique a interrompu l\'envoi : %s et les fichiers suivants n\'ont pas été enregistrés. Réessayez dans quelques instants.',
                    $name,
                ));
                break;
            }
        }

        if ($received > 0) {
            $this->addFlash('success', 1 === $received
                ? '1 pièce reçue. Son analyse va démarrer.'
                : \sprintf('%d pièces reçues. Leur analyse va démarrer.', $received));
        }

        return $this->redirectToRoute($received > 0 ? 'client_dashboard' : 'client_upload');
    }

    /**
     * The optional nature chosen by the client; an unknown value is refused rather than silently dropped.
     *
     * @throws UploadRejected
     */
    private function type(Request $request): ?DocumentType
    {
        $value = $request->request->getString('type');
        if ('' === $value) {
            return null;
        }

        return DocumentType::tryFrom($value) ?? throw new UploadRejected('Nature de pièce inconnue.');
    }

    private function denyUnlessValidCsrfToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
