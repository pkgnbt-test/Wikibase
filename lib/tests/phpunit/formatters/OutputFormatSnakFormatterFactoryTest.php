<?php

namespace Wikibase\Lib\Test;

use DataTypes\DataTypeFactory;
use DataValues\DataValue;
use DataValues\StringValue;
use Language;
use ValueFormatters\FormatterOptions;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\LanguageFallbackChainFactory;
use Wikibase\Lib\OutputFormatSnakFormatterFactory;
use Wikibase\Lib\OutputFormatValueFormatterFactory;
use Wikibase\Lib\SnakFormatter;

/**
 * @covers Wikibase\Lib\OutputFormatSnakFormatterFactory
 *
 * @group ValueFormatters
 * @group DataValueExtensions
 * @group WikibaseLib
 * @group Wikibase
 *
 * @licence GNU GPL v2+
 * @author Daniel Kinzler
 */
class OutputFormatSnakFormatterFactoryTest extends \PHPUnit_Framework_TestCase {

	private function newOutputFormatSnakFormatterFactory() {
		$self = $this;
		$callbacks = array(
			'VT:string' => function( $format, FormatterOptions $options ) use ( $self ) {
				return $format === SnakFormatter::FORMAT_PLAIN ? $self->makeMockValueFormatter() : null;
			},
		);

		$dataTypeFactory = new DataTypeFactory( array(
			'string' => 'string'
		) );

		$dataTypeLookup = $this->getMock( 'Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup' );
		$dataTypeLookup->expects( $this->any() )
			->method( 'getDataTypeIdForProperty' )
			->will( $this->returnValue( 'string' ) );

		$valueFormatterFactory = new OutputFormatValueFormatterFactory(
			$callbacks,
			Language::factory( 'en' ),
			new LanguageFallbackChainFactory()
		);

		return new OutputFormatSnakFormatterFactory( $valueFormatterFactory, $dataTypeLookup, $dataTypeFactory );
	}

	public function makeMockValueFormatter() {
		$mock = $this->getMock( 'ValueFormatters\ValueFormatter' );

		$mock->expects( $this->any() )
			->method( 'format' )
			->will( $this->returnCallback(
				function( DataValue $value ) {
					return strval( $value->getValue() );
				}
			) );

		return $mock;
	}

	public function getSnakFormatterProvider() {

		return array(
			'plain' => array(
				SnakFormatter::FORMAT_PLAIN,
				new StringValue( 'foo' ),
				'foo',
			),
			'html' => array(
				SnakFormatter::FORMAT_HTML,
				new StringValue( 'b<a>r' ),
				'b&lt;a&gt;r',
			),
		);
	}

	/**
	 * @dataProvider getSnakFormatterProvider
	 */
	public function testGetSnakFormatter( $format, DataValue $value, $expected ) {
		$factory = $this->newOutputFormatSnakFormatterFactory();
		$formatter = $factory->getSnakFormatter( $format, new FormatterOptions() );

		$this->assertInstanceOf( 'Wikibase\Lib\SnakFormatter', $formatter );
		$this->assertEquals( $format, $formatter->getFormat() );

		$snak = new PropertyValueSnak( new PropertyId( 'P5' ), $value );
		$this->assertEquals( $expected, $formatter->formatSnak( $snak ) );
	}

	public function getSnakFormatterProvider_options() {
		return array(
			'default' => array(
				array(),
				'Wikibase\Lib\Formatters\ErrorHandlingSnakFormatter'
			),
			'OPT_ON_ERROR => ON_ERROR_WARN' => array(
				array( SnakFormatter::OPT_ON_ERROR => SnakFormatter::ON_ERROR_WARN ),
				'Wikibase\Lib\Formatters\ErrorHandlingSnakFormatter'
			),
			'OPT_ON_ERROR => ON_ERROR_FAIL' => array(
				array( SnakFormatter::OPT_ON_ERROR => SnakFormatter::ON_ERROR_FAIL ),
				'Wikibase\Lib\DispatchingSnakFormatter'
			),
		);
	}

	/**
	 * @dataProvider getSnakFormatterProvider_options
	 */
	public function testGetSnakFormatter_options( $options, $expectedType ) {
		$factory = $this->newOutputFormatSnakFormatterFactory();
		$formatter = $factory->getSnakFormatter( SnakFormatter::FORMAT_WIKI, new FormatterOptions( $options ) );

		$this->assertInstanceOf( $expectedType, $formatter );
	}

}
