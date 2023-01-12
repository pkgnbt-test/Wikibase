<?php declare( strict_types=1 );

namespace Wikibase\Repo\RestApi\Serialization;

use ArrayObject;
use Wikibase\Repo\RestApi\Domain\ReadModel\ItemData;

/**
 * @license GPL-2.0-or-later
 */
class ItemDataSerializer {

	private ReadModelStatementListSerializer $statementsSerializer;
	private SiteLinkListSerializer $siteLinksSerializer;

	public function __construct( ReadModelStatementListSerializer $statementsSerializer, SiteLinkListSerializer $siteLinksSerializer ) {
		$this->statementsSerializer = $statementsSerializer;
		$this->siteLinksSerializer = $siteLinksSerializer;
	}

	public function serialize( ItemData $itemData ): array {
		$fieldSerializers = [
			ItemData::FIELD_TYPE => fn() => $itemData->getType(),
			ItemData::FIELD_LABELS => fn() => new ArrayObject( $itemData->getLabels()->toTextArray() ),
			ItemData::FIELD_DESCRIPTIONS => fn() => new ArrayObject( $itemData->getDescriptions()->toTextArray() ),
			ItemData::FIELD_ALIASES => fn() => new ArrayObject( $itemData->getAliases()->toTextArray() ),
			ItemData::FIELD_STATEMENTS => fn() => $this->statementsSerializer->serialize( $itemData->getStatements() ),
			ItemData::FIELD_SITELINKS => fn() => $this->siteLinksSerializer->serialize( $itemData->getSiteLinks() ),
		];

		// serialize all $itemData fields, filtered by isRequested()
		$serialization = array_map(
			fn( callable $serializeField ) => $serializeField(),
			array_filter(
				$fieldSerializers,
				fn ( string $fieldName ) => $itemData->isRequested( $fieldName ),
				ARRAY_FILTER_USE_KEY
			)
		);

		$serialization['id'] = $itemData->getId()->getSerialization();

		return $serialization;
	}

}
