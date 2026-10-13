<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[CurrentUser] User $user): RedirectResponse
    {
        return $this->redirectToRoute($user->isAdmin() ? 'admin_dashboard' : 'client_dashboard');
    }
}
