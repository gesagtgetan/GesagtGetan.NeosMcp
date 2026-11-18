<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Repository\AssetRepository;

/**
 * Translates between the assets that asset-typed node properties hold and `asset://<assetIdentifier>`
 * references.
 *
 * JSON cannot carry PHP objects, so getNode() returns such a property as a reference and
 * createNode() / setNodeProperties() accept the same string. It is also the format Neos uses for
 * links to assets, and what findAssets hands out identifiers for.
 */
#[Flow\Scope('singleton')]
final readonly class AssetPropertyConverter
{
    private const REFERENCE_SCHEME = 'asset://';
    private const LIST_TYPE_PATTERN = '/^array<(.+)>$/';

    public function __construct(
        private AssetRepository $assetRepository,
    ) {
    }

    /**
     * Whether a property declared with this type holds an asset or a list of assets.
     */
    public static function isAssetPropertyType(string $propertyType): bool
    {
        return self::assetClassOf($propertyType) !== null;
    }

    public static function toReference(Asset $asset): string
    {
        return self::REFERENCE_SCHEME . $asset->getIdentifier();
    }

    /**
     * @param string $propertyName
     * @param string $propertyType An asset type, see {@see isAssetPropertyType()}
     * @param mixed $references One reference, or a list of references for an `array<...>` type. The bare asset identifier is accepted too.
     *
     * @return AssetInterface|list<AssetInterface>
     */
    public function toAssets(string $propertyName, string $propertyType, mixed $references): AssetInterface|array
    {
        $assetClass = self::assetClassOf($propertyType)
            ?? throw new \LogicException(sprintf('"%s" is not an asset property type.', $propertyType), 1795100001);

        if (preg_match(self::LIST_TYPE_PATTERN, $propertyType) !== 1) {
            return $this->toAsset($propertyName, $assetClass, $references);
        }
        if (!is_array($references) || !array_is_list($references)) {
            throw new \InvalidArgumentException(sprintf('Property "%s" holds a list of assets and needs a list of references like ["asset://<assetIdentifier>"], got %s.', $propertyName, get_debug_type($references)), 1795100002);
        }

        return array_map(
            fn (mixed $reference): AssetInterface => $this->toAsset($propertyName, $assetClass, $reference),
            $references,
        );
    }

    private function toAsset(string $propertyName, string $assetClass, mixed $reference): AssetInterface
    {
        if (!is_string($reference) || $reference === '') {
            throw new \InvalidArgumentException(sprintf('Property "%s" holds an asset and needs a reference like "asset://<assetIdentifier>", got %s. Use findAssets to look up the identifier.', $propertyName, is_string($reference) ? 'an empty string' : get_debug_type($reference)), 1795100003);
        }

        $assetIdentifier = str_starts_with($reference, self::REFERENCE_SCHEME)
            ? substr($reference, strlen(self::REFERENCE_SCHEME))
            : $reference;
        $asset = $this->assetRepository->findByIdentifier($assetIdentifier);
        if (!$asset instanceof AssetInterface) {
            throw new \InvalidArgumentException(sprintf('Property "%s": no asset with identifier "%s" exists. Use findAssets to look up the identifier.', $propertyName, $assetIdentifier), 1795100004);
        }
        if (!$asset instanceof $assetClass) {
            throw new \InvalidArgumentException(sprintf('Property "%s" needs an asset of type %s, but "%s" is a %s.', $propertyName, $assetClass, $assetIdentifier, $asset::class), 1795100005);
        }

        return $asset;
    }

    /**
     * The asset class a property type declares, either directly or as the element type of a list.
     */
    private static function assetClassOf(string $propertyType): ?string
    {
        $elementType = preg_match(self::LIST_TYPE_PATTERN, $propertyType, $matches) === 1 ? $matches[1] : $propertyType;
        $className = ltrim($elementType, '\\');

        return is_a($className, AssetInterface::class, true) || is_a($className, ImageInterface::class, true)
            ? $className
            : null;
    }
}
