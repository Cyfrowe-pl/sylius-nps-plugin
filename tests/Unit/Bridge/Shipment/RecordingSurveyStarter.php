<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Unit\Bridge\Shipment;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Survey\SurveyStarterInterface;
use Sylius\Component\Core\Model\OrderInterface;

final class RecordingSurveyStarter implements SurveyStarterInterface
{
    /** @var list<OrderInterface> */
    public array $started = [];

    public function start(OrderInterface $order): ?SurveyInterface
    {
        $this->started[] = $order;

        return null;
    }
}
