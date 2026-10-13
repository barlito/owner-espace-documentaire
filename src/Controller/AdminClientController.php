<?php

declare(strict_types=1);

namespace App\Controller;

use App\DocumentList\DocumentFilters;
use App\DocumentList\Page;
use App\DocumentList\PeriodFilter;
use App\DocumentList\SearchTerm;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Repository\ClientRepository;
use App\Repository\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * The clients of OWNER, and one client's folder as the administrator sees it.
 */
#[IsGranted(User::ROLE_ADMIN)]
final class AdminClientController extends AbstractController
{
    private const int PER_PAGE = 25;

    #[Route('/admin/clients', name: 'admin_clients')]
    public function index(Request $request, ClientRepository $clients): Response
    {
        $search = SearchTerm::clean(\is_string($term = $request->query->all()['q'] ?? null) ? $term : null);

        return $this->render('admin/clients.html.twig', [
            'search' => $search,
            'page' => $clients->searchWithStatusCounts($search, Page::parseNumber($request->query->all()['page'] ?? null), self::PER_PAGE),
        ]);
    }

    /**
     * Same search, filters and pagination as the client's own "Mon dossier", on the client the administrator picked.
     */
    #[Route('/admin/clients/{id}', name: 'admin_client_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id, Request $request, ClientRepository $clients, DocumentRepository $documents): Response
    {
        $client = $clients->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException();

        $filters = DocumentFilters::fromRequest($request);
        $statusCounts = $documents->countByStatusForClient($client);

        return $this->render('admin/client/show.html.twig', [
            'client' => $client,
            'filters' => $filters,
            'page' => $documents->searchForClient($client, $filters, Page::parseNumber($request->query->all()['page'] ?? null), self::PER_PAGE),
            'statusCounts' => $statusCounts,
            'folderEmpty' => 0 === array_sum(array_column($statusCounts, 'count')),
            'typeGroups' => DocumentType::groupedByFamily(),
            'periods' => PeriodFilter::suggestions(now(), $filters->period),
        ]);
    }
}
