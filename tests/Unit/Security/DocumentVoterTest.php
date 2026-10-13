<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Security\Voter\DocumentVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class DocumentVoterTest extends TestCase
{
    private DocumentVoter $voter;
    private Client $martin;
    private Client $petitsPas;
    private User $paul;
    private User $claire;
    private User $admin;
    private Document $martinDocument;

    protected function setUp(): void
    {
        $this->voter = new DocumentVoter();
        $this->martin = new Client('Boulangerie Martin');
        $this->petitsPas = new Client('Micro-crèche Les Petits Pas');
        $this->paul = User::forClient($this->martin, 'paul@boulangerie-martin.test', 'Paul Martin');
        $this->claire = User::forClient($this->petitsPas, 'claire@lespetitspas.test', 'Claire Dubois');
        $this->admin = User::admin('admin@owner.test', 'Équipe OWNER');
        $this->martinDocument = new Document($this->martin, $this->paul, 'bilan.pdf', 'key-1', 'application/pdf', 1024, str_repeat('a', 64));
    }

    public function testClientCanViewItsOwnDocument(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->paul, DocumentVoter::VIEW));
    }

    public function testClientCanViewItsDocumentThroughAnotherInstanceOfTheSameClient(): void
    {
        $sameClientElsewhere = clone $this->martin;
        $colleague = User::forClient($sameClientElsewhere, 'comptable@boulangerie-martin.test', 'Marie Comptable');

        $this->assertNotSame($this->martin, $sameClientElsewhere);
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($colleague, DocumentVoter::VIEW));
    }

    public function testClientCannotViewAnotherClientsDocument(): void
    {
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->claire, DocumentVoter::VIEW));
    }

    public function testAdminCanViewAnyDocument(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->admin, DocumentVoter::VIEW));
    }

    public function testOnlyAdminCanRetry(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->admin, DocumentVoter::RETRY));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->paul, DocumentVoter::RETRY), 'The owner client must not retry.');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->claire, DocumentVoter::RETRY));
    }

    public function testAnonymousIsDenied(): void
    {
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote(new NullToken(), $this->martinDocument, [DocumentVoter::VIEW]));
    }

    public function testUnsupportedAttributeOrSubjectAbstains(): void
    {
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->admin, 'DOCUMENT_DELETE'));
        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($this->admin), new Client('Other'), [DocumentVoter::VIEW]),
        );
    }

    private function vote(User $user, string $attribute): int
    {
        return $this->voter->vote($this->token($user), $this->martinDocument, [$attribute]);
    }

    private function token(User $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
