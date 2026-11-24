<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Repository;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;

class SurveyRepository extends EntityRepository implements SurveyRepositoryInterface
{
    /**
     * Whitelisted on purpose: the field name reaches DQL, so it may never come straight from a grid
     * configuration or a request.
     */
    private const FILTERABLE_FIELDS = ['channelCode', 'shippingMethodCode', 'shippingMethodName'];

    /**
     * The values a column actually holds, for the admin filters. Read from the records rather than
     * from the shop's catalogue, so a shipping method retired last year stays filterable in the
     * history that mentions it.
     *
     * @return array<string, string>
     */
    public function findDistinctValues(string $field): array
    {
        if (!in_array($field, self::FILTERABLE_FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf('Refusing to read distinct values of "%s".', $field));
        }

        // The empty walker list opts out of Sylius' OrderByIdentifierSqlWalker, which appends
        // ORDER BY <root>.id to every select statement. MySQL rejects that ordering here, because
        // DISTINCT has collapsed the identifier away.
        /** @var list<array<string, string|null>> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select(sprintf('DISTINCT o.%s AS value', $field))
            ->from($this->getClassName(), 'o')
            ->andWhere(sprintf('o.%s IS NOT NULL', $field))
            ->getQuery()
            ->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [])
            ->getScalarResult()
        ;

        $values = [];

        foreach ($rows as $row) {
            $value = $row['value'] ?? null;

            if (null !== $value && '' !== $value) {
                $values[$value] = $value;
            }
        }

        ksort($values);

        return $values;
    }

    public function createAdminListQueryBuilder(): QueryBuilder
    {
        // One place builds the joins, because both the admin grid and the summary query above it
        // have to run against the same set of records - the summary is meaningless otherwise.
        // The customer is fetch-joined for the e-mail column: the plugin stores no address of its
        // own, so without this every row on the page would cost another query.
        return $this->createQueryBuilder('o')
            ->addSelect('customer')
            ->leftJoin('o.customer', 'customer')
        ;
    }

    public function findAwaitingCorrectionNotice(
        string $channelCode,
        int $threshold,
        \DateTimeInterface $linkAliveAt,
        int $maxAttempts,
        int $limit,
    ): array {
        /** @var list<SurveyInterface> $surveys */
        $surveys = $this->alreadyAlertedAbout($channelCode, $linkAliveAt, $maxAttempts, $limit)
            ->andWhere('o.score > :threshold')
            ->andWhere('o.criticalCorrectionNotifiedAt IS NULL')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult()
        ;

        return $surveys;
    }

    public function findAwaitingCommentNotice(
        string $channelCode,
        int $threshold,
        \DateTimeInterface $linkAliveAt,
        int $maxAttempts,
        int $limit,
    ): array {
        /** @var list<SurveyInterface> $surveys */
        $surveys = $this->alreadyAlertedAbout($channelCode, $linkAliveAt, $maxAttempts, $limit)
            ->andWhere('o.score <= :threshold')
            ->andWhere('o.comment IS NOT NULL')
            // Later than the alert, so a record alerted about with its comment already in is not
            // told about twice. The form cannot be submitted again, so this moment happens once.
            ->andWhere('o.completedAt > o.criticalNotifiedAt')
            ->andWhere('o.criticalCommentNotifiedAt IS NULL')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult()
        ;

        return $surveys;
    }

    private function alreadyAlertedAbout(
        string $channelCode,
        \DateTimeInterface $linkAliveAt,
        int $maxAttempts,
        int $limit,
    ): QueryBuilder {
        return $this->createQueryBuilder('o')
            ->addSelect('shopOrder', 'customer')
            ->leftJoin('o.order', 'shopOrder')
            ->leftJoin('o.customer', 'customer')
            ->andWhere('o.channelCode = :channelCode')
            ->andWhere('o.criticalNotifiedAt IS NOT NULL')
            ->andWhere('o.criticalAttempts < :maxAttempts')
            // Spelled the way Survey::isExpired() spells it, an empty date included: the shop's
            // pages treat a record without one as still open, and a query that disagreed with
            // them would drop follow-ups that can genuinely still happen.
            ->andWhere('o.expiresAt IS NULL OR o.expiresAt >= :linkAliveAt')
            ->setParameter('channelCode', $channelCode)
            ->setParameter('maxAttempts', $maxAttempts)
            ->setParameter('linkAliveAt', $linkAliveAt)
            ->orderBy('o.scoreSetAt', 'ASC')
            ->setMaxResults($limit)
        ;
    }

    public function findOneByToken(string $token): ?SurveyInterface
    {
        $survey = $this->createQueryBuilder('o')
            ->andWhere('o.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        assert(null === $survey || $survey instanceof SurveyInterface);

        return $survey;
    }

    public function findOneByOrder(OrderInterface $order): ?SurveyInterface
    {
        $survey = $this->createQueryBuilder('o')
            ->andWhere('o.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        assert(null === $survey || $survey instanceof SurveyInterface);

        return $survey;
    }

    public function findDueForSending(string $channelCode, \DateTimeInterface $qualifiedBefore, int $limit): array
    {
        /** @var list<SurveyInterface> $surveys */
        $surveys = $this->createQueryBuilder('o')
            // The sender reads the address and the first name off the order, so fetch both
            // up front rather than paying two extra queries per record.
            ->addSelect('shopOrder', 'customer')
            ->leftJoin('o.order', 'shopOrder')
            ->leftJoin('o.customer', 'customer')
            ->andWhere('o.status = :status')
            ->andWhere('o.channelCode = :channelCode')
            ->andWhere('o.qualifiedAt <= :qualifiedBefore')
            ->setParameter('status', SurveyStatus::Pending)
            ->setParameter('channelCode', $channelCode)
            ->setParameter('qualifiedBefore', $qualifiedBefore)
            ->orderBy('o.qualifiedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;

        return $surveys;
    }

    public function hasBeenSentToCustomerSince(CustomerInterface $customer, \DateTimeInterface $since): bool
    {
        $count = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.customer = :customer')
            ->andWhere('o.sentAt >= :since')
            ->setParameter('customer', $customer)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult()
        ;

        return (int) $count > 0;
    }

    public function findAwaitingCriticalNotification(
        string $channelCode,
        int $threshold,
        \DateTimeInterface $scoredBefore,
        int $maxAttempts,
        int $limit,
    ): array {
        /** @var list<SurveyInterface> $surveys */
        $surveys = $this->createQueryBuilder('o')
            // The alert carries the order and the customer, so both are needed for every row.
            ->addSelect('shopOrder', 'customer')
            ->leftJoin('o.order', 'shopOrder')
            ->leftJoin('o.customer', 'customer')
            ->andWhere('o.channelCode = :channelCode')
            ->andWhere('o.score IS NOT NULL')
            ->andWhere('o.score <= :threshold')
            ->andWhere('o.criticalNotifiedAt IS NULL')
            ->andWhere('o.completedAt IS NOT NULL OR o.scoreSetAt <= :scoredBefore')
            ->andWhere('o.criticalAttempts < :maxAttempts')
            ->setParameter('channelCode', $channelCode)
            ->setParameter('threshold', $threshold)
            ->setParameter('scoredBefore', $scoredBefore)
            ->setParameter('maxAttempts', $maxAttempts)
            ->orderBy('o.scoreSetAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;

        return $surveys;
    }
}
