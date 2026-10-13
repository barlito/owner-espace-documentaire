<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Document;
use App\Message\PublishDocumentUpdate;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The single place that decides a live update is due: whenever a flush inserts a document or changes its status,
 * a PublishDocumentUpdate is queued. Nothing else in the code base has to remember to publish.
 *
 * postFlush runs while the flush's own transaction is "committed", which is only a nesting level when the caller
 * holds an outer transaction (the upload, the doctrine_transaction middleware of the handlers): the message row is
 * then written in that same transaction and disappears with it on rollback, so a change that never happened is
 * never announced. Outside any outer transaction the flush has really committed by then, and a message failing
 * to queue loses only the live update: reloading the page shows the right state.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final class QueueDocumentUpdateListener
{
    /**
     * @var array<string, bool> document id => inserted by the current flush
     */
    private array $pending = [];

    private bool $muted = false;

    /**
     * The "realtime" bus has no doctrine_transaction middleware: that one flushes after dispatching, and
     * flushing from inside postFlush re-enters the unit of work, which is still holding the entities it just wrote.
     */
    public function __construct(
        #[Autowire(service: 'messenger.bus.realtime')]
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * For bulk loads in a short-lived process (demo and volume fixtures): thousands of documents nobody is watching
     * would only fill the queue with updates for the rest of the process's life.
     */
    public function mute(): void
    {
        $this->muted = true;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        // A flush that failed never reached postFlush: forget what it had collected.
        $this->pending = [];
        if ($this->muted) {
            return;
        }

        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Document) {
                $this->pending[$entity->getId()->toRfc4122()] = true;
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Document && isset($unitOfWork->getEntityChangeSet($entity)['status'])) {
                $this->pending[$entity->getId()->toRfc4122()] ??= false;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        // Emptied before dispatching so that nothing dispatched below can send the same updates twice.
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $id => $created) {
            $this->bus->dispatch(new PublishDocumentUpdate(Uuid::fromString($id), $created));
        }
    }

    public function onClear(OnClearEventArgs $args): void
    {
        $this->pending = [];
    }
}
