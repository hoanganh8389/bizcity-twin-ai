<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableEm_Chunks extends WaicTable {
	public function __construct() {
		$this->_table = '@__em_chunks';
		$this->_id = 'id';
		$this->_alias = 'waic_em_chunks';
		$this->_addField('id', 'text', 'int')
			 ->_addField('em_id', 'text', 'int')
			 ->_addField('chunk', 'text', 'varchar')
			 ->_addField('status', 'text', 'int')
			 ->_addField('start', 'text', 'text')
			 ->_addField('end', 'text', 'text')
			 ->_addField('step', 'text', 'int')
			 ->_addField('steps', 'text', 'int')
			 ->_addField('updated', 'text', 'text')
			 ->_addField('flag', 'text', 'int')
			 ->_addField('tokens', 'text', 'int')
			 ->_addField('results', 'text', 'text');
	}
}
