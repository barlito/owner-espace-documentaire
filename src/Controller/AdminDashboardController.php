<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ClientRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
final class AdminDashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard')]
    public function __invoke(ClientRepository $clients): Response
    {
        return $this->render('admin/dashboard.html.twig', ['rows' => $clients->findAllWithDocumentCount()]);
    }
}
