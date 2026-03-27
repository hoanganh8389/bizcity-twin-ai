<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTableFormlogs extends WaicTable {
	public function __construct() {
		$this->_table = '@__formlogs';
		$this->_id = 'id';     /*Let's associate it with posts*/
		$this->_alias = 'waic_formlogs';
		$this->_addField('id', 'text', 'int')
			 ->_addField('his_id', 'text', 'int')
			 ->_addField('question', 'text', 'text')
			 ->_addField('answer', 'text', 'text')
			 ->_addField('file', 'text', 'text');
	}
}
