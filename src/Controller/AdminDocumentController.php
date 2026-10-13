<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\AnalysisRetrier;
use App\Analysis\RetryOutcome;
use App\DocumentPage\DocumentFileResponder;
use App\Entity\Document;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Security\Voter\DocumentVoter;
use App\Supervision\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Admin page of any document, its file, and the retry of a failed analysis. The voter is the rule here (an
 * administrator sees every client's documents); the client routes stay scoped to the client and never reach these.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/pieces/{id}', requirements: ['id' => Requirement::UUID])]
final class AdminDocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentFileResponder $files,
    ) {
    }

    #[Route('', name: 'admin_document_show', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $document = $this->findDocument($id);
        $back = ReturnPath::parse($request->query->all()['retour'] ?? null);

        return $this->render('admin/document/show.html.twig', [
            'document' => $document,
            'client' => $document->getClient(),
            'retour' => $back,
            'backPath' => $back ?? $this->generateUrl('admin_dashboard'),
            'backLabel' => ReturnPath::label($back ?? '/admin'),
        ]);
    }

    /**
     * The retry button, alone: it carries a CSRF token, which the worker cannot issue when it pushes a live update.
     * The page asks for it again (a lazy Turbo Frame) every time the document changes.
     */
    #[Route('/actions', name: 'admin_document_actions', methods: ['GET'])]
    public function actions(string $id, Request $request): Response
    {
        // A frame request comes from the page being viewed: its Referer holds the "retour" the page was opened with.
        parse_str((string) parse_url((string) $request->headers->get('Referer'), \PHP_URL_QUERY), $refererQuery);

        return $this->render('admin/document/_actions.html.twig', [
            'document' => $this->findDocument($id),
            'retour' => ReturnPath::parse($request->query->all()['retour'] ?? null) ?? ReturnPath::parse($refererQuery['retour'] ?? null),
        ]);
    }

    #[Route('/relancer', name: 'admin_document_retry', methods: ['POST'])]
    public function retry(string $id, Request $request, #[CurrentUser] User $admin, AnalysisRetrier $retrier): Response
    {
        $document = $this->findDocument($id);
        $this->denyAccessUnlessGranted(DocumentVoter::RETRY, $document);

        $documentId = $document->getId();
        // all(): get() answers 400 to an array, and a forged token deserves the same 403 as any invalid one.
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid('retry-' . $documentId->toRfc4122(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $result = $retrier->retry($documentId, $admin);
        match ($result->outcome) {
            RetryOutcome::Retried => $this->addFlash('success', \sprintf('Analyse relancée (tentative n° %d).', $result->attemptNumber)),
            RetryOutcome::AlreadyHandled => $this->addFlash('info', 'Relance déjà prise en compte.'),
            RetryOutcome::NotFound => throw $this->createNotFoundException(),
        };

        $back = ReturnPath::parse($request->request->all()['retour'] ?? null);

        return $this->redirectToRoute('admin_document_show', ['id' => $documentId->toRfc4122()] + (null === $back ? [] : ['retour' => $back]));
    }

    #[Route('/fichier', name: 'admin_document_file', methods: ['GET'])]
    public function download(string $id): Response
    {
        return $this->files->download($this->findDocument($id));
    }

    #[Route('/apercu', name: 'admin_document_preview', methods: ['GET'])]
    public function preview(string $id): Response
    {
        return $this->files->preview($this->findDocument($id));
    }

    private function findDocument(string $id): Document
    {
        $document = $this->documents->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DocumentVoter::VIEW, $document);

        return $document;
    }
}
