<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the sending date and retire the expired survey status.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'The NPS plugin supports MySQL and MariaDB only; its migrations cannot run on this database.',
        );

        $this->addSql('CREATE INDEX idx_nps_survey_sent ON cyfrowe_nps_survey (sent_at)');
        // Nothing in the package ever set it, but a record still holding it would no longer
        // hydrate once the enum lost the case. An unanswered survey whose link ran out stays sent.
        $this->addSql("UPDATE cyfrowe_nps_survey SET status = 'sent' WHERE status = 'expired'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'The NPS plugin supports MySQL and MariaDB only; its migrations cannot run on this database.',
        );

        $this->addSql('DROP INDEX idx_nps_survey_sent ON cyfrowe_nps_survey');
    }
}
