<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Client;
use App\Realtime\Topics;
use Twig\Attribute\AsTwigFunction;

final class RealtimeExtension
{
    /**
     * The only topic a client page subscribes to: turbo_stream_from() grants the subscription cookie for exactly this.
     */
    #[AsTwigFunction('client_topic')]
    public function clientTopic(Client $client): string
    {
        return Topics::client($client->getId());
    }
}
