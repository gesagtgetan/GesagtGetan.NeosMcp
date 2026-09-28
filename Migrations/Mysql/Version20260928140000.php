<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;


final class Version20260928140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Declare the OAuth client json columns as JSON so they match the mapping';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQLPlatform'.",
        );

        $this->addSql('ALTER TABLE gesagtgetan_neosmcp_oauth_client CHANGE redirecturis redirecturis JSON NOT NULL COMMENT \'(DC2Type:json)\', CHANGE granttypes granttypes JSON NOT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQLPlatform'.",
        );

        $this->addSql('ALTER TABLE gesagtgetan_neosmcp_oauth_client CHANGE redirecturis redirecturis LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\', CHANGE granttypes granttypes LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\'');
    }
}
