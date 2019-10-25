<?php

namespace Wikibase\Client\ParserOutput;

use InvalidArgumentException;
use ParserOutput;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Title;
use Wikibase\Client\Hooks\OtherProjectsSidebarGeneratorFactory;
use Wikibase\Client\Usage\EntityUsageFactory;
use Wikibase\Client\Usage\ParserOutputUsageAccumulator;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\Lib\Store\SiteLinkLookup;

/**
 * Update Wikibase ParserOutput properties and extension data.
 *
 * @license GPL-2.0-or-later
 * @author Daniel Kinzler
 * @author Katie Filbert < aude.wiki@gmail.com >
 */
class ClientParserOutputDataUpdater {

	/**
	 * @var OtherProjectsSidebarGeneratorFactory
	 */
	private $otherProjectsSidebarGeneratorFactory;

	/**
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * @var SiteLinkLookup
	 */
	private $siteLinkLookup;

	/**
	 * @var EntityUsageFactory
	 */
	private $entityUsageFactory;

	/**
	 * @var string
	 */
	private $siteId;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @param OtherProjectsSidebarGeneratorFactory $otherProjectsSidebarGeneratorFactory
	 *            Use the factory here to defer initialization of things like Site objects.
	 * @param SiteLinkLookup $siteLinkLookup
	 * @param EntityLookup $entityLookup
	 * @param EntityUsageFactory $entityUsageFactory
	 * @param string $siteId The global site ID for the local wiki
	 * @param LoggerInterface|null $logger
	 *
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		OtherProjectsSidebarGeneratorFactory $otherProjectsSidebarGeneratorFactory,
		SiteLinkLookup $siteLinkLookup,
		EntityLookup $entityLookup,
		EntityUsageFactory $entityUsageFactory,
		$siteId,
		LoggerInterface $logger = null
	) {
		if ( !is_string( $siteId ) ) {
			throw new InvalidArgumentException( '$siteId must be a string.' );
		}

		$this->otherProjectsSidebarGeneratorFactory = $otherProjectsSidebarGeneratorFactory;
		$this->entityLookup = $entityLookup;
		$this->siteLinkLookup = $siteLinkLookup;
		$this->entityUsageFactory = $entityUsageFactory;
		$this->siteId = $siteId;
		$this->logger = $logger ?: new NullLogger();
	}

	/**
	 * Add wikibase_item parser output property
	 *
	 * @param Title $title
	 * @param ParserOutput $out
	 */
	public function updateItemIdProperty( Title $title, ParserOutput $out ) {
		$itemId = $this->getItemIdForTitle( $title );

		if ( $itemId ) {
			$out->setProperty( 'wikibase_item', $itemId->getSerialization() );

			$usageAccumulator = new ParserOutputUsageAccumulator( $out, $this->entityUsageFactory );
			$usageAccumulator->addSiteLinksUsage( $itemId );
		} else {
			$out->unsetProperty( 'wikibase_item' );
		}
	}

	/**
	 * Add tracking category if the page is a redirect and is connected to an item
	 *
	 * @param Title $title
	 * @param ParserOutput $out
	 */
	public function updateTrackingCategories( Title $title, ParserOutput $out ) {
		$itemId = $this->getItemIdForTitle( $title );

		if ( $itemId && $title->isRedirect() ) {
			$out->addTrackingCategory( 'connected-redirect-category', $title );
		}
	}

	public function updateOtherProjectsLinksData( Title $title, ParserOutput $out ) {
		$itemId = $this->getItemIdForTitle( $title );

		if ( $itemId ) {
			$usageAccumulator = new ParserOutputUsageAccumulator( $out, $this->entityUsageFactory );
			$otherProjectsSidebarGenerator = $this->otherProjectsSidebarGeneratorFactory
				->getOtherProjectsSidebarGenerator( $usageAccumulator );
			$otherProjects = $otherProjectsSidebarGenerator->buildProjectLinkSidebar( $title );
			$out->setExtensionData( 'wikibase-otherprojects-sidebar', $otherProjects );
		} else {
			$out->setExtensionData( 'wikibase-otherprojects-sidebar', [] );
		}
	}

	public function updateBadgesProperty( Title $title, ParserOutput $out ) {
		$itemId = $this->getItemIdForTitle( $title );

		// first reset all badges in case one got removed
		foreach ( $out->getProperties() as $name => $property ) {
			if ( strpos( $name, 'wikibase-badge-' ) === 0 ) {
				$out->unsetProperty( $name );
			}
		}

		if ( $itemId ) {
			$this->setBadgesProperty( $itemId, $out );
		}
	}

	private function setBadgesProperty( ItemId $itemId, ParserOutput $out ) {
		/** @var Item $item */
		$item = $this->entityLookup->getEntity( $itemId );

		if ( !$item || !$item->getSiteLinkList()->hasLinkWithSiteId( $this->siteId ) ) {
			// Probably some sort of race condition or data inconsistency.
			// See T183993.
			$this->logger->warning(
				'According to SiteLinkLookup {item} is linked to {site}, but the link does not exist.',
				[
					'item' => $itemId->getSerialization(),
					'site' => $this->siteId,
				]
			);

			return;
		}

		$siteLink = $item->getSiteLinkList()->getBySiteId( $this->siteId );

		foreach ( $siteLink->getBadges() as $badge ) {
			$out->setProperty( 'wikibase-badge-' . $badge->getSerialization(), true );
		}
	}

	/**
	 * @param Title $title
	 *
	 * @return ItemId|null
	 */
	private function getItemIdForTitle( Title $title ) {
		return $this->siteLinkLookup->getItemIdForLink(
			$this->siteId,
			$title->getPrefixedText()
		);
	}

}
