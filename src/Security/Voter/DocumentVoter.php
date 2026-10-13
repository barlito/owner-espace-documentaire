<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Document;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Second layer of client isolation, behind the client-scoped repository lookup.
 *
 * @extends Voter<string, Document>
 */
final class DocumentVoter extends Voter
{
    public const string VIEW = 'DOCUMENT_VIEW';
    public const string RETRY = 'DOCUMENT_RETRY';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::RETRY], true) && $subject instanceof Document;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        \assert($subject instanceof Document);

        return match ($attribute) {
            self::VIEW => $user->isAdmin() || $this->belongsToUserClient($subject, $user),
            self::RETRY => $user->isAdmin(),
            default => false,
        };
    }

    private function belongsToUserClient(Document $document, User $user): bool
    {
        // Compare ids: entities may come from different units of work.
        return $user->getClient()?->getId()->equals($document->getClient()->getId()) ?? false;
    }
}
