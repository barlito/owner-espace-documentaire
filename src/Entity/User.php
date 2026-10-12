<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Login account. A client account belongs to exactly one client;
 * an administrator account belongs to none.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_CLIENT = 'ROLE_CLIENT';
    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 120)]
    private string $fullName;

    #[ORM\Column]
    private string $password = '';

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Client $client;

    /**
     * @param list<string> $roles
     */
    private function __construct(string $email, string $fullName, array $roles, ?Client $client)
    {
        $this->id = Uuid::v7();
        $this->email = mb_strtolower($email);
        $this->fullName = $fullName;
        $this->roles = $roles;
        $this->client = $client;
    }

    public static function forClient(Client $client, string $email, string $fullName): self
    {
        return new self($email, $fullName, [self::ROLE_CLIENT], $client);
    }

    public static function admin(string $email, string $fullName): self
    {
        return new self($email, $fullName, [self::ROLE_ADMIN], null);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
