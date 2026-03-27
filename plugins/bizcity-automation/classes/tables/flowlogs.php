<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableFlowlogs extends WaicTable {
	public function __construct() {
		$this->_table = '@__flowlogs';
		$this->_id = 'id';     /*Let's associate it with posts*/
		$this->_alias = 'waic_flowlogs';
		$this->_addField('id', 'text', 'int')
			->_addField('run_id', 'text', 'int')
			->_addField('bl_type', 'text', 'int')
			->_addField('bl_id', 'text', 'int')
			->_addField('bl_code', 'text', 'text')
			->_addField('parent', 'text', 'int')
			->_addField('step', 'text', 'int')
			->_addField('cnt', 'text', 'int')
			->_addField('status', 'text', 'int')
			->_addField('result', 'text', 'text')
			->_addField('started', 'text', 'text')
			->_addField('ended', 'text', 'text')
			->_addField('error', 'text', 'text');
	}
}
