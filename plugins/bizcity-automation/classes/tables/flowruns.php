<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableFlowruns extends WaicTable {
	public function __construct() {
		$this->_table = '@__flowruns';
		$this->_id = 'id';     /*Let's associate it with posts*/
		$this->_alias = 'waic_flowruns';
		$this->_addField('id', 'text', 'int')
			->_addField('task_id', 'text', 'int')
			->_addField('fl_id', 'text', 'int')
			->_addField('status', 'text', 'int')
			->_addField('params', 'text', 'text')
			->_addField('obj_id', 'text', 'int')
			->_addField('tokens', 'text', 'int')
			->_addField('added', 'text', 'text')
			->_addField('started', 'text', 'text')
			->_addField('ended', 'text', 'text')
			->_addField('log_id', 'text', 'int')
			->_addField('waiting', 'text', 'int')
			->_addField('error', 'text', 'error');
	}
}
