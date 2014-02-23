<?php

namespace Tests\Wikibase\DataModel\Deserializers;

use DataValues\Deserializers\DataValueDeserializer;
use DataValues\StringValue;
use Wikibase\DataModel\Deserializers\SnakDeserializer;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyNoValueSnak;
use Wikibase\DataModel\Snak\PropertySomeValueSnak;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * @covers Wikibase\DataModel\Deserializers\SnakDeserializer
 *
 * @licence GNU GPL v2+
 * @author Thomas Pellissier Tanon
 */
class SnakDeserializerTest extends DeserializerBaseTest {

	public function buildDeserializer() {
		$entityIdDeserializerMock = $this->getMock( '\Deserializers\Deserializer' );
		$entityIdDeserializerMock->expects( $this->any() )
			->method( 'deserialize' )
			->with( $this->equalTo( 'P42' ) )
			->will( $this->returnValue( new PropertyId( 'P42' ) ) );

		return new SnakDeserializer(
			new DataValueDeserializer( array (
				'string' => 'DataValues\StringValue',
			) ),
			$entityIdDeserializerMock
		);
	}

	public function deserializableProvider() {
		return array(
			array(
				array(
					'snaktype' => 'novalue',
					'property' => 'P42'
				)
			),
			array(
				array(
					'snaktype' => 'somevalue',
					'property' => 'P42'
				)
			),
			array(
				array(
					'snaktype' => 'value',
					'property' => 'P42',
					'datavalue' => array(
						'type' => 'string',
						'value' => 'hax'
					)
				)
			),
		);
	}

	public function nonDeserializableProvider() {
		return array(
			array(
				42
			),
			array(
				array()
			),
			array(
				array(
					'id' => 'P10'
				)
			),
			array(
				array(
					'snaktype' => '42value'
				)
			),
		);
	}

	public function deserializationProvider() {
		return array(
			array(
				new PropertyNoValueSnak( 42 ),
				array(
					'snaktype' => 'novalue',
					'property' => 'P42'
				)
			),
			array(
				new PropertySomeValueSnak( 42 ),
				array(
					'snaktype' => 'somevalue',
					'property' => 'P42'
				)
			),
			array(
				new PropertyValueSnak( 42, new StringValue( 'hax' ) ),
				array(
					'snaktype' => 'value',
					'property' => 'P42',
					'datavalue' => array(
						'type' => 'string',
						'value' => 'hax'
					)
				)
			),
		);
	}

	/**
	 * @dataProvider invalidDeserializationProvider
	 */
	public function testInvalidSerialization( $serialization ) {
		$this->setExpectedException( '\Deserializers\Exceptions\DeserializationException' );
		$this->buildDeserializer()->deserialize( $serialization );
	}

	public function invalidDeserializationProvider() {
		return array(
			array(
				array(
					'snaktype' => 'somevalue'
				)
			),
			array(
				array(
					'snaktype' => 'value',
					'property' => 'P42'
				)
			),
		);
	}

	public function testDeserializePropertyIdFilterItemId() {
		$entityIdDeserializerMock = $this->getMock( '\Deserializers\Deserializer' );
		$entityIdDeserializerMock->expects( $this->any() )
			->method( 'deserialize' )
			->with( $this->equalTo( 'Q42' ) )
			->will( $this->returnValue( new ItemId( 'Q42' ) ) );
		$deserializer = new SnakDeserializer( new DataValueDeserializer(), $entityIdDeserializerMock );

		$this->setExpectedException( '\Deserializers\Exceptions\InvalidAttributeException' );
		$deserializer->deserialize( array(
			'snaktype' => 'somevalue',
			'property' => 'Q42'
		) );
	}
}