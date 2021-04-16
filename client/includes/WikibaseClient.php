<?php

namespace Wikibase\Client;

use DataValues\Deserializers\DataValueDeserializer;
use ExtensionRegistry;
use ExternalUserNames;
use Language;
use MediaWiki\MediaWikiServices;
use MWException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Serializers\Serializer;
use Site;
use SiteLookup;
use Wikibase\Client\Changes\AffectedPagesFinder;
use Wikibase\Client\Changes\ChangeHandler;
use Wikibase\Client\DataAccess\ClientSiteLinkTitleLookup;
use Wikibase\Client\DataAccess\DataAccessSnakFormatterFactory;
use Wikibase\Client\DataAccess\ParserFunctions\Runner;
use Wikibase\Client\DataAccess\ParserFunctions\StatementGroupRendererFactory;
use Wikibase\Client\DataAccess\ReferenceFormatterFactory;
use Wikibase\Client\DataAccess\SnaksFinder;
use Wikibase\Client\Hooks\LangLinkHandlerFactory;
use Wikibase\Client\Hooks\LanguageLinkBadgeDisplay;
use Wikibase\Client\Hooks\OtherProjectsSidebarGeneratorFactory;
use Wikibase\Client\Hooks\SidebarLinkBadgeDisplay;
use Wikibase\Client\ParserOutput\ClientParserOutputDataUpdater;
use Wikibase\Client\RecentChanges\RecentChangeFactory;
use Wikibase\Client\Store\ClientStore;
use Wikibase\Client\Store\DescriptionLookup;
use Wikibase\Client\Usage\EntityUsageFactory;
use Wikibase\DataAccess\AliasTermBuffer;
use Wikibase\DataAccess\DataAccessSettings;
use Wikibase\DataAccess\EntitySource;
use Wikibase\DataAccess\EntitySourceDefinitions;
use Wikibase\DataAccess\PrefetchingTermLookup;
use Wikibase\DataAccess\PrefetchingTermLookupFactory;
use Wikibase\DataAccess\SingleEntitySourceServicesFactory;
use Wikibase\DataAccess\WikibaseServices;
use Wikibase\DataModel\DeserializerFactory;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\SerializerFactory;
use Wikibase\DataModel\Services\Diff\EntityDiffer;
use Wikibase\DataModel\Services\EntityId\EntityIdComposer;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\Services\Lookup\RestrictedEntityLookup;
use Wikibase\DataModel\Services\Lookup\TermLookup;
use Wikibase\DataModel\Services\Term\PropertyLabelResolver;
use Wikibase\DataModel\Services\Term\TermBuffer;
use Wikibase\Lib\Changes\EntityChangeFactory;
use Wikibase\Lib\ContentLanguages;
use Wikibase\Lib\DataTypeDefinitions;
use Wikibase\Lib\DataTypeFactory;
use Wikibase\Lib\EntityTypeDefinitions;
use Wikibase\Lib\Formatters\CachingKartographerEmbeddingHandler;
use Wikibase\Lib\Formatters\FormatterLabelDescriptionLookupFactory;
use Wikibase\Lib\Formatters\OutputFormatSnakFormatterFactory;
use Wikibase\Lib\Formatters\OutputFormatValueFormatterFactory;
use Wikibase\Lib\Formatters\Reference\WellKnownReferenceProperties;
use Wikibase\Lib\Formatters\WikibaseSnakFormatterBuilders;
use Wikibase\Lib\Formatters\WikibaseValueFormatterBuilders;
use Wikibase\Lib\LanguageFallbackChainFactory;
use Wikibase\Lib\LanguageNameLookup;
use Wikibase\Lib\SettingsArray;
use Wikibase\Lib\Store\EntityIdLookup;
use Wikibase\Lib\Store\EntityNamespaceLookup;
use Wikibase\Lib\Store\LanguageFallbackLabelDescriptionLookupFactory;
use Wikibase\Lib\Store\PropertyOrderProvider;
use Wikibase\Lib\Store\TitleLookupBasedEntityExistenceChecker;
use Wikibase\Lib\Store\TitleLookupBasedEntityRedirectChecker;
use Wikibase\Lib\Store\TitleLookupBasedEntityTitleTextLookup;
use Wikibase\Lib\Store\TitleLookupBasedEntityUrlLookup;
use Wikibase\Lib\StringNormalizer;
use Wikibase\Lib\TermFallbackCache\TermFallbackCacheFacade;
use Wikibase\Lib\TermFallbackCacheFactory;
use Wikibase\Lib\WikibaseContentLanguages;

