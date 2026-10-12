<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_CLIENT)]
final class ClientDashboardController extends AbstractController
{
    #[Route('/espace', name: 'client_dashboard')]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        // Guaranteed by User::forClient(), but never render a client space without a client.
        $client = $user->getClient() ?? throw $this->createAccessDeniedException('Client account without client.');

        return $this->render('client/dashboard.html.twig', ['client' => $client]);
    }
}
