<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923054121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the NPS survey and per-channel configuration tables.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'The NPS plugin supports MySQL and MariaDB only; its migrations cannot run on this database.',
        );

        $this->addSql('CREATE TABLE cyfrowe_nps_configuration (id INT AUTO_INCREMENT NOT NULL, channel_code VARCHAR(255) DEFAULT NULL, enabled TINYINT(1) NOT NULL, delay_days SMALLINT NOT NULL, cooldown_days SMALLINT NOT NULL, link_validity_days SMALLINT NOT NULL, send_hour SMALLINT NOT NULL, notify_enabled TINYINT(1) NOT NULL, notify_email VARCHAR(255) DEFAULT NULL, notify_threshold SMALLINT NOT NULL, notify_window_minutes SMALLINT NOT NULL, UNIQUE INDEX uniq_nps_configuration_channel (channel_code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cyfrowe_nps_configuration_translation (id INT AUTO_INCREMENT NOT NULL, translatable_id INT NOT NULL, email_subject VARCHAR(255) DEFAULT NULL, main_question LONGTEXT DEFAULT NULL, question_low LONGTEXT DEFAULT NULL, question_high LONGTEXT DEFAULT NULL, thank_you_text LONGTEXT DEFAULT NULL, footer_note LONGTEXT DEFAULT NULL, locale VARCHAR(255) NOT NULL, INDEX IDX_5F65846C2C2AC5D3 (translatable_id), UNIQUE INDEX cyfrowe_nps_configuration_translation_uniq_trans (translatable_id, locale), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cyfrowe_nps_survey (id INT AUTO_INCREMENT NOT NULL, order_id INT DEFAULT NULL, customer_id INT DEFAULT NULL, token VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, skip_reason VARCHAR(32) DEFAULT NULL, channel_code VARCHAR(255) DEFAULT NULL, locale_code VARCHAR(16) DEFAULT NULL, order_number VARCHAR(255) DEFAULT NULL, order_total INT DEFAULT NULL, currency_code VARCHAR(3) DEFAULT NULL, shipping_method_code VARCHAR(255) DEFAULT NULL, shipping_method_name VARCHAR(255) DEFAULT NULL, qualified_at DATETIME DEFAULT NULL, sent_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, score SMALLINT DEFAULT NULL, score_set_at DATETIME DEFAULT NULL, comment LONGTEXT DEFAULT NULL, completed_at DATETIME DEFAULT NULL, bot_suspected TINYINT(1) NOT NULL, handling_status VARCHAR(20) NOT NULL, handled_by VARCHAR(255) DEFAULT NULL, internal_note LONGTEXT DEFAULT NULL, critical_notified_at DATETIME DEFAULT NULL, critical_comment_notified_at DATETIME DEFAULT NULL, critical_correction_notified_at DATETIME DEFAULT NULL, critical_attempts SMALLINT DEFAULT 0 NOT NULL, INDEX IDX_3975AE129395C3F3 (customer_id), INDEX idx_nps_survey_channel_scored (channel_code, score_set_at), INDEX idx_nps_survey_shipping_method (shipping_method_code), INDEX idx_nps_survey_shipping_name (shipping_method_name), INDEX idx_nps_survey_due_for_sending (status, channel_code, qualified_at), INDEX idx_nps_survey_customer_sent (customer_id, sent_at), INDEX idx_nps_survey_handling (handling_status), INDEX idx_nps_survey_score_notified (score, critical_notified_at), INDEX idx_nps_survey_channel_notified (channel_code, critical_notified_at, expires_at), UNIQUE INDEX uniq_nps_survey_token (token), UNIQUE INDEX uniq_nps_survey_order (order_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE cyfrowe_nps_configuration_translation ADD CONSTRAINT FK_5F65846C2C2AC5D3 FOREIGN KEY (translatable_id) REFERENCES cyfrowe_nps_configuration (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cyfrowe_nps_survey ADD CONSTRAINT FK_3975AE128D9F6D38 FOREIGN KEY (order_id) REFERENCES sylius_order (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cyfrowe_nps_survey ADD CONSTRAINT FK_3975AE129395C3F3 FOREIGN KEY (customer_id) REFERENCES sylius_customer (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'The NPS plugin supports MySQL and MariaDB only; its migrations cannot run on this database.',
        );

        $this->addSql('ALTER TABLE cyfrowe_nps_configuration_translation DROP FOREIGN KEY FK_5F65846C2C2AC5D3');
        $this->addSql('ALTER TABLE cyfrowe_nps_survey DROP FOREIGN KEY FK_3975AE128D9F6D38');
        $this->addSql('ALTER TABLE cyfrowe_nps_survey DROP FOREIGN KEY FK_3975AE129395C3F3');
        $this->addSql('DROP TABLE cyfrowe_nps_configuration');
        $this->addSql('DROP TABLE cyfrowe_nps_configuration_translation');
        $this->addSql('DROP TABLE cyfrowe_nps_survey');
    }
}
