<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Version20260220000000 gave the three unique indexes hand-picked names. The entities
 * declare their unique columns via `unique=true`, from which Doctrine derives hashed
 * index names instead, so every `doctrine:migrationgenerate` proposed these renames.
 *
 * Naming the indexes in the mapping is not possible: Flow's FlowAnnotationDriver
 * discards the `name` of an `@ORM\UniqueConstraint`, so the schema has to follow the
 * derived names rather than the other way round.
 */
final class Version20260727170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align OAuth unique index names with the names Doctrine derives from the mapping';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'.",
        );

        $this->addSql('ALTER TABLE gesagtgetan_neosmcp_oauth_client RENAME INDEX uniq_oauth_client_clientid TO UNIQ_79BAA9DD7F98CD1C');
        $this->addSql('ALTER TABLE gesagtgetan_neosmcp_oauth_auth_code RENAME INDEX uniq_oauth_auth_code_code TO UNIQ_F7B9871577153098');
        $this->addSql('ALTER TABLE gesagtgetan_neosmcp_oauth_refresh_token RENAME INDEX uniq_oauth_refresh_token_token TO UNIQ_D39D50A55F37A13B');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'.",
        );

        $this->addSql('ALTER TABLE gesagtgetan_neosmcp_oauth_refresh_token RENAME INDEX uniq_d39d50a55f37a13b TO UNIQ_oauth_refresh_token_token');
        $this->addSql('ALTER TABLE gesagtgetan_neosmcp_oauth_auth_code RENAME INDEX uniq_f7b9871577153098 TO UNIQ_oauth_auth_code_code');
        $this->addSql('ALTER TABLE gesagtgetan_neosmcp_oauth_client RENAME INDEX uniq_79baa9dd7f98cd1c TO UNIQ_oauth_client_clientid');
    }
}
