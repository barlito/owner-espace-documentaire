<?php

declare(strict_types=1);

namespace App\Analysis;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Consumed by the worker as the "scheduler_analysis" transport.
 */
#[AsSchedule('analysis')]
final readonly class AnalysisSchedule implements ScheduleProviderInterface
{
    public function __construct(private ReconcileSettings $settings)
    {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()->add(RecurringMessage::every($this->settings->interval, new ReconcileAnalyses()));
    }
}
