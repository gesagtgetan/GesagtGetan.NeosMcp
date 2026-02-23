<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260220000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create OAuth tables for MCP authorization server';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'.",
        );

        $this->addSql('CREATE TABLE gesagtgetan_neosmcp_oauth_client (
            persistence_object_identifier VARCHAR(40) NOT NULL,
            clientid VARCHAR(255) NOT NULL,
            clientsecret VARCHAR(255) DEFAULT NULL,
            clientname VARCHAR(255) NOT NULL,
            redirecturis LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\',
            granttypes LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\',
            tokenendpointauthmethod VARCHAR(50) NOT NULL DEFAULT \'none\',
            isconfidential TINYINT(1) NOT NULL DEFAULT 0,
            createdat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_oauth_client_clientid (clientid),
            PRIMARY KEY(persistence_object_identifier)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE gesagtgetan_neosmcp_oauth_auth_code (
            persistence_object_identifier VARCHAR(40) NOT NULL,
            code VARCHAR(128) NOT NULL,
            clientid VARCHAR(255) NOT NULL,
            useridentifier VARCHAR(255) DEFAULT NULL,
            redirecturi VARCHAR(2048) DEFAULT NULL,
            scopes VARCHAR(1024) NOT NULL,
            expiresat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE INDEX UNIQ_oauth_auth_code_code (code),
            PRIMARY KEY(persistence_object_identifier)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE gesagtgetan_neosmcp_oauth_refresh_token (
            persistence_object_identifier VARCHAR(40) NOT NULL,
            token VARCHAR(128) NOT NULL,
            accesstokenid VARCHAR(255) NOT NULL,
            clientid VARCHAR(255) NOT NULL,
            useridentifier VARCHAR(255) DEFAULT NULL,
            scopes VARCHAR(1024) NOT NULL,
            expiresat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE INDEX UNIQ_oauth_refresh_token_token (token),
            PRIMARY KEY(persistence_object_identifier)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'.",
        );

        $this->addSql('DROP TABLE gesagtgetan_neosmcp_oauth_refresh_token');
        $this->addSql('DROP TABLE gesagtgetan_neosmcp_oauth_auth_code');
        $this->addSql('DROP TABLE gesagtgetan_neosmcp_oauth_client');
    }
}