/**
 * Top level factory for the WikibaseClient extension.
 *
 * @license GPL-2.0-or-later
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 * @author Daniel Kinzler
 */
final class WikibaseClient {

	/**
	 * @warning only for use in getDefaultInstance()!
	 * @var WikibaseClient
	 */
	private static $defaultInstance = null;

	/**
	 * @warning only for use in getDefaultSnakFormatterBuilders()!
	 * @var WikibaseSnakFormatterBuilders
	 */
	private static $defaultSnakFormatterBuilders = null;

	/**
	 * @var SiteLookup
	 */
	private $siteLookup;

	/**
	 * @var ClientParserOutputDataUpdater|null
	 */
	private $parserOutputDataUpdater = null;

	/**
	 * @var WikibaseValueFormatterBuilders|null
	 */
	private $valueFormatterBuilders = null;

	/** @var ReferenceFormatterFactory|null */
	private $referenceFormatterFactory = null;

	/**
	 * @warning This is for use with bootstrap code in WikibaseClient.datatypes.php only!
	 * Program logic should use WikibaseClient::getSnakFormatterFactory() instead!
	 *
	 * @return WikibaseValueFormatterBuilders
	 */
	public static function getDefaultValueFormatterBuilders() {
		global $wgThumbLimits;
		return self::getDefaultInstance()->newWikibaseValueFormatterBuilders( $wgThumbLimits );
	}

	/**
	 * Returns a low level factory object for creating formatters for well known data types.
	 *
	 * @warning This is for use with getDefaultValueFormatterBuilders() during bootstrap only!
	 * Program logic should use WikibaseClient::getSnakFormatterFactory() instead!
	 *
	 * @param array $thumbLimits
	 *
	 * @return WikibaseValueFormatterBuilders
	 */
	private function newWikibaseValueFormatterBuilders( array $thumbLimits ) {
		if ( $this->valueFormatterBuilders === null ) {
			$settings = self::getSettings();

			$entityTitleLookup = new ClientSiteLinkTitleLookup(
				self::getStore()->getSiteLinkLookup(),
				$settings->getSetting( 'siteGlobalID' )
			);

			$services = MediaWikiServices::getInstance();

			$kartographerEmbeddingHandler = null;
			if ( $this->useKartographerGlobeCoordinateFormatter() ) {
				$kartographerEmbeddingHandler = new CachingKartographerEmbeddingHandler(
					$services->getParserFactory()->create()
				);
			}

			$this->valueFormatterBuilders = new WikibaseValueFormatterBuilders(
				new FormatterLabelDescriptionLookupFactory( self::getTermLookup() ),
				new LanguageNameLookup( self::getUserLanguage()->getCode() ),
				self::getRepoItemUriParser(),
				$settings->getSetting( 'geoShapeStorageBaseUrl' ),
				$settings->getSetting( 'tabularDataStorageBaseUrl' ),
				self::getTermFallbackCache(),
				$settings->getSetting( 'sharedCacheDuration' ),
				self::getEntityLookup(),
				self::getStore()->getEntityRevisionLookup(),
				$settings->getSetting( 'entitySchemaNamespace' ),
				new TitleLookupBasedEntityExistenceChecker(
					$entityTitleLookup,
					$services->getLinkBatchFactory()
				),
				new TitleLookupBasedEntityTitleTextLookup( $entityTitleLookup ),
				new TitleLookupBasedEntityUrlLookup( $entityTitleLookup ),
				new TitleLookupBasedEntityRedirectChecker( $entityTitleLookup ),
				$entityTitleLookup,
				$kartographerEmbeddingHandler,
				$settings->getSetting( 'useKartographerMaplinkInWikitext' ),
				$thumbLimits
			);
		}

		return $this->valueFormatterBuilders;
	}

