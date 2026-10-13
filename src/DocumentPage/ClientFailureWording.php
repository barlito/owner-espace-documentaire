<?php

declare(strict_types=1);

namespace App\DocumentPage;

use App\Enum\FailureReason;

/**
 * What a client is told about a failed analysis: plain language, no technical detail, and what they can do about it.
 * The back office will word the same reasons for the team (technical cause, admin names) in its own class.
 */
final class ClientFailureWording
{
    /**
     * One short phrase for the timeline.
     */
    public function cause(FailureReason $reason): string
    {
        return match ($reason) {
            FailureReason::UnreadableFile => 'Le fichier est illisible.',
            FailureReason::UnsupportedFormat => 'Le contenu de ce fichier ne peut pas être analysé automatiquement.',
            FailureReason::Interrupted => 'Le traitement a été interrompu.',
            FailureReason::Unknown => 'Un problème est survenu pendant l\'analyse.',
        };
    }

    /**
     * Message of the analysis block: what happened and what happens next.
     */
    public function explanation(FailureReason $reason): string
    {
        return match ($reason) {
            FailureReason::UnreadableFile => 'Le fichier semble illisible. Déposez une version plus nette (scan à plat, bien éclairé, texte lisible).',
            default => 'L\'analyse n\'a pas pu aboutir. L\'équipe OWNER a été prévenue et relancera l\'analyse : vous n\'avez rien à faire.',
        };
    }

    /**
     * Only an unreadable file is the client's to fix; for the other causes the team takes over.
     */
    public function suggestsNewUpload(FailureReason $reason): bool
    {
        return FailureReason::UnreadableFile === $reason;
    }
}
