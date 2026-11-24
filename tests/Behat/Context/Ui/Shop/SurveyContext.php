<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

/**
 * The customer's side of a survey, written as the business describes it.
 *
 * The browser here is the Symfony test client rather than a Mink session: the journey has no
 * JavaScript in it, and driving the kernel directly keeps the scenario deterministic. The
 * assertions come from PHPUnit rather than from the language construct, which php.ini can turn
 * off - a scenario that passes because its checks were compiled out is worse than none.
 */
final class SurveyContext implements Context
{
    private const CHANNEL = 'NPS_BEHAT';

    private const HOST = 'nps.behat';

    private const LOCALE = 'pl_PL';

    private ?Survey $survey = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelBrowser $browser,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Behat, unlike the PHPUnit cases, gets no isolation for free: without this the second
     * scenario walks into the channel and the settings the first one created.
     *
     * @BeforeScenario
     */
    public function startATransaction(): void
    {
        // Left alone, the client boots a fresh kernel for every request, and the new connection
        // knows nothing of the transaction this scenario is holding open - the data set up a
        // moment ago simply vanishes at the second request.
        $this->browser->disableReboot();

        $this->entityManager->getConnection()->beginTransaction();
    }

    /**
     * @AfterScenario
     */
    public function undoEverythingTheScenarioDid(): void
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->entityManager->clear();
    }

    /**
     * @Given a survey has been sent to a customer
     */
    public function aSurveyHasBeenSentToACustomer(): void
    {
        (new ShopFixture($this->entityManager, $this->cache))->createChannel(self::CHANNEL, 'NPS Behat', self::HOST);

        $configuration = new Configuration();
        $configuration->setChannelCode(self::CHANNEL);
        $this->entityManager->persist($configuration);

        $survey = (new SurveyFactory($this->entityManager))->createSurvey(channelCode: self::CHANNEL);
        $survey->setToken(bin2hex(random_bytes(8)));
        $survey->setStatus(SurveyStatus::Sent);
        $survey->setSentAt(new \DateTimeImmutable('-1 day'));
        $survey->setExpiresAt(new \DateTimeImmutable('+29 days'));

        $this->entityManager->flush();

        $this->browser->setServerParameter('HTTP_HOST', self::HOST);
        $this->survey = $survey;
    }

    /**
     * @When the customer picks :score in the email
     */
    public function theCustomerPicksInTheEmail(int $score): void
    {
        $this->browser->request('GET', sprintf('/%s/nps/%s/%d', self::LOCALE, $this->token(), $score));
    }

    /**
     * @When the customer leaves the comment :comment
     */
    public function theCustomerLeavesTheComment(string $comment): void
    {
        $crawler = $this->browser->request('GET', sprintf('/%s/nps/%s', self::LOCALE, $this->token()));

        $form = $crawler->filter('form')->form();
        $form['cyfrowe_nps_survey_answer[comment]'] = $comment;

        $this->browser->submit($form);
    }

    /**
     * @Then the survey holds a score of :score
     */
    public function theSurveyHoldsAScoreOf(int $score): void
    {
        Assert::assertSame($score, $this->reload()->getScore());
    }

    /**
     * @Then the survey is finished and holds the comment :comment
     */
    public function theSurveyIsFinishedAndHoldsTheComment(string $comment): void
    {
        $survey = $this->reload();

        Assert::assertSame(SurveyStatus::Completed, $survey->getStatus());
        Assert::assertSame($comment, $survey->getComment());
        Assert::assertNotNull($survey->getCompletedAt());
    }

    /**
     * @Then the survey holds no score at all
     */
    public function theSurveyHoldsNoScoreAtAll(): void
    {
        Assert::assertNull($this->reload()->getScore());
    }

    private function reload(): Survey
    {
        $this->entityManager->clear();

        $survey = $this->entityManager->getRepository(Survey::class)->findOneBy(['token' => $this->token()]);
        Assert::assertInstanceOf(Survey::class, $survey);

        return $survey;
    }

    private function token(): string
    {
        Assert::assertNotNull($this->survey, 'No survey has been set up for this scenario.');

        return (string) $this->survey->getToken();
    }
}