	/**
	 * @return bool
	 */
	private function useKartographerGlobeCoordinateFormatter() {
		// FIXME: remove the global out of here
		global $wgKartographerEnableMapFrame;

		return self::getSettings()->getSetting( 'useKartographerGlobeCoordinateFormatter' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'Kartographer' ) &&
			isset( $wgKartographerEnableMapFrame ) &&
			$wgKartographerEnableMapFrame;
	}

	/**
	 * @warning This is for use with bootstrap code in WikibaseClient.datatypes.php only!
	 * Program logic should use WikibaseClient::getSnakFormatterFactory() instead!
	 *
	 * @return WikibaseSnakFormatterBuilders
	 */
	public static function getDefaultSnakFormatterBuilders() {
		if ( self::$defaultSnakFormatterBuilders === null ) {
			self::$defaultSnakFormatterBuilders = self::getDefaultInstance()->newWikibaseSnakFormatterBuilders(
				self::getDefaultValueFormatterBuilders()
			);
		}

		return self::$defaultSnakFormatterBuilders;
	}

	/**
	 * Returns a low level factory object for creating formatters for well known data types.
	 *
	 * @warning This is for use with getDefaultValueFormatterBuilders() during bootstrap only!
	 * Program logic should use WikibaseClient::getSnakFormatterFactory() instead!
	 *
	 * @param WikibaseValueFormatterBuilders $valueFormatterBuilders
	 *
	 * @return WikibaseSnakFormatterBuilders
	 */
	private function newWikibaseSnakFormatterBuilders( WikibaseValueFormatterBuilders $valueFormatterBuilders ) {
		return new WikibaseSnakFormatterBuilders(
			$valueFormatterBuilders,
			self::getStore()->getPropertyInfoLookup(),
			self::getPropertyDataTypeLookup(),
			self::getDataTypeFactory()
		);
	}

	public function __construct(
		SiteLookup $siteLookup
	) {
		$this->siteLookup = $siteLookup;
	}

	public static function getDataTypeDefinitions( ContainerInterface $services = null ): DataTypeDefinitions {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.DataTypeDefinitions' );
	}

	public static function getEntitySourceDefinitions( ContainerInterface $services = null ): EntitySourceDefinitions {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.EntitySourceDefinitions' );
	}

	public static function getEntityTypeDefinitions( ContainerInterface $services = null ): EntityTypeDefinitions {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.EntityTypeDefinitions' );
	}

