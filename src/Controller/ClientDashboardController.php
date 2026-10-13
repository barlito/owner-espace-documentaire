<?php

declare(strict_types=1);

namespace App\Controller;

use App\DocumentList\DocumentFilters;
use App\DocumentList\Page;
use App\DocumentList\PeriodFilter;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Clock\now;

/**
 * "Mon dossier": every document of the client, searchable, filterable and paginated.
 */
#[IsGranted(User::ROLE_CLIENT)]
final class ClientDashboardController extends AbstractController
{
    private const int PER_PAGE = 25;

    #[Route('/espace', name: 'client_dashboard')]
    public function __invoke(#[CurrentUser] User $user, Request $request, DocumentRepository $documents): Response
    {
        // Guaranteed by User::forClient(), but never render a client space without a client.
        $client = $user->getClient() ?? throw $this->createAccessDeniedException('Client account without client.');

        $filters = DocumentFilters::fromRequest($request);
        $page = $documents->searchForClient($client, $filters, Page::parseNumber($request->query->all()['page'] ?? null), self::PER_PAGE);
        $statusCounts = $documents->countByStatusForClient($client);

        return $this->render('client/dashboard.html.twig', [
            'client' => $client,
            'filters' => $filters,
            'page' => $page,
            'statusCounts' => $statusCounts,
            // Whatever the filters, a client without any document sees the invitation to upload.
            'folderEmpty' => 0 === array_sum(array_column($statusCounts, 'count')),
            'typeGroups' => DocumentType::groupedByFamily(),
            'periods' => PeriodFilter::suggestions(now(), $filters->period),
        ]);
    }
}
