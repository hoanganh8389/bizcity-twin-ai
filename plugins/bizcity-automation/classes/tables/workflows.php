<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableWorkflows extends WaicTable {
	public function __construct() {
		$this->_table = '@__workflows';
		$this->_id = 'id';     /*Let's associate it with posts*/
		$this->_alias = 'waic_workflows';
		$this->_addField('id', 'text', 'int')
			->_addField('task_id', 'text', 'int')
			->_addField('version', 'text', 'int')
			->_addField('params', 'text', 'text')
			->_addField('status', 'text', 'int')
			->_addField('tr_id', 'text', 'int')
			->_addField('tr_code', 'text', 'text')
			->_addField('tr_type', 'text', 'int')
			->_addField('sch_start', 'text', 'text')
			->_addField('sch_period', 'text', 'int')
			->_addField('tr_hook', 'text', 'text')
			->_addField('timeout', 'text', 'int')
			->_addField('flags', 'text', 'text')
			->_addField('created', 'text', 'text')
			->_addField('updated', 'text', 'text');
	}
}
