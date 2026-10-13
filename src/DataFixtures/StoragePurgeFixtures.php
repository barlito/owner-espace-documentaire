<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use League\Flysystem\FilesystemOperator;

/**
 * The fixtures bundle purges the database but knows nothing about stored files, which would be left orphaned.
 * Goes through Flysystem so that only the documents storage is ever touched.
 */
final class StoragePurgeFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(private readonly FilesystemOperator $documentsStorage)
    {
    }

    public static function getGroups(): array
    {
        return ['demo', 'volume'];
    }

    public function load(ObjectManager $manager): void
    {
        foreach ($this->documentsStorage->listContents('', false) as $entry) {
            $entry->isDir()
                ? $this->documentsStorage->deleteDirectory($entry->path())
                : $this->documentsStorage->delete($entry->path());
        }
    }
}
