<?php

declare(strict_types=1);

namespace App\Controller;

use App\DocumentPage\DocumentFileResponder;
use App\Entity\Document;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Security\Voter\DocumentVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Page of one document and its file. The document is always looked up through the logged-in user's client:
 * another client's document answers 404, exactly like a missing one. The voter is a second layer behind it.
 */
#[IsGranted(User::ROLE_CLIENT)]
#[Route('/espace/pieces/{id}', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
final class ClientDocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentFileResponder $files,
    ) {
    }

    #[Route('', name: 'client_document_show')]
    public function show(string $id, #[CurrentUser] User $user): Response
    {
        $document = $this->findDocument($id, $user);

        return $this->render('client/document/show.html.twig', [
            'document' => $document,
            'client' => $document->getClient(),
        ]);
    }

    #[Route('/fichier', name: 'client_document_file')]
    public function download(string $id, #[CurrentUser] User $user): Response
    {
        return $this->files->download($this->findDocument($id, $user));
    }

    #[Route('/apercu', name: 'client_document_preview')]
    public function preview(string $id, #[CurrentUser] User $user): Response
    {
        return $this->files->preview($this->findDocument($id, $user));
    }

    private function findDocument(string $id, User $user): Document
    {
        $client = $user->getClient() ?? throw $this->createAccessDeniedException('Client account without client.');
        $document = $this->documents->findOneForClient(Uuid::fromString($id), $client) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DocumentVoter::VIEW, $document);

        return $document;
    }
}
