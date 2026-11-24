<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Command;

use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Mailer\CriticalAlertKind;
use Cyfrowe\SyliusNpsPlugin\Mailer\CriticalAlertSender;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Tells the shop about scores it needs to react to, and about what has happened to them since.
 *
 * A command rather than a schedule: how often it runs, and with what, is the deployment's business.
 * Whatever that interval is, it is added to the grace window before an alert appears - which is why
 * the README recommends a few minutes rather than an hour.
 */
#[AsCommand(
    name: 'nps:notify:critical',
    description: 'Alerts the shop about critical scores, corrections and late comments.',
)]
final class NotifyCriticalCommand extends Command
{
    public function __construct(
        private readonly SurveyRepositoryInterface $surveyRepository,
        private readonly RepositoryInterface $configurationRepository,
        private readonly CriticalAlertSender $alertSender,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many records to alert about in one run', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be sent and change nothing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Two runs overlapping would both find the same records - the mark is only written once an
        // alert is through, which is far too late to stop the second email.
        $lock = $this->lockFactory->createLock('cyfrowe_nps_notify_critical');

        if (!$lock->acquire()) {
            $io->warning('Another run is still going. Nothing was sent.');

            return Command::SUCCESS;
        }

        try {
            return $this->alert($io, $input);
        } finally {
            $lock->release();
        }
    }

    private function alert(SymfonyStyle $io, InputInterface $input): int
    {
        $dryRun = true === $input->getOption('dry-run');
        $limit = max(1, (int) $input->getOption('limit'));
        $now = $this->clock->now();

        $sent = 0;
        $failed = 0;

        foreach ($this->configurations() as $configuration) {
            if (!$configuration->isNotifyEnabled()) {
                continue;
            }

            foreach (CriticalAlertKind::cases() as $kind) {
                // Worked out again for every kind rather than once per channel: handing the same
                // room to all three queries would let one channel send three times the limit.
                $room = $limit - $sent - $failed;

                if ($room < 1) {
                    break 2;
                }

                foreach ($this->due($configuration, $kind, $now, $room) as $survey) {
                    $io->writeln(sprintf(
                        '%s a %s alert for order %s (score %s)',
                        $dryRun ? 'Would send' : 'Sending',
                        $kind->value,
                        (string) $survey->getOrderNumber(),
                        (string) $survey->getScore(),
                    ));

                    if ($dryRun) {
                        ++$sent;

                        continue;
                    }

                    if ($this->alertSender->send($survey, $configuration, $kind)) {
                        ++$sent;

                        continue;
                    }

                    ++$failed;
                    $io->writeln(sprintf('<comment>The alert for order %s did not go out.</comment>', (string) $survey->getOrderNumber()));
                }
            }
        }

        $io->success(sprintf('%d %s.', $sent, $dryRun ? 'alerts would be sent' : 'alerts sent'));

        if ($failed > 0) {
            $io->warning(sprintf('%d could not be sent. The next run will try again.', $failed));
        }

        return Command::SUCCESS;
    }

    /**
     * The records one kind of alert is due for, at most $room of them.
     *
     * @return list<SurveyInterface>
     */
    private function due(
        ConfigurationInterface $configuration,
        CriticalAlertKind $kind,
        \DateTimeImmutable $now,
        int $room,
    ): array {
        $channelCode = (string) $configuration->getChannelCode();
        $threshold = $configuration->getNotifyThreshold();
        $attempts = CriticalAlertSender::MAX_ATTEMPTS;

        return match ($kind) {
            CriticalAlertKind::Primary => $this->surveyRepository->findAwaitingCriticalNotification(
                $channelCode,
                $threshold,
                // Counted back from now, so clicking another number in the email restarts the wait.
                $now->modify(sprintf('-%d minutes', $configuration->getNotifyWindowMinutes())),
                $attempts,
                $room,
            ),
            CriticalAlertKind::Correction => $this->surveyRepository->findAwaitingCorrectionNotice(
                $channelCode,
                $threshold,
                $now,
                $attempts,
                $room,
            ),
            CriticalAlertKind::Comment => $this->surveyRepository->findAwaitingCommentNotice(
                $channelCode,
                $threshold,
                $now,
                $attempts,
                $room,
            ),
        };
    }

    /** @return list<ConfigurationInterface> */
    private function configurations(): array
    {
        return array_values(array_filter(
            $this->configurationRepository->findAll(),
            static fn (object $configuration): bool => $configuration instanceof ConfigurationInterface,
        ));
    }
}
