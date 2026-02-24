<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Repository;

use GesagtGetan\NeosMcp\OAuth\Entity\OAuthAuthCode;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;

/**
 * @Flow\Scope("singleton")
 *
 * @method OAuthAuthCode|null findOneByCode(string $code)
 */
class OAuthAuthCodeRepository extends Repository implements AuthCodeRepositoryInterface
{
    public const ENTITY_CLASSNAME = OAuthAuthCode::class;

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new OAuthAuthCode();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->add($authCodeEntity);
    }

    /** @param string $codeId */
    public function revokeAuthCode($codeId): void
    {
        $authCode = $this->findOneByCode($codeId);

        if ($authCode !== null) {
            $authCode->setRevoked(true);
        }
    }

    /** @param string $codeId */
    public function isAuthCodeRevoked($codeId): bool
    {
        $authCode = $this->findOneByCode($codeId);

        if ($authCode === null) {
            return true;
        }

        return $authCode->isRevoked();
    }
}
