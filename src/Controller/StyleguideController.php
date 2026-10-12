<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * UI components showcase, only available outside production.
 */
final class StyleguideController extends AbstractController
{
    #[Route('/_styleguide', name: 'styleguide', env: ['dev', 'test'])]
    public function __invoke(): Response
    {
        return $this->render('styleguide/index.html.twig');
    }
}
