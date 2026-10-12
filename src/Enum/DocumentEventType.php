<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Timeline event types. Events store codes, never sentences:
 * the wording is produced at display time, adapted to the reader.
 */
enum DocumentEventType: string
{
    case DocumentReceived = 'document_received';
    case AnalysisStarted = 'analysis_started';
    case AnalysisSucceeded = 'analysis_succeeded';
    case AnalysisFailed = 'analysis_failed';
    case AnalysisRetried = 'analysis_retried';
}
