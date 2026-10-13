<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ReconcileAnalyses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(name: 'app:analysis:reconcile', description: 'Runs the analysis reconciliation once: fails stuck analyses and queues the ones never sent')]
final class ReconcileAnalysesCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        // Goes through the bus (not straight to the handler) to get the same database transaction as the scheduler.
        $envelope = $this->bus->dispatch(new ReconcileAnalyses());

        /** @var array{interrupted: int, queued: int} $report */
        $report = $envelope->last(HandledStamp::class)?->getResult();
        $io->success(\sprintf('%d analysis(es) interrupted, %d queued.', $report['interrupted'], $report['queued']));

        return Command::SUCCESS;
    }
}
