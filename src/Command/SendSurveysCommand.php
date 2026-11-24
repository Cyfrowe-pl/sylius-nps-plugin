<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Command;

use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Message\SendSurvey;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'nps:survey:send',
    description: 'Dispatches surveys whose waiting period is over.',
)]
final class SendSurveysCommand extends Command
{
    public function __construct(
        private readonly SurveyRepositoryInterface $surveyRepository,
        private readonly RepositoryInterface $configurationRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many surveys to take in one run', '500')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Restrict the run to one channel code')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be dispatched and change nothing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Two runs overlapping would pick the same records and the customer would get the survey
        // twice. The status only moves to QUEUED once a record is dispatched, which is too late.
        $lock = $this->lockFactory->createLock('cyfrowe_nps_survey_send');

        if (!$lock->acquire()) {
            $io->warning('Another run is still going. Nothing was dispatched.');

            return Command::SUCCESS;
        }

        try {
            return $this->dispatchDueSurveys($io, $input);
        } finally {
            $lock->release();
        }
    }

    private function dispatchDueSurveys(SymfonyStyle $io, InputInterface $input): int
    {
        $dryRun = true === $input->getOption('dry-run');
        $limit = max(1, (int) $input->getOption('limit'));
        $dispatched = 0;

        foreach ($this->configurations($input) as $configuration) {
            if (!$configuration->isEnabled() || !$this->isWithinTheSendingWindow($configuration)) {
                continue;
            }

            $due = $this->surveyRepository->findDueForSending(
                (string) $configuration->getChannelCode(),
                $this->clock->now()->modify(sprintf('-%d days', $configuration->getDelayDays())),
                $limit - $dispatched,
            );

            foreach ($due as $survey) {
                ++$dispatched;

                $io->writeln(sprintf(
                    '%s survey %d for order %s',
                    $dryRun ? 'Would dispatch' : 'Dispatching',
                    (int) $survey->getId(),
                    (string) $survey->getOrderNumber(),
                ));

                if ($dryRun) {
                    continue;
                }

                // Moved out of PENDING before the message goes out, so the next run cannot pick
                // the same record while a worker is still holding it.
                $survey->setStatus(SurveyStatus::Queued);
                $this->entityManager->flush();

                try {
                    $this->messageBus->dispatch(new SendSurvey((int) $survey->getId()));
                } catch (\Throwable $exception) {
                    $survey->setStatus(SurveyStatus::Pending);
                    $this->entityManager->flush();

                    $this->logger->error('Dispatching an NPS survey failed; it waits for the next run.', [
                        'survey' => $survey->getId(),
                        'exception' => $exception,
                    ]);

                    $io->writeln(sprintf('<comment>Survey %d could not be dispatched.</comment>', (int) $survey->getId()));
                }
            }

            if ($dispatched >= $limit) {
                break;
            }
        }

        $io->success(sprintf('%d %s.', $dispatched, $dryRun ? 'surveys would be dispatched' : 'surveys dispatched'));

        return Command::SUCCESS;
    }

    /** @return list<ConfigurationInterface> */
    private function configurations(InputInterface $input): array
    {
        $channel = $input->getOption('channel');

        $found = is_string($channel) && '' !== $channel
            ? $this->configurationRepository->findBy(['channelCode' => $channel])
            : $this->configurationRepository->findAll();

        return array_values(array_filter(
            $found,
            static fn (object $configuration): bool => $configuration instanceof ConfigurationInterface,
        ));
    }

    /**
     * Whether the current hour falls inside the channel's sending window. Any run inside it sends,
     * rather than only the one at the opening hour: a run that failed at ten is caught up with at
     * eleven instead of tomorrow, which is the very thing running hourly is meant to give. Records
     * move to QUEUED as they go out, so a later run finds nothing to repeat.
     */
    private function isWithinTheSendingWindow(ConfigurationInterface $configuration): bool
    {
        $hour = (int) $this->clock->now()->format('G');

        return $hour >= $configuration->getSendHourFrom() && $hour < $configuration->getSendHourTo();
    }
}
