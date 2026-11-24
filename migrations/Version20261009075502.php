<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009075502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace the sending hour with a sending window.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'The NPS plugin supports MySQL and MariaDB only; its migrations cannot run on this database.',
        );

        $this->addSql('ALTER TABLE cyfrowe_nps_configuration CHANGE send_hour send_hour_from SMALLINT NOT NULL, ADD send_hour_to SMALLINT NOT NULL AFTER send_hour_from');
        $this->addSql('UPDATE cyfrowe_nps_configuration SET send_hour_to = CASE WHEN send_hour_from < 20 THEN 20 ELSE 24 END');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'The NPS plugin supports MySQL and MariaDB only; its migrations cannot run on this database.',
        );

        $this->addSql('ALTER TABLE cyfrowe_nps_configuration DROP send_hour_to, CHANGE send_hour_from send_hour SMALLINT NOT NULL');
    }
}
