<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnalysisAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalysisAttempt>
 */
class AnalysisAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalysisAttempt::class);
    }
}