	public static function getDataTypeFactory( ContainerInterface $services = null ): DataTypeFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.DataTypeFactory' );
	}

	public static function getEntityIdParser( ContainerInterface $services = null ): EntityIdParser {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.EntityIdParser' );
	}

	public static function getEntityIdComposer( ContainerInterface $services = null ): EntityIdComposer {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.EntityIdComposer' );
	}

	/**
	 * @deprecated
	 * DO NOT USE THIS SERVICE! This is just a temporary convenience placeholder until we finish migrating
	 * SingleEntitySourceServices. Will be removed with T277731
	 */
	public static function getSingleEntitySourceServicesFactory(
		ContainerInterface $services = null
	): SingleEntitySourceServicesFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.SingleEntitySourceServicesFactory' );
	}

	public static function getWikibaseServices( ContainerInterface $services = null ): WikibaseServices {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.WikibaseServices' );
	}

	public static function getDataAccessSettings( ContainerInterface $services = null ): DataAccessSettings {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.DataAccessSettings' );
	}

	public static function getEntityLookup( ContainerInterface $services = null ): EntityLookup {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.EntityLookup' );
	}

	public static function getTermBuffer( ContainerInterface $services = null ): TermBuffer {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.TermBuffer' );
	}

	public static function getAliasTermBuffer( ContainerInterface $services = null ): AliasTermBuffer {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.AliasTermBuffer' );
	}

	public static function getTermLookup( ContainerInterface $services = null ): TermLookup {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.TermLookup' );
	}

	public static function getPrefetchingTermLookupFactory(
		ContainerInterface $services = null
	): PrefetchingTermLookupFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.PrefetchingTermLookupFactory' );
	}

	public static function getPrefetchingTermLookup( ContainerInterface $services = null ): PrefetchingTermLookup {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.PrefetchingTermLookup' );
	}

	public static function getPropertyDataTypeLookup( ContainerInterface $services = null ): PropertyDataTypeLookup {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.PropertyDataTypeLookup' );
	}

	public static function getStringNormalizer( ContainerInterface $services = null ): StringNormalizer {
		return ( $services ?: MediawikiServices::getInstance() )
				->get( 'WikibaseClient.StringNormalizer' );
	}

	public static function getRepoLinker( ContainerInterface $services = null ): RepoLinker {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.RepoLinker' );
	}

	public static function getLanguageFallbackChainFactory( ContainerInterface $services = null ): LanguageFallbackChainFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.LanguageFallbackChainFactory' );
	}

	public static function getLanguageFallbackLabelDescriptionLookupFactory(
		ContainerInterface $services = null
	): LanguageFallbackLabelDescriptionLookupFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.LanguageFallbackLabelDescriptionLookupFactory' );
	}

	public static function getStore( ContainerInterface $services = null ): ClientStore {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.Store' );
	}

	/**
	 * @throws MWException when called to early
	 */
	public function getContentLanguage(): Language {
		/**
		 * Before this constant is defined, custom config may not have been taken into account.
		 * So try not to allow code to use a language before that point.
		 * This code was explicitly mentioning the SetupAfterCache hook.
		 * With services, that hook won't be a problem anymore.
		 * So this check may well be unnecessary (but better safe than sorry).
		 */
		if ( !defined( 'MW_SERVICE_BOOTSTRAP_COMPLETE' ) ) {
			throw new MWException( 'Premature access to MediaWiki ContentLanguage!' );
		}

		return MediaWikiServices::getInstance()->getContentLanguage();
	}

	/**
	 * @deprecated
	 */
	public static function getUserLanguage( ContainerInterface $services = null ): Language {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.UserLanguage' );
	}

	public static function getSettings( ContainerInterface $services = null ): SettingsArray {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.Settings' );
	}

	/**
	 * Returns a new instance constructed from global settings.
	 * IMPORTANT: Use only when it is not feasible to inject an instance properly.
	 *
	 * @throws MWException
	 * @return self
	 */
	private static function newInstance() {
		return new self(
			MediaWikiServices::getInstance()->getSiteLookup()
		);
	}

	/**
	 * IMPORTANT: Use only when it is not feasible to inject an instance properly.
	 *
	 * @param string $reset Flag: Pass "reset" to reset the default instance
	 *
	 * @return self
	 */
	public static function getDefaultInstance( $reset = 'noreset' ) {
		if ( $reset === 'reset' ) {
			self::$defaultInstance = null;
			self::$defaultSnakFormatterBuilders = null;
		}

		if ( self::$defaultInstance === null ) {
			self::$defaultInstance = self::newInstance();
		}

		return self::$defaultInstance;
	}

	public static function getLogger( ContainerInterface $services = null ): LoggerInterface {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.Logger' );
	}

	/**
	 * Returns the this client wiki's site object.
	 *
	 * This is taken from the siteGlobalID setting, which defaults
	 * to the wiki's database name.
	 *
	 * If the configured site ID is not found in the sites table, a
	 * new Site object is constructed from the configured ID.
	 */
	public static function getSite( ContainerInterface $services = null ): Site {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.Site' );
	}

	/**
	 * Returns the site group ID for the group to be used for language links.
	 * This is typically the group the client wiki itself belongs to, but
	 * can be configured to be otherwise using the languageLinkSiteGroup setting.
	 */
	public static function getLangLinkSiteGroup( ContainerInterface $services = null ): string {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.LangLinkSiteGroup' );
	}

	/**
	 * Get site group ID
	 */
	public static function getSiteGroup( ContainerInterface $services = null ): string {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.SiteGroup' );
	}

	/**
	 * Returns a OutputFormatSnakFormatterFactory the provides SnakFormatters
	 * for different output formats.
	 */
	public static function getSnakFormatterFactory( ContainerInterface $services = null ): OutputFormatSnakFormatterFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.SnakFormatterFactory' );
	}

	/**
	 * Returns a OutputFormatValueFormatterFactory the provides ValueFormatters
	 * for different output formats.
	 */
	public static function getValueFormatterFactory( ContainerInterface $services = null ): OutputFormatValueFormatterFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.ValueFormatterFactory' );
	}

	public static function getRepoItemUriParser( ContainerInterface $services = null ): EntityIdParser {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.RepoItemUriParser' );
	}

	public static function getNamespaceChecker( ContainerInterface $services = null ): NamespaceChecker {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.NamespaceChecker' );
	}

	public function getLangLinkHandlerFactory(): LangLinkHandlerFactory {
		return new LangLinkHandlerFactory(
			$this->getLanguageLinkBadgeDisplay(),
			self::getNamespaceChecker(),
			self::getStore()->getSiteLinkLookup(),
			self::getEntityLookup(),
			$this->siteLookup,
			MediaWikiServices::getInstance()->getHookContainer(),
			self::getLogger(),
			self::getSettings()->getSetting( 'siteGlobalID' ),
			self::getLangLinkSiteGroup()
		);
	}

	public function getParserOutputDataUpdater(): ClientParserOutputDataUpdater {
		if ( $this->parserOutputDataUpdater === null ) {
			$this->parserOutputDataUpdater = new ClientParserOutputDataUpdater(
				$this->getOtherProjectsSidebarGeneratorFactory(),
				self::getStore()->getSiteLinkLookup(),
				self::getEntityLookup(),
				new EntityUsageFactory( self::getEntityIdParser() ),
				self::getSettings()->getSetting( 'siteGlobalID' ),
				self::getLogger()
			);
		}

		return $this->parserOutputDataUpdater;
	}

	public static function getSidebarLinkBadgeDisplay( ContainerInterface $service = null ): SidebarLinkBadgeDisplay {
		return ( $service ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.SidebarLinkBadgeDisplay' );
	}

	public function getLanguageLinkBadgeDisplay(): LanguageLinkBadgeDisplay {
		return new LanguageLinkBadgeDisplay(
			$this->getSidebarLinkBadgeDisplay()
		);
	}

	public static function getBaseDataModelDeserializerFactory(
		ContainerInterface $services = null
	): DeserializerFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.BaseDataModelDeserializerFactory' );
	}

	/**
	 * @return string[]
	 */
	public function getLuaEntityModules() {
		return self::getEntityTypeDefinitions()->get( EntityTypeDefinitions::LUA_ENTITY_MODULE );
	}

	/**
	 * Returns a SerializerFactory creating serializers that generate the most compact serialization.
	 * A factory returned has knowledge about items, properties, and the elements they are made of,
	 * but no other entity types.
	 */
	public static function getCompactBaseDataModelSerializerFactory( ContainerInterface $services = null ): SerializerFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.CompactBaseDataModelSerializerFactory' );
	}

	/**
	 * Returns an entity serializer that generates the most compact serialization.
	 */
	public static function getCompactEntitySerializer( ContainerInterface $services = null ): Serializer {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.CompactEntitySerializer' );
	}

	public static function getDataValueDeserializer( ContainerInterface $services = null ): DataValueDeserializer {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.DataValueDeserializer' );
	}

	public function getOtherProjectsSidebarGeneratorFactory(): OtherProjectsSidebarGeneratorFactory {
		return new OtherProjectsSidebarGeneratorFactory(
			self::getSettings(),
			self::getStore()->getSiteLinkLookup(),
			$this->siteLookup,
			self::getEntityLookup(),
			$this->getSidebarLinkBadgeDisplay(),
			MediaWikiServices::getInstance()->getHookContainer(),
			self::getLogger()
		);
	}

	public static function getEntityChangeFactory( ContainerInterface $services = null ): EntityChangeFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.EntityChangeFactory' );
	}

	public static function getEntityDiffer( ContainerInterface $services = null ): EntityDiffer {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.EntityDiffer' );
	}

	private function getStatementGroupRendererFactory(): StatementGroupRendererFactory {
		return new StatementGroupRendererFactory(
			self::getPropertyLabelResolver(),
			new SnaksFinder(),
			self::getRestrictedEntityLookup(),
			self::getDataAccessSnakFormatterFactory(),
			new EntityUsageFactory( self::getEntityIdParser() ),
			MediaWikiServices::getInstance()->getLanguageConverterFactory(),
			self::getSettings()->getSetting( 'allowDataAccessInUserLanguage' )
		);
	}

	public static function getDataAccessSnakFormatterFactory( ContainerInterface $services = null ): DataAccessSnakFormatterFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.DataAccessSnakFormatterFactory' );
	}

	public function getPropertyParserFunctionRunner(): Runner {
		$settings = self::getSettings();
		return new Runner(
			$this->getStatementGroupRendererFactory(),
			self::getStore()->getSiteLinkLookup(),
			self::getEntityIdParser(),
			self::getRestrictedEntityLookup(),
			$settings->getSetting( 'siteGlobalID' ),
			$settings->getSetting( 'allowArbitraryDataAccess' )
		);
	}

	public static function getOtherProjectsSitesProvider( ContainerInterface $services = null ): OtherProjectsSitesProvider {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.OtherProjectsSitesProvider' );
	}

	public static function getAffectedPagesFinder( containerInterface $services = null ): AffectedPagesFinder {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.AffectedPagesFinder' );
	}

	public static function getChangeHandler( containerInterface $services = null ): ChangeHandler {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.ChangeHandler' );
	}

	public static function getRecentChangeFactory( ContainerInterface $services = null ): RecentChangeFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.RecentChangeFactory' );
	}

	/**
	 * Returns an {@link ExternalUserNames} that can be used to link to the
	 * {@link getItemAndPropertySource item and property source},
	 * if an interwiki prefix for that source (and its site) is known.
	 */
	public static function getExternalUserNames( ContainerInterface $services = null ): ?ExternalUserNames {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.ExternalUserNames' );
	}

	public static function getItemAndPropertySource( ContainerInterface $services = null ): EntitySource {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.ItemAndPropertySource' );
	}

	public static function getWikibaseContentLanguages( ContainerInterface $services = null ): WikibaseContentLanguages {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.WikibaseContentLanguages' );
	}

	/**
	 * Get a ContentLanguages object holding the languages available for labels, descriptions and aliases.
	 */
	public static function getTermsLanguages( ContainerInterface $services = null ): ContentLanguages {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.TermsLanguages' );
	}

	public static function getRestrictedEntityLookup( ContainerInterface $services = null ): RestrictedEntityLookup {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.RestrictedEntityLookup' );
	}

	public static function getPropertyOrderProvider( ContainerInterface $services = null ): PropertyOrderProvider {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.PropertyOrderProvider' );
	}

	public static function getEntityNamespaceLookup( ContainerInterface $services = null ): EntityNamespaceLookup {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.EntityNamespaceLookup' );
	}

	public static function getTermFallbackCache( ContainerInterface $services = null ): TermFallbackCacheFacade {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.TermFallbackCache' );
	}

	public static function getTermFallbackCacheFactory( ContainerInterface $services = null ): TermFallbackCacheFactory {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.TermFallbackCacheFactory' );
	}

	public static function getEntityIdLookup( ContainerInterface $services = null ): EntityIdLookup {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.EntityIdLookup' );
	}

	public static function getDescriptionLookup( ContainerInterface $services = null ): DescriptionLookup {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.DescriptionLookup' );
	}

	public static function getPropertyLabelResolver( ContainerInterface $services = null ): PropertyLabelResolver {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.PropertyLabelResolver' );
	}

	public function getReferenceFormatterFactory(): ReferenceFormatterFactory {
		if ( $this->referenceFormatterFactory === null ) {
			$logger = self::getLogger();
			$this->referenceFormatterFactory = new ReferenceFormatterFactory(
				$this->getDataAccessSnakFormatterFactory(),
				WellKnownReferenceProperties::newFromArray(
					self::getSettings()->getSetting( 'wellKnownReferencePropertyIds' ),
					$logger
				),
				$logger
			);
		}

		return $this->referenceFormatterFactory;
	}

	public static function getItemSource( ContainerInterface $services = null ): EntitySource {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.ItemSource' );
	}

	public static function getPropertySource( ContainerInterface $services = null ): EntitySource {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WikibaseClient.PropertySource' );
	}

}
