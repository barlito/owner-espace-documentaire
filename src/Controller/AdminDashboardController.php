<?php

declare(strict_types=1);

namespace App\Controller;

use App\DocumentList\Page;
use App\Entity\User;
use App\Enum\FailureReason;
use App\Repository\ClientRepository;
use App\Repository\DocumentRepository;
use App\Supervision\TodoFilters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Supervision: global counts per status and the "À traiter" list, the failed analyses of every client.
 */
#[IsGranted(User::ROLE_ADMIN)]
final class AdminDashboardController extends AbstractController
{
    private const int PER_PAGE = 25;

    #[Route('/admin', name: 'admin_dashboard')]
    public function __invoke(Request $request, DocumentRepository $documents, ClientRepository $clients): Response
    {
        $filters = TodoFilters::fromRequest($request);

        $clientChoices = $documents->failedCountsByClient();
        $selectedClient = null;
        if (null !== $filters->clientId) {
            $known = array_values(array_filter($clientChoices, static fn (array $choice): bool => $choice['id']->equals($filters->clientId)));
            // A client whose failures were all handled since the link was shared stays a valid, empty filter;
            // an id that matches no client is dropped like any malformed value.
            $selectedClient = $known[0]['name'] ?? $clients->find($filters->clientId)?->getName();
            if (null === $selectedClient) {
                $filters = $filters->without(TodoFilters::PARAM_CLIENT);
            } elseif ([] === $known) {
                $clientChoices[] = ['id' => $filters->clientId, 'name' => $selectedClient, 'count' => 0];
                usort($clientChoices, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
            }
        }

        $page = $documents->searchFailed($filters, Page::parseNumber($request->query->all()['page'] ?? null), self::PER_PAGE);

        return $this->render('admin/dashboard.html.twig', [
            'statusCounts' => $documents->countByStatus(),
            'filters' => $filters,
            'page' => $page,
            'clientChoices' => $clientChoices,
            'selectedClient' => $selectedClient,
            'reasons' => FailureReason::cases(),
        ]);
    }
}
