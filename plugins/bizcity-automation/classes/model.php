<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
abstract class WaicModel extends WaicBaseObject {
	protected $_data = array();
	protected $_code = '';
		
	protected $_orderBy = '';
	protected $_sortOrder = '';
	protected $_groupBy = '';
	protected $_limit = '';
	protected $_where = array();
	protected $_stringWhere = '';
	protected $_selectFields = '*';
	protected $_tbl = '';
	protected $_lastGetCount = 0;
	protected $_idField = 'id';
	protected $_indexes = array();
	// lists for fields values
	public $fieldLists = array();

	public function setCode( $code ) {
		$this->_code = $code;
	}
	public function getCode() {
		return $this->_code;
	}
	public function getModule() {
		return WaicFrame::_()->getModule( $this->_code );
	}
	
	protected function _setTbl( $tbl ) {
		$this->_tbl = $tbl;
	}
	public function setOrderBy( $orderBy ) {
		$this->_orderBy = $orderBy;
		return $this;
	}
	public function setIndexes( $indexes ) {
		$this->_indexes = $indexes;
	}
	/**
	 * ASC, DESC
	 */
	public function setSortOrder( $sortOrder ) {
		$this->_sortOrder = $sortOrder;
		return $this;
	}
	public function setLimit( $limit ) {
		$this->_limit = $limit;
		return $this;
	}
	public function setWhere( $where ) {
		$this->_where = $where;
		return $this;
	}
	public function addWhere( $where ) {
		if (empty($this->_where) && !is_string($where)) {
			$this->setWhere( $where );
		} elseif (is_array($this->_where) && is_array($where)) {
			$this->_where = array_merge($this->_where, $where);
		} elseif (is_string($where)) {
			if (!isset($this->_where['additionalCondition'])) {
				$this->_where['additionalCondition'] = '';
			}
			if (!empty($this->_where['additionalCondition'])) {
				$this->_where['additionalCondition'] .= ' AND ';
			}
			$this->_where['additionalCondition'] .= $where;
		}
		return $this;
	}
	public function setSelectFields( $selectFields ) {
		if (is_array($selectFields)) {
			$selectFields = implode(',', $selectFields);
		}
		$this->_selectFields = $selectFields;
		return $this;
	}
	public function groupBy( $groupBy ) {
		$this->_groupBy = $groupBy;
		return $this;
	}
	public function getLastGetCount() {
		return $this->_lastGetCount;
	}
	public function getTable() {
		return WaicFrame::_()->getTable( $this->_tbl );
	}
	public function getFromTbl( $params = array() ) {
		$this->_lastGetCount = 0;
		$tbl = isset($params['tbl']) ? $params['tbl'] : $this->_tbl;
		$table = WaicFrame::_()->getTable( $tbl );
		$this->_buildQuery( $table );
		$return = isset($params['return']) ? $params['return'] : 'all';
		$data = $table->get($this->_selectFields, $this->_where, '', $return);
		if (!empty($data)) {
			switch ($return) {
				case 'one':
					$this->_lastGetCount = 1;
					break;
				case 'row':
					$data = $this->_afterGetFromTbl( $data );
					$this->_lastGetCount = 1;
					break;
				default:
					foreach ($data as $i => $row) {
						$data[ $i ] = $this->_afterGetFromTbl( $row );
					}
					$this->_lastGetCount = count( $data );
					break;
			}
		}
		$this->_clearQuery( $params );
		return $data;
	}
	protected function _clearQuery( $params = array() ) {
		$clear = isset($params['clear']) ? $params['clear'] : array();
		if (!is_array($clear)) {
			$clear = array($clear);
		}
		if (empty($clear) || in_array('limit', $clear)) {
			$this->_limit = '';
		}
		if (empty($clear) || in_array('orderBy', $clear)) {
			$this->_orderBy = '';
		}
		if (empty($clear) || in_array('sortOrder', $clear)) {
			$this->_sortOrder = '';
		}
		if (empty($clear) || in_array('where', $clear)) {
			$this->_where = array();
		}
		if (empty($clear) || in_array('selectFields', $clear)) {
			$this->_selectFields = '*';
		}
		if (empty($clear) || in_array('groupBy', $clear)) {
			$this->_groupBy = '';
		}
	}
	public function getCount( $params = array() ) {
		$tbl = isset($params['tbl']) ? $params['tbl'] : $this->_tbl;
		$table = WaicFrame::_()->getTable( $tbl );
		$this->setSelectFields('COUNT(*) AS total');
		$this->_buildQuery( $table );
		$data = (int) $table->get($this->_selectFields, $this->_where, '', 'one');
		$this->_clearQuery($params);
		return $data;
	}
	protected function _afterGetFromTbl( $row ) {
		// You can re-define this method in your own model
		return $row;
	}
	protected function _buildQuery( $table = null ) {
		if (!$table) {
			$table = WaicFrame::_()->getTable( $this->_tbl );
		}
		if (!empty($this->_orderBy)) {
			$order = $this->_orderBy;
			if (!empty($this->_sortOrder)) {
				$order .= ' ' . strtoupper($this->_sortOrder);
			}
			$table->orderBy( $order );
		}
		if (!empty($this->_groupBy)) {
			$table->groupBy($this->_groupBy);
		}
		if (!empty($this->_limit)) {
			$table->setLimit($this->_limit);
		}
	}
	public function removeGroup( $ids ) {
		if (!is_array($ids)) {
			$ids = array($ids);
		}
		// Remove all empty values
		$ids = array_filter(array_map('intval', $ids));
		if (!empty($ids)) {
			if (WaicFrame::_()->getTable($this->_tbl)->delete(array('additionalCondition' => 'id IN (' . implode(',', $ids) . ')'))) {
				$this->_dataRemove($ids);
				return true;
			} else {
				$this->pushError(esc_html__('Database error detected', 'ai-copilot-content-generator'));
			}
		} else {
			$this->pushError(esc_html__('Invalid ID', 'ai-copilot-content-generator'));
		}
		return false;
	}
	public function clear() {
		return $this->delete(); // Just delete all
	}
	public function delete( $params = array() ) {
		if (WaicFrame::_()->getTable($this->_tbl)->delete($params)) {
			if (is_numeric($params)) {
				$this->_dataRemove(array($params));
			}
			return true;
		} else {
			$this->pushError(esc_html__('Database error detected', 'ai-copilot-content-generator'));
		}
		return false;
	}
	public function getById( $id ) {
		$data = $this->setWhere(array($this->_idField => $id))->getFromTbl();
		return empty($data) ? false : array_shift($data);
	}
	public function insert( $data ) {
		$data = $this->_dataSave($data, false);
		$id = WaicFrame::_()->getTable($this->_tbl)->insert($data);
		if ($id) {
			return $id;
		}
		$this->pushError(WaicFrame::_()->getTable($this->_tbl)->getErrors());
		return false;
	}
	public function updateById( $data, $id = 0 ) {
		if (!$id) {
			$id = isset($data[ $this->_idField ]) ? (int) $data[ $this->_idField ] : 0;
		}
		if ($id) {
			return $this->update($data, array($this->_idField => $id));
		} else {
			$this->pushError(esc_html__('Empty or invalid ID', 'ai-copilot-content-generator'));
		}
		return false;
	}
	public function update( $data, $where ) {
		$data = $this->_dataSave($data, true);
		if (WaicFrame::_()->getTable($this->_tbl)->update($data, $where)) {
			return true;
		}
		$this->pushError(WaicFrame::_()->getTable($this->_tbl)->getErrors());
		return false;
	}
	protected function _dataSave( $data, $update = false ) {
		return $data;
	}
	public function getTbl() {
		return $this->_tbl;
	}
	/**
	 * We can re-define this method to not retrive all data - for simple tables
	 */
	public function setSimpleGetFields() {
		return $this;
	}
	protected function setFieldLists( $list ) {
		$this->fieldLists = $list;
		$values = array();
		foreach ($list as $field => $data) {
			$values[$field] = array_keys($data);
		}
		$this->getTable()->setLists($values);
	}
	public function getFieldLists( $field, $key = false ) {
		$list = WaicUtils::getArrayValue($this->fieldLists, $field, array(), 2);
		return false === $key ? $list : WaicUtils::getArrayValue($list, $key);
	}
	public function getFieldKeys( $field ) {
		$list = WaicUtils::getArrayValue($this->fieldLists, $field, array(), 2);
		return is_array($list) ? array_keys($list) : $list;
	}
	protected function _dataRemove( $ids ) {
		return false;
	}
	public function dropIndexes( $withPrimary = false ) {
		$table = $this->_tbl;
		$indexes = WaicDb::get('SHOW INDEX FROM `@__' . $table . '`');
		if (!$indexes) {
			$this->pushError(WaicDb::getError());
			return false;
		}

		$drop = array();
		foreach ($indexes as $index) {
			$name = $index['Key_name'];
			if ($withPrimary || 'PRIMARY' != $name) {
				$drop[] = ' DROP INDEX ' . $name;
			}
		}
		if (!empty($drop)) {
			if (!WaicDb::query('ALTER TABLE `@__' . $table . '`' . implode(',', array_unique($drop)))) {
				$this->pushError(WaicDb::getError());
				return false;
			}
		}
		return true;
	}
	public function addIndexes( $delete = true ) {
		if (empty($this->_indexes)) {
			return true;
		}
		$table = $this->_tbl;
		$indexes = WaicDb::get('SHOW INDEX FROM `@__' . $table . '`');
		if (!$indexes) {
			$this->pushError(WaicDb::getError());
			return false;
		}
		$exists = array();
		foreach ($indexes as $index) {
			$exists[] = $index['Key_name'];
		}

		$alter = '';
		foreach ($this->_indexes as $name => $index) {
			if (!in_array($name, $exists)) {
				$alter .= ' ADD ' . $index . ',';
			}
		}
		if (!empty($alter)) {
			if (!WaicDb::query('ALTER TABLE `@__' . $table . '`' . substr($alter, 0, -1))) {
				$this->pushError(WaicDb::getError());
				if ($delete) {
					$this->delete();
				}
				return false;
			}
		}
		return true;
	}
}
