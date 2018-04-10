<?php

namespace Tainacan\Tests;

use Tainacan\Entities\Collection;
use Tainacan\Entities\Log;

/**
 * Class TestCollections
 *
 * @package Test_Tainacan
 */

/**
 * Sample test case.
 */
class Logs extends TAINACAN_UnitTestCase {


	/**
	 * Teste da insercao de um log simples apenas se criar o dado bruto
	 */
	function test_add() {
		$Tainacan_Logs = \Tainacan\Repositories\Logs::getInstance();
		$Tainacan_Collections = \Tainacan\Repositories\Collections::getInstance();

		$log = $this->tainacan_entity_factory->create_entity(
			'log',
			array(
				'title'       => 'blame someone',
				'description' => 'someone did that'
			),
			true
		);

		$user_id = get_current_user_id();
		$blog_id = get_current_blog_id();

		//retorna a taxonomia
		$test = $Tainacan_Logs->fetch( $log->get_id() );

		$this->assertEquals( 'blame someone', $test->get_title() );
		$this->assertEquals( 'someone did that', $test->get_description() );
		$this->assertEquals( $user_id, $test->get_user_id() );
		$this->assertEquals( $blog_id, $test->get_blog_id() );

		$value = $this->tainacan_entity_factory->create_entity(
			'collection',
			array(
				'name'          => 'testeLogs',
				'description'   => 'adasdasdsa123',
				'default_order' => 'DESC'
			),
			true
		);

		$old_value = $value;

		$value->set_name( 'newtesteLogs' );

		$new_value = $Tainacan_Collections->update( $value );

		$create_log = Log::create( 'teste create', 'testing a log creation function', $new_value, $old_value );

		$this->assertEquals( 'teste create', $create_log->get_title() );
		$this->assertEquals( 'testing a log creation function', $create_log->get_description() );
		$this->assertEquals( $new_value, $create_log->get_value() );
		$this->assertEquals( $old_value, $create_log->get_old_value() );

		$testDB = $Tainacan_Logs->fetch( $create_log->get_id() );

		$this->assertEquals( 'teste create', $testDB->get_title() );
		$this->assertEquals( 'testing a log creation function', $testDB->get_description() );
		$this->assertEquals( $new_value, $testDB->get_value() );
		$this->assertEquals( $old_value, $testDB->get_old_value() );

		$last_log = $Tainacan_Logs->fetch_last();

		$collection = $last_log->get_value();

		$this->assertEquals( 'newtesteLogs', $collection->get_name() );
		$this->assertEquals( 'adasdasdsa123', $collection->get_description() );
		$this->assertEquals( 'DESC', $collection->get_default_order() );
	}

	public function test_log_diff() {
		$Tainacan_Logs    = \Tainacan\Repositories\Logs::getInstance();
		$Tainacan_Filters = \Tainacan\Repositories\Filters::getInstance();

		$filter = $this->tainacan_entity_factory->create_entity(
			'filter',
			array(
				'name'        => 'No name',
			),
			true
		);

		// Modify filter name
		$filter->set_name( 'With name' );

		$Tainacan_Filters->update( $filter );

		$log = $Tainacan_Logs->fetch_last();

		$diff = $log->diff();

		$this->assertEquals( 'With name', "{$diff['name']['new'][0]} {$diff['name']['new'][1]}" );
		$this->assertEquals( 'No name', $diff['name']['old'] );
		$this->assertEquals( 'With', $diff['name']['diff_with_index'][0] );
	}
}