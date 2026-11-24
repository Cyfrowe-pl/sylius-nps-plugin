<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Locale\Provider\CachedLocaleCollectionProvider;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Builds the smallest shop the settings form needs. Every record is looked up by its code first,
 * because CI loads the Sylius fixtures into the same database and they claim the same codes.
 * The locale list the form offers is cached in cache.app, so it is dropped here as well.
 */
final class ShopFixture
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
    ) {
    }

    public function createChannel(
        string $code = 'TEST_CHANNEL',
        string $name = 'Test channel',
        string $hostname = 'localhost',
    ): ChannelInterface {
        $this->cache->delete(CachedLocaleCollectionProvider::LOCALES_CACHE_KEY);

        $channel = $this->findOneByCode(Channel::class, $code);

        if (null !== $channel) {
            return $channel;
        }

        $locale = $this->findOrCreateLocale('pl_PL');
        $currency = $this->findOrCreateCurrency('PLN');

        $channel = new Channel();
        $channel->setCode($code);
        $channel->setName($name);
        $channel->setHostname($hostname);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setTaxCalculationStrategy('order_items_based');

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<ShippingMethodInterface>
     */
    public function createShippingMethods(array $codes): array
    {
        $zone = $this->findOrCreateZone('TEST_ZONE');
        $methods = [];

        foreach ($codes as $code) {
            $methods[] = $this->findOrCreateShippingMethod($code, $zone);
        }

        $this->entityManager->flush();

        return $methods;
    }

    private function findOrCreateShippingMethod(string $code, ZoneInterface $zone): ShippingMethodInterface
    {
        $shippingMethod = $this->findOneByCode(ShippingMethod::class, $code);

        if (null !== $shippingMethod) {
            return $shippingMethod;
        }

        $shippingMethod = new ShippingMethod();
        $shippingMethod->setCode($code);
        $shippingMethod->setCurrentLocale('pl_PL');
        $shippingMethod->setFallbackLocale('pl_PL');
        $shippingMethod->setName(ucfirst(str_replace('_', ' ', $code)));
        $shippingMethod->setZone($zone);
        $shippingMethod->setCalculator('flat_rate');
        $shippingMethod->setConfiguration(['TEST_CHANNEL' => ['amount' => 0]]);

        $this->entityManager->persist($shippingMethod);

        return $shippingMethod;
    }

    private function findOrCreateZone(string $code): ZoneInterface
    {
        $zone = $this->findOneByCode(Zone::class, $code);

        if (null !== $zone) {
            return $zone;
        }

        $zone = new Zone();
        $zone->setCode($code);
        $zone->setName('Test zone');
        $zone->setType('country');

        $this->entityManager->persist($zone);

        return $zone;
    }

    private function findOrCreateLocale(string $code): LocaleInterface
    {
        $locale = $this->findOneByCode(Locale::class, $code);

        if (null !== $locale) {
            return $locale;
        }

        $locale = new Locale();
        $locale->setCode($code);

        $this->entityManager->persist($locale);

        return $locale;
    }

    private function findOrCreateCurrency(string $code): CurrencyInterface
    {
        $currency = $this->findOneByCode(Currency::class, $code);

        if (null !== $currency) {
            return $currency;
        }

        $currency = new Currency();
        $currency->setCode($code);

        $this->entityManager->persist($currency);

        return $currency;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T|null
     */
    private function findOneByCode(string $className, string $code): ?object
    {
        return $this->entityManager->getRepository($className)->findOneBy(['code' => $code]);
    }
}
