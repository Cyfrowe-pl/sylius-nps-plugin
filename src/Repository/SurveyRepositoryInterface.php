<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Repository;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

interface SurveyRepositoryInterface extends RepositoryInterface
{
    /**
     * The values a column actually holds, for the admin filters.
     *
     * @return array<string, string>
     */
    public function findDistinctValues(string $field): array;

    /** Shared by the admin grid and the summary above it, so both see the same records. */
    public function createAdminListQueryBuilder(): QueryBuilder;

    public function findOneByToken(string $token): ?SurveyInterface;

    public function findOneByOrder(OrderInterface $order): ?SurveyInterface;

    /**
     * Records qualified long enough ago to be sent, for a channel whose send hour has come.
     *
     * @return list<SurveyInterface>
     */
    public function findDueForSending(string $channelCode, \DateTimeInterface $qualifiedBefore, int $limit): array;

    /**
     * True when the customer already received a survey within the cooldown window.
     */
    public function hasBeenSentToCustomerSince(CustomerInterface $customer, \DateTimeInterface $since): bool;

    /**
     * Critical scores still awaiting an alert: either the form is in, or the grace window has passed.
     *
     * Records whose alert has failed $maxAttempts times in a row are left out, so one permanently
     * undeliverable address cannot fill the log on every run. They keep an empty
     * criticalNotifiedAt, so the panel still shows them as never alerted about, and zeroing the
     * counter puts them back in the queue.
     *
     * @return list<SurveyInterface>
     */
    public function findAwaitingCriticalNotification(
        string $channelCode,
        int $threshold,
        \DateTimeInterface $scoredBefore,
        int $maxAttempts,
        int $limit,
    ): array;

    /**
     * Records already alerted about whose score has since climbed above the threshold. Saying so
     * saves a call to a customer who is no longer unhappy.
     *
     * Only records whose link is still alive at $linkAliveAt are considered: past that moment the
     * shop's own pages refuse both a new score and the form, so no follow-up can arise. That keeps
     * the set this reads bounded by the link validity rather than by the whole history.
     *
     * @return list<SurveyInterface>
     */
    public function findAwaitingCorrectionNotice(
        string $channelCode,
        int $threshold,
        \DateTimeInterface $linkAliveAt,
        int $maxAttempts,
        int $limit,
    ): array;

    /**
     * Records alerted about before the form came in, which has since brought a comment with it.
     * The alert went out saying there was none, so the comment is worth a sentence of its own.
     *
     * Records whose score has also climbed above the threshold are left to the correction notice:
     * one event, one message.
     *
     * Bounded by the link validity in the same way as the correction notice above.
     *
     * @return list<SurveyInterface>
     */
    public function findAwaitingCommentNotice(
        string $channelCode,
        int $threshold,
        \DateTimeInterface $linkAliveAt,
        int $maxAttempts,
        int $limit,
    ): array;
}
