<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;

abstract class FunctionalTestCase extends WebTestCase
{
    /**
     * A host of its own, because Sylius resolves the channel from the request host and CI loads the
     * Sylius fixtures into the same database. Sharing "localhost" with the fixture channel meant
     * the survey locale was not one the resolved channel had enabled, and every request bounced to
     * the channel's default language instead of reaching the controller.
     */
    protected const HOST = 'nps.test';

    protected KernelBrowser $client;

    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTP_HOST' => self::HOST]);

        // Without this the kernel is rebooted for every request, which would open a second
        // connection that cannot see anything this test has not committed.
        $this->client->disableReboot();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);

        $this->entityManager = $entityManager;
        $this->entityManager->getConnection()->beginTransaction();

        $cache = self::getContainer()->get('cache.app');
        assert($cache instanceof CacheInterface);

        // The survey pages sit in the storefront layout, which needs a channel for the request
        // host - exactly as they will in a real shop.
        (new ShopFixture($this->entityManager, $cache))->createChannel('NPS_FUNCTIONAL', 'NPS functional tests', self::HOST);
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->entityManager->close();

        parent::tearDown();
    }

    protected function logIn(string $localeCode = 'en_US'): void
    {
        $admin = new AdminUser();
        $admin->setEmail('tester@example.com');
        $admin->setUsername('tester');
        $admin->setPlainPassword('tester');
        $admin->setEnabled(true);
        $admin->setLocaleCode($localeCode);

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        assert($admin instanceof UserInterface);
        $this->client->loginUser($admin, 'admin');
    }
}
