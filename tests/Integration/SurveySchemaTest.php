<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration;

use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Covers the database-level guarantees the model leans on. They live in the schema rather than
 * in PHP, so PHP-level tests would not notice if a migration dropped them.
 */
final class SurveySchemaTest extends IntegrationTestCase
{
    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testRefusesASecondSurveyForTheSameOrder(): void
    {
        $order = $this->factory->createOrder();
        $this->factory->createSurvey($order);
        $this->entityManager->flush();

        $duplicate = new Survey();
        $duplicate->setOrder($order);
        $duplicate->setToken(bin2hex(random_bytes(8)));
        $this->entityManager->persist($duplicate);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testAllowsManySurveysWithoutAnOrder(): void
    {
        foreach (['first', 'second'] as $token) {
            $survey = new Survey();
            $survey->setOrder(null);
            $survey->setToken($token . bin2hex(random_bytes(4)));
            $this->entityManager->persist($survey);
        }

        $this->entityManager->flush();

        self::assertSame(
            2,
            (int) $this->entityManager->getConnection()
                ->fetchOne('SELECT COUNT(*) FROM cyfrowe_nps_survey WHERE order_id IS NULL'),
        );
    }

    public function testRefusesTwoSurveysSharingAToken(): void
    {
        $this->factory->createSurvey()->setToken('shared-token');
        $this->entityManager->flush();

        $duplicate = new Survey();
        $duplicate->setToken('shared-token');
        $this->entityManager->persist($duplicate);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testIndexesTheShippingMethodNameTheAdminFilterReads(): void
    {
        $indexed = $this->entityManager->getConnection()->fetchFirstColumn(
            "SHOW INDEX FROM cyfrowe_nps_survey WHERE Column_name = 'shipping_method_name'",
        );

        self::assertNotEmpty(
            $indexed,
            'Without it the filter reads every row of the table on every render of the list.',
        );
    }

    public function testIndexesTheSendingDateTheAdminFilterReads(): void
    {
        $leading = $this->entityManager->getConnection()->fetchFirstColumn(
            "SHOW INDEX FROM cyfrowe_nps_survey WHERE Column_name = 'sent_at' AND Seq_in_index = 1",
        );

        self::assertNotEmpty(
            $leading,
            'The index on the customer and the date cannot serve a range on the date alone.',
        );
    }

    public function testLeadsTheFollowUpIndexWithTheColumnThatSelects(): void
    {
        $columns = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT column_name FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index
             ORDER BY seq_in_index',
            ['table' => 'cyfrowe_nps_survey', 'index' => 'idx_nps_survey_channel_notified'],
        );

        self::assertSame(
            ['channel_code', 'critical_notified_at', 'expires_at'],
            $columns,
            'Nothing past a range column narrows the scan, so expires_at has to come last.',
        );
    }

    /**
     * Deleting the order must leave the answer behind: the score and the order number are what
     * the report is built from.
     */
    public function testKeepsTheSurveyWhenTheOrderIsDeleted(): void
    {
        $survey = $this->factory->createSurvey();
        $survey->setScore(9);
        $this->entityManager->flush();

        $surveyId = $survey->getId();
        $orderNumber = $survey->getOrderNumber();
        $orderId = $survey->getOrder()?->getId();
        self::assertNotNull($orderId);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM sylius_order WHERE id = :id', ['id' => $orderId]);

        $row = $connection->fetchAssociative(
            'SELECT order_id, order_number, score FROM cyfrowe_nps_survey WHERE id = :id',
            ['id' => $surveyId],
        );

        self::assertIsArray($row);
        self::assertNull($row['order_id']);
        self::assertSame($orderNumber, $row['order_number']);
        self::assertSame(9, (int) $row['score']);
    }

    public function testKeepsTheSurveyWhenTheCustomerIsDeleted(): void
    {
        $survey = $this->factory->createSurvey();
        $this->entityManager->flush();

        $surveyId = $survey->getId();
        $customerId = $survey->getCustomer()?->getId();
        self::assertNotNull($customerId);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM sylius_order WHERE customer_id = :id', ['id' => $customerId]);
        $connection->executeStatement('DELETE FROM sylius_customer WHERE id = :id', ['id' => $customerId]);

        $row = $connection->fetchAssociative(
            'SELECT customer_id FROM cyfrowe_nps_survey WHERE id = :id',
            ['id' => $surveyId],
        );

        self::assertIsArray($row);
        self::assertNull($row['customer_id']);
    }
}
