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
        $client = $user->getClient();
        \assert(null !== $client, 'A client account always belongs to a client.');

        return $this->render('client/dashboard.html.twig', ['client' => $client]);
    }
}
