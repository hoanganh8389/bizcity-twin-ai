<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicWorkflowModel extends WaicModel {
	private $_blocksPath = null;
	private $_customPath = null;
	private $_blClasses = array();
	private $_blocks = array();
	private $_blTypes = array('trigger', 'action', 'logic');
	private $_blCodes = null;
	private $_blCategories = null;
	private $_statuses = null;
	public $trTypes = array(0 => 'manual', 1 => 'scheduled', 2 => 'wphook', 3 => 'webhook', 4 => 'url');
	public $lgTypes = array(1 => 'waiting', 2 => 'if', 3 => 'loop', 4 => 'stop_loop', 5 => 'stop');
	
	private $workspace = false;
	private $runModel = false;
	private $logModel = false;
	private $runningFlowId = 11;
	private $runningTimeout = 300; //sec
	private $sendError = false;
	
	public function __construct() {
		$this->_setTbl('workflows');
	}

	public function getStatuses( $st = null ) {
		if (is_null($this->_statuses)) {
			$this->_statuses = array(
				1 => __('Waiting', 'ai-copilot-content-generator'), /*task published & wait*/
				3 => __('Сompleted', 'ai-copilot-content-generator'), /*flow completed (scheduled once)*/
				6 => __('Stopped', 'ai-copilot-content-generator'), /*stopped by tokens limit*/
				7 => __('Error', 'ai-copilot-content-generator'),
				9 => __('Canceled', 'ai-copilot-content-generator'), /*task unpublished or old version*/
			);
		}
		return is_null($st) ? $this->_statuses : ( isset($this->_statuses[$st]) ? $this->_statuses[$st] : '' );
	}
	
	public function canUnpublish() {
		return true;
	}
	public function unpublishEtaps( $taskId, $getStatus = false ) {
		$taskId = (int) $taskId;
		$taskModel = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		$task = $taskModel->getById($taskId);
		
		if ($task && $taskModel->isPublished($task['status'])) {
			$this->unpublishFlows($taskId);
			$taskModel->updateTask($taskId, array('status' => 6));
		}
		return $getStatus ? 6 : false;
	}
	public function publishResults( $taskId, $publish = 0, $getStatus = false ) {
		$taskId = (int) $taskId;
		$taskModel = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		$task = $taskModel->getById($taskId);
		if ($task && !$taskModel->isPublished($task['status'])) {
			$version = (int) $task['mode'];
			$published = false;
			if (!empty($version)) {
				$oldParams = WaicDb::get('SELECT params FROM `@__workflows` WHERE task_id=' . $taskId . ' AND version=' . $version . ' LIMIT 1', 'one');
				if ($oldParams == $task['params']) {
					$this->publishFlows($taskId, $version);
					$published = true;
				}
			} 
			if (!$published) {
				$version++;
				$this->delete(array('task_id' => $taskId, 'version' => $version));
				$this->unpublishFlows($taskId);
				$params = WaicUtils::getArrayValue($task, 'params');
				$flowBlocks = WaicUtils::jsonDecode($params);
				$params = WaicUtils::jsonEncode($flowBlocks, true);
				$nodes = WaicUtils::getArrayValue($flowBlocks, 'nodes', array(), 2);
				if (!empty($nodes)) {
					$triggers = $this->getFlowBlocks($nodes, 'trigger');
					$settings = WaicUtils::getArrayValue($flowBlocks, 'settings', array(), 2);
					$now = WaicUtils::getTimestampDB();
					foreach ($triggers as $trigger) {
						$block = $this->getFlowBlock($trigger);
						if ($block) {
							$flags = '';
							foreach (array('multiple', 'skip') as $f) {
								$flags .= WaicUtils::getArrayValue($settings, $f, 0, 1) ? '1' : '0';
							}
							$flow = array(
								'task_id' => $taskId,
								'version' => $version,
								'params' => $params,
								'status' => 1,
								'tr_id' => $block->getId(),
								'tr_code' => substr($block->getCode(), 0, 30),
								'tr_type' => $block->getSubType(),
								'sch_start' => $block->getSchStart(),
								'sch_period' => $block->getPeriod($settings),
								'tr_hook' => $block->getHook(),
								'timeout' => WaicUtils::getArrayValue($settings, 'timeout', 0, 1),
								'flags' => $flags,
								'created' => $now,
							);
							$id = $this->insert($flow);
						}
					}
				}
			}
			$taskModel->updateTask($taskId, array('status' => 4, 'mode' => $version));
		}
		$this->doScheduledFlows($taskId);
		$this->getModule()->runCronEvents(true);
		return $getStatus ? 4 : false;
	}

	public function clearEtaps( $taskId, $ids = false, $withContent = true ) {
		$taskId = (int) $taskId;
		$query = 'DELETE l FROM `@__flowlogs` l JOIN `@__flowruns` r ON (l.run_id = r.id) WHERE r.task_id=' . $taskId;
		WaicDb::query($query);
		$this->getModule()->getModel('flowruns')->delete(array('task_id' => $taskId));
		$this->delete(array('task_id' => $taskId));
		return true;
	}
	public function getFlowBlocks( $nodes, $typ = 'trigger' ) {
		$blocks = array();
		foreach ($nodes as $node) {
			if (isset($node['type']) && ( $typ == $node['type'])) {
				$blocks[] = $node;
			}
		}
		return $blocks;
	}
	public function getNextNodeId( $edges, $cutNodeId, $sourceHandle ) {
		foreach ($edges as $edge) {
			if (isset($edge['source']) && ( $cutNodeId == $edge['source']) && ( $sourceHandle == $edge['sourceHandle'] )) {
				return isset($edge['target']) ? $edge['target'] : false;
			}
		}
		return false;
	}
	public function getNodeById( $nodes, $id ) {
		foreach ($nodes as $node) {
			if (isset($node['id']) && ( $id == $node['id'])) {
				return $node;
			}
		}
		return false;
	}
	
	public function unpublishFlows( $taskId ) {
		$taskId = (int) $taskId;
		$this->update(array('status' => 9, 'updated' => WaicUtils::getTimestampDB()), array('task_id' => $taskId, 'status' => 1));
		$this->getModule()->getModel('flowruns')->cancelRuns($taskId);
		return true;
	}
	public function publishFlows( $taskId, $version ) {
		$taskId = (int) $taskId;
		$this->update(array('status' => 1, 'updated' => WaicUtils::getTimestampDB()), array('task_id' => $taskId, 'version' => $version));
		
		return true;
	}
	
	public function existScheduledFlows() {
		$exists = WaicDb::get('SELECT 1 FROM `@__workflows` WHERE status=1 AND tr_type=1 LIMIT 1', 'one');
		return $exists ? 1 : 0;
	}
	
	public function getBlocksPath() {
		if (is_null($this->_blocksPath)) {
			$this->_blocksPath = $this->getModule()->getModDir() . 'blocks' . WAIC_DS;
		}
		return $this->_blocksPath;
	}
	public function getCustomBlocksPath() {
		if (is_null($this->_customPath)) {
			$path = WaicFrame::_()->getModule('options')->get('plugin', 'blocks_path');
			$this->_customPath = ( empty($path) || !is_dir(ABSPATH . $path) ? false : ABSPATH . $path );
		}
		return $this->_customPath;
	}
	private function loadAllBlocks() {
		$this->_blCodes = array();
		$pathes = array($this->getCustomBlocksPath(), $this->getBlocksPath());
		foreach ($pathes as $path) {
			if (empty($path)) {
				continue;
			}
			foreach ($this->_blTypes as $typ) {
				$dir = $path . $typ . 's';
				//if (is_file($path . $typ . '.php') && is_dir($dir)) {
				if (is_dir($dir)) {
					$blocks = array();
					$categories = array();
					$files = scandir($dir);
					foreach ($files as $file) {
						if ($file === '.' || $file === '..') {
							continue;
						}
						if (is_file($dir . WAIC_DS . $file)) {
							$file = str_replace('.php', '', $file);
							$pos = strpos($file, '_');
							if ($pos) {
								$cat = substr($file, 0, $pos);
								//$code = substr($file, $pos + 1);
								$code = $file;
								if (empty($blocks[$code])) {
									if (!isset($categories[$cat])) {
										$categories[$cat] = array();
									}
									$categories[$cat][] = $code;
									$blocks[$code] = $cat;
								}
							}
						}
					}
					$typS = $typ . 's';
					if (isset($this->_blCodes[$typS])) {
						$this->_blCodes[$typS]['codes'] = array_merge($blocks, $this->_blCodes[$typS]['codes']);
						foreach ($categories as $c => $a) {
							if (isset($this->_blCodes[$typS]['cats'][$c])) {
								$this->_blCodes[$typS]['cats'][$c] = array_merge($a, $this->_blCodes[$typS]['cats'][$c]);
							} else {
								$this->_blCodes[$typS]['cats'][$c] = $a;
							}
						}
					} else {
						$this->_blCodes[$typS] = array('codes' => $blocks, 'cats' => $categories);
					}
				}
			}
		}
	}
	
	public function getAllBlocksCodes( $typ = null ) {
		if (is_null($this->_blCodes)) {
			$this->loadAllBlocks();
		}
		if (is_null($typ)) {
			return $this->_blCodes;
		}
		return isset($this->_blCodes[$typ]) && isset($this->_blCodes[$typ]['codes']) ? $this->_blCodes[$typ]['codes'] : array();
	}
	public function getAllBlocksCategories( $typ = null ) {
		if (is_null($this->_blCodes)) {
			$this->loadAllBlocks();
		}
		if (is_null($typ)) {
			return $this->_blCodes;
		}
		return isset($this->_blCodes[$typ]) && isset($this->_blCodes[$typ]['cats']) ? $this->_blCodes[$typ]['cats'] : array();
	}
	
	public function getDefBlock( $typ, $code ) {
		if (empty($typ) || empty($code) || !in_array($typ, $this->_blTypes)) {
			return false;
		}
		if (!isset($this->_blocks[$typ])) {
			$this->_blocks[$typ] = array();
		}
		if (!isset($this->_blocks[$typ][$code])) {
			$blockClass = $this->getBlockClass($typ, $code);
			$this->_blocks[$typ][$code] = class_exists($blockClass) ? new $blockClass() : false;
		}
		return $this->_blocks[$typ][$code];
	}
	public function getFlowBlock( $block ) {
		$data = WaicUtils::getArrayValue($block, 'data', array(), 2);
		$typ = WaicUtils::getArrayValue($data, 'type');
		$code = WaicUtils::getArrayValue($data, 'code');
		
		$blockClass = $this->getBlockClass($typ, $code);
		return class_exists($blockClass) ? new $blockClass($block) : false;
	}
	
	public function getBlockClass( $typ, $code ) {
		if (!isset($_blockClasses[$typ])) {
			waicImportClass(waicStrFirstUp(WAIC_CODE) . waicStrFirstUp($typ), $this->getBlocksPath() . $typ . '.php');
			$_blockClasses[$typ] = array();
		}
		if (!isset($_blockClasses[$typ][$code])) {
			$name = waicStrFirstUp(WAIC_CODE) . waicStrFirstUp($typ) . '_' . $code;
			$file = $typ . 's/' . $code . '.php';
			$custom = $this->getCustomBlocksPath();
			$found = false;
			if ($custom) {
				$found = waicImportClass($name, $custom . $file);
			}
			if (!$found) {
				$found = waicImportClass($name, $this->getBlocksPath() . $file);
			}
			$_blockClasses[$typ][$code] = $name;
		}
		return $_blockClasses[$typ][$code];
	}
	
	public function getBlocksCategories() {
		if (is_null($this->_blCategories)) {
			$this->_blCategories = array(
				'triggers' => array(
					'un' => array(),
					'sy' => array(
						'name' => __('Core Triggers', 'ai-copilot-content-generator'),
						'desc' => __('Manual, Schedule, Webhooks, Url etc', 'ai-copilot-content-generator'),
					),
					'wp' => array(
						'name' => __('WordPress Events', 'ai-copilot-content-generator'),
						'desc' => __('User registration, post publishing, comment submission, etc', 'ai-copilot-content-generator'),
					),
					'wc' => array(
						'name' => __('WooCommerce Events', 'ai-copilot-content-generator'),
						'desc' => __('New orders, payment completion, stock changes, etc.', 'ai-copilot-content-generator'),
					),
					'wu' => array(
						'name' => __('AIWU Events', 'ai-copilot-content-generator'),
						'desc' => __('Internal triggers for interaction between Workflows', 'ai-copilot-content-generator'),
					),
				),
				'actions' => array(
					'un' => array(),
					'ai' => array(
						'name' => __('AI Actions', 'ai-copilot-content-generator'),
						'desc' => __('AI text generation, DALL-E images', 'ai-copilot-content-generator'),
					),
					'wp' => array(
						'name' => __('WordPress Actions', 'ai-copilot-content-generator'),
						'desc' => __('Send emails, сreate posts, update users, manage content', 'ai-copilot-content-generator'),
					),
					'wc' => array(
						'name' => __('WooCommerce Actions', 'ai-copilot-content-generator'),
						'desc' => __('Manage orders, products, and customers', 'ai-copilot-content-generator'),
					),
					'em' => array(
						'name' => __('Email Providers', 'ai-copilot-content-generator'),
						'desc' => __('Send emails', 'ai-copilot-content-generator'),
					),
					'ca' => array(
						'name' => __('Calendar & Meetings', 'ai-copilot-content-generator'),
						'desc' => __('Create Calendar Events and Meetings', 'ai-copilot-content-generator'),
					),
					'sl' => array(
						'name' => __('Slack Actions', 'ai-copilot-content-generator'),
						'desc' => __('Send Slack Message', 'ai-copilot-content-generator'),
					),
					'te' => array(
						'name' => __('Telegram Actions', 'ai-copilot-content-generator'),
						'desc' => __('Send Telegram Message', 'ai-copilot-content-generator'),
					),
					'di' => array(
						'name' => __('Discord Actions', 'ai-copilot-content-generator'),
						'desc' => __('Send messages and embeds to Discord channels', 'ai-copilot-content-generator'),
					),
					'db' => array(
						'name' => __('Database Actions', 'ai-copilot-content-generator'),
						'desc' => __('Execute SQL queries', 'ai-copilot-content-generator'),
					),
				),
				'logics' => array(
					'lp' => array(
						'name' => __('Loops', 'ai-copilot-content-generator'),
						'desc' => __('Process multiple items one by one', 'ai-copilot-content-generator'),
					),
					'un' => array(),
				),
			);
			$this->_blCategories = WaicDispatcher::applyFilters('getBlocksCategories', $this->_blCategories);
		}
		return $this->_blCategories;
	}

	public function getAllBlocksSettings() {
		$blocks = array();
		$categories = $this->getBlocksCategories();
		foreach ($this->_blTypes as $typ) {
			$key = $typ . 's';
			$typCategories = $this->getAllBlocksCategories($key);
			$bls = array();
			if (!empty($typCategories) && !empty($categories[$key])) {
				foreach ($categories[$key] as $cat => $catData) {
					if (!empty($typCategories[$cat])) {
						$list = array();
						foreach ($typCategories[$cat] as $code) {
							$block = $this->getDefBlock($typ, $code);
							if ($block) {
								$list[] = array(
									'code' => $code,
									'name' => $block->getName(),
									'desc' => $block->getDesc(),
									'settings' => $block->getSettings(),
									'variables' => $block->getVariables(),
									'sublabel' => $block->getSublabel(),
									'order' => $block->getOrder(),
								);
							}
						}
						if (!empty($list)) {
							usort($list, function($a, $b) {
								return $a['order'] <=> $b['order'];
							});
							$bls[$cat] = $catData;
							$bls[$cat]['list'] = $list;
						}
					}
				}
			}
			$blocks[$key] = $bls;
		}
		return $blocks;
	}
	public function getWorkflowSettings() {
		$yesNo = array(0 => __('no', 'ai-copilot-content-generator'), 1 => __('yes', 'ai-copilot-content-generator'));
		$settings = array(
			'timeout' => array(
				'type' => 'number',
				'label' => __('Max time allowed for workflow completion (sec)', 'ai-copilot-content-generator'),
				'default' => 300,
				'min' => 10,
			),
			'multiple' => array(
				'type' => 'select',
				'label' => __('Allow the same trigger to run multiple times', 'ai-copilot-content-generator'),
				'default' => 0,
				'options' => $yesNo,
			),
			'cooldown' => array(
				'type' => 'number',
				'label' => __('Cooldown period between runs', 'ai-copilot-content-generator'),
				'default' => 0,
				'add' => array('units'),
			),
			'units' => array(
				'type' => 'select',
				'label' => '',
				'default' => 'd',
				'options' => array('d' => 'Days', 'h' => 'Hours', 'm' => 'Minutes'),
				'inner' => true,
			),
			'skip' => array(
				'type' => 'select',
				'label' => __('Skip if already running', 'ai-copilot-content-generator'),
				'default' => 0,
				'options' => $yesNo,
			),
			'stop' => array(
				'type' => 'select',
				'label' => __('Stop workflow on error', 'ai-copilot-content-generator'),
				'default' => 'yes',
				'options' => array(
					'yes' => __('yes', 'ai-copilot-content-generator'),
					'no' => __('no', 'ai-copilot-content-generator'), 
					'in_loop' => __('only in the loop', 'ai-copilot-content-generator'),
					'out_loop' => __('only outside the loop', 'ai-copilot-content-generator'),
				),
			),
			'send' => array(
				'type' => 'select',
				'label' => __('Send error notifications', 'ai-copilot-content-generator'),
				'default' => 0,
				'options' => $yesNo,
			),
			'ai_all' => array(
				'type' => 'number',
				'label' => __('Max AI requests all time', 'ai-copilot-content-generator'),
				'default' => 0,
				'min' => 0,
			),
			'ai_run' => array(
				'type' => 'number',
				'label' => __('Max AI requests per run', 'ai-copilot-content-generator'),
				'default' => 0,
				'min' => 0,
			),
		);
		
		return $settings;
	}
	public function hasPath( $fromId, $toId, $edges, &$visited = [] ) {
		if ($fromId === $toId && !empty($visited)) return true;
		if (in_array($fromId, $visited)) return false;

		$visited[] = $fromId;

		foreach ($edges as $edge) {
			if ($edge['source'] === $fromId) {
				if ($this->hasPath($edge['target'], $toId, $edges, $visited)) {
					return true;
				}
			}
		}
		return false;
	}
	public function getDescendants($nodeId, $edges, &$visited = []) {
		$descendants = array();

		if (in_array($nodeId, $visited)) return array();
		$visited[] = $nodeId;

		foreach ($edges as $edge) {
			if ($edge['source'] === $nodeId) {
				$descendants[] = $edge['target'];
				$descendants = array_merge($descendants, $this->getDescendants($edge['target'], $edges, $visited));
			}
		}

		return $descendants;
	}
	
	public function controlTaskParameters( $params, &$error = '', &$errNodes = array(), $taskId = 0 ) {
		if (!WaicFrame::_()->isPro()) {
			$cnt = WaicFrame::_()->getModule('workspace')->getModel('tasks')->getCountTasksByParams(array('feature' => 'workflow', 'additionalCondition' => 'id!=' . ((int) $taskId)));
			if ($cnt && !is_null($cnt) && $cnt > 3) {
				$error = __('To get access to creating more than three workflows, you need to purchase and receive the Pro version of the plugin.', 'ai-copilot-content-generator');
				return false;
			}
		}
		$nodes = WaicUtils::getArrayValue($params, 'nodes', array(), 2);
		$edges = WaicUtils::getArrayValue($params, 'edges', array(), 2);
		if (count($nodes) == 0 || count($edges) == 0) {
			return true;
		}
		$loopNodes = array();
		$endNodes = array();

		foreach ($nodes as $node) {
			$nodeId = $node['id'];
			$nodeData = WaicUtils::getArrayValue($node, 'data', array(), 2);
			if (empty($nodeData)) {
				$error = __('A source node [' . $nodeId . '] has a corrupted structure.', 'ai-copilot-content-generator');
				return false;
			}
			
			$startEdges = array_filter($edges, function($e) use ($nodeId) {
				return $e['source'] === $nodeId && $e['sourceHandle'] === 'output-right';
			});
			if (count($startEdges) > 1) {
				$error = __('A source node [' . $nodeId . '] can only be linked to one target.', 'ai-copilot-content-generator');
				return false;
			}
			$elseEdges = array_filter($edges, function($e) use ($nodeId) {
				return $e['source'] === $nodeId && $e['sourceHandle'] === 'output-else';
			});
			if (count($elseEdges) > 1) {
				$error = __('A source node [' . $nodeId . '] from each output can only be connected to one target.', 'ai-copilot-content-generator');
				return false;
			}
			$thenEdges = array_filter($edges, function($e) use ($nodeId) {
				return $e['source'] === $nodeId && $e['sourceHandle'] === 'output-then';
			});
			if (count($thenEdges) > 1) {
				$error = __('A source node [' . $nodeId . '] from each output can only be linked to one target.', 'ai-copilot-content-generator');
				return false;
			}
			if ($this->hasPath($nodeId, $nodeId, $edges)) {
				$error = __('Noda [' . $nodeId . '] is cyclical or in cycle.', 'ai-copilot-content-generator');
				return false;
			}
			$settings = WaicUtils::getArrayValue($nodeData, 'settings', array(), 2);
			
			foreach ($settings as $k => $v) {
				if (!is_array($v)) {
					preg_match_all('/\{\{node#(\d+)\.[^}]+\}\}/', $v, $matches);
					if (!empty($matches[1])) {
						foreach ($matches[1] as $nId) {
							if (!$this->hasPath($nId, $nodeId, $edges)) {
								$errNodes[] = array($nodeId, $k);
								$error = __('Noda [' . $nodeId . '] has an invalid variable - reference to a non-existent node.', 'ai-copilot-content-generator');
								return false;
							}
						}
					}
				}
			}
			
			// if loop
			if (isset($nodeData['category']) && $nodeData['category'] === 'lp') {
				if (!empty($thenEdges)) {
					$startId = array_values($thenEdges)[0]['target'];
					$loopNodes[$nodeId] = array_merge(array($startId), $this->getDescendants($startId, $edges));
				} else {
					$loopNodes[$nodeId] = array();
				}
				if (!empty($elseEdges)) {
					$startId = array_values($elseEdges)[0]['target'];
					$endNodes[$nodeId] = array_merge(array($startId), $this->getDescendants($startId, $edges));
				} else {
					$endNodes[$nodeId] = array();
				}
			}
		}
		foreach ($loopNodes as $loopId => $children) {
			foreach ($children as $nodeId => $child) {
				if (isset($loopNodes[$child])) {
					$error = __('Nested loops [' . $child . '] are not permitted.', 'ai-copilot-content-generator');
					return false;
				}
				foreach ($loopNodes as $lpId => $chs) {
					if ($lpId != $loopId && in_array($child, $chs)) {
						$error = __('The Loop branch must be isolated and have no connections to nodes outside the branch. Problem node [' . $child . ']', 'ai-copilot-content-generator');
						return false;
					}
				}
				foreach ($endNodes as $lpId => $chs) {
					if (in_array($child, $chs)) {
						$error = __('The Loop branch must be isolated and nodes inside the loop should not be able to reach the END output. Problem node [' . $child . ']', 'ai-copilot-content-generator');
						return false;
					}
				}
			}
		}

		return empty($error);
	}
	
	public function convertTaskParameters( $params, $toDB = true ) {
		if (!$toDB) {
			$nodes = WaicUtils::getArrayValue($params, 'nodes', array(), 2);
			foreach ($nodes as $n => $node) {
				$data = WaicUtils::getArrayValue($node, 'data', array(), 2);
				$settings = WaicUtils::getArrayValue($data, 'settings', array(), 2);
				foreach ($settings as $k => $v) {
					if (!is_array($v)) {
						$nodes[$n]['data']['settings'][$k] = html_entity_decode(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
					}
				}
			}
			$params['nodes'] = $nodes;
		}
		return $params;
	}
	 
	public function doScheduledFlows( $taskId = 0 ) {

		$now = WaicUtils::getTimestampDB();
		$select = 'SELECT id, task_id, tr_type, sch_period, flags FROM `@__workflows`' .
			' WHERE status=1 AND tr_type<=1' .
			' AND (tr_type=0 OR (tr_type=1 AND sch_start IS NOT NULL' .
			" AND sch_start<='" . $now . "'))" .
			( empty($taskId) ? '' : ' AND task_id=' . ( (int) $taskId ) ) .
			' ORDER BY tr_type, sch_start';
		$flows = WaicDb::get($select);
		if ($flows) {
			$runsModel = $this->getModule()->getModel('flowruns');
			foreach ($flows as $flow) {
				$id = $flow['id'];
				$skip = $this->needSkip($flow['flags'], $id);
				if (!$skip && $runsModel->createRun($flow['task_id'], $id, array('date' => date('Y-m-d'), 'time' => date('H:i:s')))) {
					$period = $flow['sch_period'];
					if (empty($period)) {
						$this->updateById(array('status' => 3, 'updated' => $now), $id);
					} else {
						WaicDb::query("UPDATE `@__workflows` SET sch_start=TIMESTAMPADD(SECOND,sch_period,'" . $now . "'), updated='" . $now ."' WHERE id=" . $id);
					}
				}
			}
			if (empty($taskId)) {
				$this->doFlowRuns();
			}
		}
		return true;
	}
	public function isRunningFlow() {
		$option = $this->workspace->getWorkspaceFlagById($this->runningFlowId);
		if (is_null($option) || empty($option)) {
			return true;
		}
		if (empty((int) $option['value'])) {
			return false;
		} 
		if ($option['flag'] + $option['timeout'] < WaicUtils::getTimestamp()) {
				$this->runModel->stopRun((int) $option['value'], 6, 'Stopped by timeout');
				$this->workspace->resetRunningFlag($this->runningFlowId);
			return false;
		}
		return true;
	}
	public function setRunningFlow( $data ) {
		if (!$this->isRunningFlow()) {
			return $this->workspace->updateById($data, $this->runningFlowId);
		}
		return false;
	}
	public function doFlowRuns() {
		$this->workspace = WaicFrame::_()->getModule('workspace')->getModel();
		$this->runModel = $this->getModule()->getModel('flowruns');
		
		if ($this->isRunningFlow()) {
			return true;
		}
		set_time_limit(0);
		do {
			$run = $this->runModel->setWhere(array('status' => 1, 'additionalCondition' => 'waiting<' . WaicUtils::getTimestamp()))->setSortOrder('id')->setLimit(1)->getFromTbl(array('return' => 'row'));
			if ($run) {
				$result = $this->doFlowRun($run);
				if (false === $result) {
					break;
				} else {
					$this->workspace->resetRunningFlag($this->runningFlowId);
					if (7 === $result || 6 === $result) {
						$this->runModel->stopRun($run['id'], $result, WaicFrame::_()->getLastError()); 
						if ($this->sendError) {
							$this->sendAdminError($run['task_id'], WaicFrame::_()->getLastError());
						}
					}
					WaicDispatcher::doAction('afterWorkflowEnded', $run['id']);
				}
			} else {
				break;
			}
		} while (true);
		$this->workspace->resetRunningFlag($this->runningFlowId);
		return true;
	}
	
	public function doFlowRun( $run ) {
		$flId = (int) $run['fl_id'];
		$flow = $this->getFlow($flId);
		$timeout = (int) $flow['timeout'];
		$runId = $run['id'];
		if (!$this->setRunningFlow(array('value' => $runId, 'flag' => WaicUtils::getTimestamp(), 'timeout' => empty($timeout) ? $this->runningTimeout : $timeout))) {
			return false;
		}
		$this->runModel->startRun($runId);
		$taskId = (int) $flow['task_id'];
		$flowParams = WaicUtils::getArrayValue($flow, 'params');

		$nodes = WaicUtils::getArrayValue($flowParams, 'nodes', array(), 2);
		if (empty($nodes)) {
			WaicFrame::_()->pushError('There are no nodes found');
			return 7;
		}
		$edges = WaicUtils::getArrayValue($flowParams, 'edges', array(), 2);
		if (empty($edges)) {
			WaicFrame::_()->pushError('There are no edges found');
			return 7;
		}
		$settings = WaicUtils::getArrayValue($flowParams, 'settings', array(), 2);
		$this->sendError = WaicUtils::getArrayValue($settings, 'send', 0, 1) == 1;

		$maxAIAll = WaicUtils::getArrayValue($settings, 'ai_all', 0, 1);
		$maxAIRun = WaicUtils::getArrayValue($settings, 'ai_run', 0, 1);
		$saveToken = ( $maxAIAll > 0 || $maxAIRun > 0 );
		$runTokens = $run['tokens'];
		$flowTokens = ( $maxAIAll ? $this->runModel->getTokens($flId) : 0 );
		
		if ($maxAIAll && $flowTokens > $maxAIAll) {
			WaicFrame::_()->pushError('Stopped by flow tokens limit (' . $flowTokens . ' > ' . $maxAIAll . ')');
			if (1 == $flow['status']) {
				$this->updateById(array('status' => 6, 'updated' => WaicUtils::getTimestampDB()), $flId);
			}
			return 6;
		}
		
		$stopRule = WaicUtils::getArrayValue($settings, 'stop', 'yes');
		$stopByError = ( 'no' != $stopRule );
		
		$this->logModel = $this->getModule()->getModel('flowlogs');
		
		$trId = WaicUtils::getArrayValue($flow, 'tr_id', 0, 1);
		$variables = array($this->getNodeKey($trId) => $this->addObjectsVariables(WaicUtils::jsonDecode(WaicUtils::getArrayValue($run, 'params'))));
		$logId = WaicUtils::getArrayValue($run, 'log_id', 0, 1);
		$prevNodeId = $trId;
		$curNodeId = 0;
		$curNode = false;
		$sourceHandle = 'output-right';
		$loopNode = false;
		$loopParent = 0;
		$loopStep = 0;
		$loopCount = 0;
		
		if (!empty($logId)) {
			$curNode = $this->logModel->getById($logId);
			if (empty($curNode)) {
				WaicFrame::_()->pushError('Error current node');
				return 7;
			}
			$blId = $curNode['bl_id'];
			$blType = $curNode['bl_type'];
			$variables = array_merge($variables, $this->logModel->getResults($runId));
			
			if (!empty($curNode['parent'])) { // in the loop
				$loopParent = $curNode['parent'];
				$parentData = $this->logModel->getLog($loopParent);
				if (empty($parentData)) {
					WaicFrame::_()->pushError('Error get parent node data (' . $loopParent .')');
					return 7;
				}
				$loopCount = $parentData['cnt'];
				$node = $this->getNodeById($nodes, $parentData['bl_id']);
				$loopNode = $this->getFlowBlock($node);
				if (!$loopNode) {
					WaicFrame::_()->pushError('Error parent node ' . $loopParent);
					return 7;
				}
				$loopStep = $curNode['step'];
				$loopNode->setResults($parentData['result']);

				$variables[$this->getNodeKey($loopNode->getId())] = $loopNode->addLoopVariables($loopStep, $this);
				$variables = array_merge($variables, $this->logModel->getResults($runId, $loopParent, $loopStep));
			}
			
			if (empty($curNode['status'])) {
				if (1 == $blType) { //delay
					$this->logModel->updateLog($logId, array('status' => 3));
					$prevNodeId = $blId;
					$curNode = false;
				} else {
					$curNodeId = $blId;
				}
			} else {
				if (3 == $blType) { //loop
					$curNodeId = $blId;
				} else {
					$prevNodeId = $blId;
				}
			}
			
		}
		$this->logModel->clearLogs($runId, $logId);
		do {
			if (empty($curNodeId)) {
				$curNodeId = $this->getNextNodeId($edges, $prevNodeId, $sourceHandle);
			}
			
			// not found next node
			if (empty($curNodeId)) {
				//if in loop
				if (!empty($loopParent)) {
					$loopNode->addLoopSuccess();
					$this->logModel->updateLog($loopParent, $loopNode->getResults(0, false));
					// goto next step or end loop
					if ($loopStep >= $loopCount) {
						$loopParent = 0;
						$loopStep = 0;
						$sourceHandle = 'output-else';
						$variables[$this->getNodeKey($loopNode->getId())] = $loopNode->addLoopVariables($loopStep, $this);
					} else {
						$loopStep++;
						$sourceHandle = 'output-then';
						$variables[$this->getNodeKey($loopNode->getId())] = $loopNode->addLoopVariables($loopStep, $this);
					}
					$curNodeId = $this->getNextNodeId($edges, $loopNode->getId(), $sourceHandle);
				}
				
			}
			
			// not found next node
			if (empty($curNodeId)) {
				break;
			}
			
			$node = $this->getNodeById($nodes, $curNodeId);
			if (empty($node)) {
				WaicFrame::_()->pushError('Node with id=' . $curNodeId . ' not found');
				return 7;
			}
			$block = $this->getFlowBlock($node);
			if ($block) {
				$blType = $block->getSubtype();
				if (!$curNode) {
					$logId = $this->logModel->createLog($runId, $curNodeId, $block->getCode(), $blType, $loopParent, $loopStep);
					if (!$logId) {
						WaicFrame::_()->pushError('Error by inserting log');
						return 7;
					}
					$this->runModel->updateById(array('log_id' => $logId), $runId);
				}
				//error_log('$variables='.json_encode($variables));
				$results = $block->getResults($taskId, $variables);
				
				// unknown error by action
				if (empty($results) || !is_array($results)) {
					$results = array('status' => 7, 'error' => '', 'result' => array());
				}
				$this->logModel->updateLog($logId, $results);
				
				// error by action
				if (7 == $results['status']) {
					if (!empty($results['error'])) {
						WaicFrame::_()->pushError($results['error']);
					}
					// count errors in loop
					if (!empty($loopParent)) {
						$loopNode->addLoopError();
						$this->logModel->updateLog($loopParent, $loopNode->getResults(0, false));
					}
					if ($stopByError) {
						if ('in_loop' == $stopRule) {
							if (!empty($loopParent)) {
								return 7;
							}
						} else if ('out_loop' == $stopRule) {
							if (empty($loopParent)) {
								return 7;
							}
						} else {
							return 7;
						}
					} else if ($this->sendError) {
						$this->sendAdminError($taskId, WaicFrame::_()->getLastError());
					}
				}
				
				// need control tokens limit
				if ($saveToken && !empty($results['tokens'])) {
					$runTokens += $results['tokens'];
					$this->runModel->addTokens($runId, $runTokens);
					if ($maxAIRun && $runTokens > $maxAIRun) {
						WaicFrame::_()->pushError('Stopped by run tokens limit (' . $runTokens . ' > ' . $maxAIRun . ')');
						return 6;
					}
					if ($maxAIAll) {
						$flowTokens += $results['tokens'];
						if ($flowTokens > $maxAIAll) {
							WaicFrame::_()->pushError('Stopped by flow tokens limit (' . $flowTokens . ' > ' . $maxAIAll . ')');
							if (1 == $flow['status']) {
								$this->updateById(array('status' => 6, 'updated' => WaicUtils::getTimestampDB()), $flId);
							}
							return 6;
						}
					}
				}
				
				// if delay node
				if (!empty($results['waiting'])) {
					$this->runModel->waitingRun($runId, $results['waiting']);
					return true;
				}
				
				// if loop begin
				if (!empty($results['cnt'])) {
					$loopNode = $block;
					$loopParent = $logId;
					$loopStep = 1;
					$loopCount = $results['cnt'];
					$variables[$this->getNodeKey($curNodeId)] = $loopNode->addLoopVariables($loopStep, $this);
				} else {
					$variables[$this->getNodeKey($curNodeId)] = $this->addObjectsVariables($results['result']);
				}
				$sourceHandle = empty($results['sourceHandle']) ? 'output-right' : $results['sourceHandle'];
				
				// if need stop
				if (!empty($results['stop'])) {
					$stop = $results['stop'];
					if ('loop' == $stop && !empty($loopParent)) {
						$loopParent = 0;
						$loopStep = 0;
						$curNodeId = $loopNode->getId();
						$variables[$this->getNodeKey($loopNode->getId())] = $loopNode->addLoopVariables($loopStep, $this);
					} else {
						break;
					}
				}
				
			} else {
				WaicFrame::_()->pushError('Block with code=' . $block['code'] . ' not found');
				return 7;
			}
			
			$prevNodeId = $curNodeId;
			$curNodeId = false;
			$curNode = false;
			if ($this->controlStopRun($runId)) {
				break;
			}
		} while (true);
		
		$this->runModel->stopRun($runId, 3);
		
		return true;
	}
	public function getNodeKey( $id ) {
		return 'node#' . $id;
	}
	public function getFlow( $id ) {
		$id = (int) $id;
		if (empty($id)) {
			return array();
		}
		$flow = $this->getById($id);
		if ($flow) {
			$flow['params'] = $this->convertTaskParameters(WaicUtils::jsonDecode($flow['params']), false);
		}

		return $flow;
	}
	public function controlStopRun( $runId ) {
		$needStop = false;
		$option = $this->workspace->getWorkspaceFlagById($this->runningFlowId);
		if (is_null($option) || empty($option)) {
			$needStop = true;
		}
		if (!$needStop) {
			$value = (int) $option['value'];
			if ($value != $runId) {
				$needStop = true;
			}
		}
		if (!$needStop) {
			if ($option['flag'] + $option['timeout'] < WaicUtils::getTimestamp()) {
				$needStop = true;
			}
		}
		if ($needStop) {
			$this->runModel->stopRun($runId, 6, 'Stopped by timeout');
		}
		return $needStop;
	}
	public function doHookedFlows( $taskId = 0 ) {
		$flows = $this->setSelectFields('id, tr_hook')->setWhere(array('status' => 1, 'tr_type' => 2))->getFromTbl();
		if ($flows) {
			foreach ( $flows as $flow ) {
				$flowId = $flow['id'];
				$hook = $flow['tr_hook'];
				if (!empty($hook)) {
					add_action($hook, function( ...$args ) use ( $flowId ) {
						$this->runHookedFlow($flowId, $args);
					}, 10, 99);
				}
			}
		}
	}
	public function runHookedFlow( $flId, $args ) {
		$flow = $this->getFlow($flId);
		if ($flow['status'] != 1 || empty($flow['tr_id'])) {
			return;
		}

		$params = WaicUtils::getArrayValue($flow, 'params');
		$nodes = WaicUtils::getArrayValue($params, 'nodes', array(), 2);
		if (!empty($nodes)) {
			$node = $this->getNodeById($nodes, $flow['tr_id']);
			if ($node) {
				$block = $this->getFlowBlock($node);
				if ($block) {
					$block->setFlowId($flId);
					$block->setTaskId($flow['task_id']);
					
					$result = $block->controlRun($args);
					if (false !== $result) {
						$objId = WaicUtils::getArrayValue($result, 'obj_id', 0, 1);
						if ($this->needSkip($flow['flags'], $flId, $objId, $flow['sch_period'])) {
							return;
						}
						$runId = $this->getModule()->getModel('flowruns')->createRun($flow['task_id'], $flId, $result, $objId);
						if (!empty($runId)) {
							// Allow external callers (e.g. TWF) to know if a workflow was started.
							do_action('waic_hooked_flow_created_run', $flId, (int)$flow['task_id'], (int)$runId, $result, $args);
							if (!empty($flow['tr_hook']) && $flow['tr_hook'] === 'waic_twf_process_flow') {
								$GLOBALS['waic_twf_process_flow_handled'] = true;
								$runSync = apply_filters('waic_twf_process_flow_run_sync', true, $flow, (int) $runId, $result, $args);
								if ($runSync) {
									$this->processRunNow((int) $runId);
								}
							}
						}
					}
				}
			}
		}
		return;
	}
	private function processRunNow( $runId ) {
		$runId = (int) $runId;
		if (empty($runId)) {
			return false;
		}
		$this->workspace = WaicFrame::_()->getModule('workspace')->getModel();
		$this->runModel = $this->getModule()->getModel('flowruns');

		// If another workflow is currently running, keep it queued.
		if ($this->isRunningFlow()) {
			return false;
		}

		$run = $this->runModel->getById($runId);
		if (empty($run) || (int) $run['status'] !== 1) {
			return false;
		}

		try {
			$result = $this->doFlowRun($run);
			if (false === $result) {
				return false;
			}
			if (7 === $result || 6 === $result) {
				$this->runModel->stopRun($runId, $result, WaicFrame::_()->getLastError());
				if ($this->sendError) {
					$this->sendAdminError((int) $run['task_id'], WaicFrame::_()->getLastError());
				}
			}
			WaicDispatcher::doAction('afterWorkflowEnded', $runId);
		} finally {
			$this->workspace->resetRunningFlag($this->runningFlowId);
		}

		// Allow external listeners to inspect the updated run row.
		do_action('waic_hooked_flow_finished_run', (int) $runId, $this->runModel->getById($runId));
		return true;
	}
	public function needSkip( $flags, $flowId, $objId = 0, $period = 0 ) {
		$skip = false;
		if (!empty($flags) && strlen($flags) > 1) {
			$flag = (int) substr($flags, 1, 1);
		}
		if ($flag) {
			$skip = $this->getModule()->getModel('flowruns')->existRunningRuns($flowId);
		}
		if (!$skip && !empty($objId)) {
			$multiple = (int) substr($flags, 0, 1);
			if (!$multiple || !empty($period)) {
				$lastRun = $this->getModule()->getModel('flowruns')->getLastRunForObj($flowId, $objId);
				if ($lastRun && !empty($lastRun)) {
					$p = $lastRun['period'];
					$skip = !$multiple ? true : (( (int) $lastRun['period'] ) < $period );
				}
			}
		}
		return $skip;
	}

	public function addObjectsVariables( $variables ) {
		if (!empty($variables['waic_post_id']) && empty($variables['post_ID'])) {
			$variables = $this->addPostVariables($variables, $variables['waic_post_id']);
		}
		if (!empty($variables['waic_page_id']) && empty($variables['page_ID'])) {
			$variables = $this->addPageVariables($variables, $variables['waic_page_id']);
		}
		if (!empty($variables['waic_order_id']) && empty($variables['order_ID'])) {
			$variables = $this->addOrderVariables($variables, $variables['waic_order_id']);
		}
		if (!empty($variables['waic_user_id']) && empty($variables['user_ID'])) {
			$variables = $this->addUserVariables($variables, $variables['waic_user_id']);
		}
		if (!empty($variables['waic_product_id']) && empty($variables['prod_ID'])) {
			$variables = $this->addProductVariables($variables, $variables['waic_product_id']);
		}
		if (!empty($variables['waic_comment_id']) && empty($variables['com_ID'])) {
			$variables = $this->addCommentVariables($variables, $variables['waic_comment_id']);
		}
		if (!empty($variables['waic_media_id']) && empty($variables['media_ID'])) {
			$variables = $this->addMediaVariables($variables, $variables['waic_media_id']);
		}
		if (!empty($variables['waic_run_id']) && empty($variables['run_ID'])) {
			$variables = $this->addWorkflowVariables($variables, $variables['waic_run_id']);
		}
		
		return $variables;
	}
	
	public function addPostVariables( $variables, $id ) {
		$post = get_post($id);
		if (!$post) {
			return $variables;
		}
		$variables = array_merge($variables, array(
			'post_ID' => $post->ID,
			'page_type' => $post->post_type,
			'post_title' => $post->post_title,
			'post_status' => $post->post_status,
			'post_content' => wp_unslash($post->post_content),
			'post_excerpt' => wp_trim_words(wp_strip_all_tags(isset($post->post_excerpt) && !empty($post->post_excerpt) ? $post->post_excerpt : ''), 255),
			'post_permalink' => get_permalink($post),
			'post_image' => get_post_thumbnail_id($post->ID),
			'post_categories' => $this->getObjTerms($id, 'category'),
			'post_tags' => $this->getObjTerms($id, 'post_tag'),
			'post_date' => $post->post_date,
			'post_modified' => $post->post_modified,
		));
		$meta = get_post_meta($id);
		foreach ($meta as $key => $value) {
			$variables['post_meta[' . $key . ']'] = $value;
		}
		return $variables;
	}
	
	public function addPageVariables( $variables, $id ) {
		$post = get_post($id);
		if (!$post) {
			return $variables;
		}
		$variables = array_merge($variables, array(
			'page_ID' => $post->ID,
			'page_title' => $post->post_title,
			'page_status' => $post->post_status,
			'page_content' => wp_unslash($post->post_content),
			'page_excerpt' => wp_trim_words(wp_strip_all_tags(isset($post->post_excerpt) && !empty($post->post_excerpt) ? $post->post_excerpt : ''), 255),
			'page_permalink' => get_permalink($post),
			'page_image' => get_post_thumbnail_id($post->ID),
			'page_comment' => $post->comment_status,
			'page_date' => $post->post_date,
			'page_modified' => $post->post_modified,
		));
		return $variables;
	}
	
	public function addMediaVariables( $variables, $id ) {
		$post = get_post($id);
		if (!$post) {
			return $variables;
		}
		$variables = array_merge($variables, array(
			'media_ID' => $post->ID,
			'media_title' => $post->post_title,
			'media_content' => wp_unslash($post->post_content),
			'media_excerpt' => wp_trim_words(wp_strip_all_tags(isset($post->post_excerpt) && !empty($post->post_excerpt) ? $post->post_excerpt : ''), 255),
			'media_alt' => get_post_meta($id, '_wp_attachment_image_alt', true),
			'media_type' => $post->post_mime_type,
			'media_permalink' => get_permalink($post),
			'media_date' => $post->post_date,
			'media_modified' => $post->post_modified,
		));
		return $variables;
	}
	
	public function addWorkflowVariables( $variables, $id ) {
		$runModel = $this->getModule()->getModel('flowruns');
		$run = $runModel->getById($id);
		if (!$run) {
			return $variables;
		}
		$taskId = $run['task_id'];
		$taskModel = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		$task = $taskModel->getById($taskId);
		if (!$task) {
			return $variables;
		}
		$flow = $this->getById($run['fl_id']);
		if (!$flow) {
			return $variables;
		}
		$variables = array_merge($variables, array(
			'flow_id' => $taskId,
			'flow_ver' => $flow['version'],
			'flow_status_id' => $task['status'],
			'flow_status' => $taskModel->getStatuses($task['status']),
			'run_ID' => $id,
			'run_status_id' => $run['status'],
			'run_status' => $runModel->getStatuses($run['status']),
			'run_started' => $run['started'],
			'run_ended' => $run['ended'],
			'run_error' => $run['error'],
			'run_tokens' => $run['tokens'],
			'run_obj_id' => $run['obj_id'],
		));
		return $variables;
	}
	
	public function addUserVariables( $variables, $id ) {
		$user = get_user_by('id', (int) $id);
		if (!$user) {
			return $variables;
		}
		
		$variables = array_merge($variables, array(
			'user_ID' => $user->ID,
			'user_login' => $user->user_login,
			'user_email' => $user->user_email,
			'user_url' => $user->user_url,
			'user_nicename' => $user->user_nicename,
			'display_name' => $user->display_name,
			'user_registered' => $user->user_registered,
			'user_roles' => $user->roles,
		));
		$сaps = [];

		foreach ($user->allcaps as $cap => $value) {
			if ($value === true) {
				$сaps[] = $cap;
			}
		}
		$variables['user_caps'] = $сaps;
		
		$meta = get_user_meta($id);
		foreach ($meta as $key => $value) {
			$variables['user_meta[' . $key . ']'] = $value;
		}
		return $variables;
	}
	public function addProductVariables( $variables, $id ) {
		if (!function_exists('wc_get_product')) {
			return $variables;
		}
		$product = wc_get_product($id);
		if (!$product) {
			return $variables;
		}
		$modified = $product->get_date_modified();
		$variables = array_merge($variables, array(
			'prod_ID' => $product->get_id(),
			'prod_name' => $product->get_title(),
			'prod_sku' => $product->get_sku(),
			'prod_type' => $product->get_type(),
			'prod_parent' => $product->get_parent_id(),
			'post_status' => $product->get_status(),
			'prod_desc' => wp_unslash($product->get_description()),
			'prod_short_desc' => wp_unslash($product->get_short_description()),
			'prod_permalink' => $product->get_permalink(),
			'prod_image_main' => $product->get_image_id(),
			'prod_gallery_images' => $product->get_gallery_image_ids(),
			'prod_categories' => $product->get_category_ids(),// $this->getObjTerms($id, 'product_cat'),
			'prod_tags' => $product->get_tag_ids(), //$this->getObjTerms($id, 'product_tag'),
			'prod_created' => $product->get_date_created()->format('Y-m-d H:i:s'),
			'prod_modified' => $modified && !is_null($modified) ? $modified->format('Y-m-d H:i:s') : '',
			'prod_rating' => $product->get_average_rating(),
			'prod_price' => $product->get_price(),
			'prod_regular_price' => $product->get_regular_price(),
			'prod_sale_price' => $product->get_sale_price(),
			'prod_stock_status' => $product->get_stock_status(),
			'prod_stock_quantity' => $product->get_stock_quantity(),
		));
		$meta = get_post_meta($id);
		foreach ($meta as $key => $value) {
			$variables['prod_meta[' . $key . ']'] = $value;
		}
		$attributes = $product->get_attributes();
		if ($attributes) {
			if ($product->is_type('variation')) {
				foreach ($attributes as $name => $value) {
					$variables['prod_attr[' . $name . ']'] = array($value);
				}
			} else {
				foreach($attributes as $attribute) {
					$variables['prod_attr[' . $attribute->get_name() . ']'] = $attribute->get_options();
				}
			}
		}

		return $variables;
	}
	public function addCommentVariables( $variables, $id ) {
		$comment = get_comment((int) $id);
		if (!$comment) {
			return $variables;
		}
		
		$variables = array_merge($variables, array(
			'com_ID' => $comment->comment_ID,
			'com_post_ID' => $comment->comment_post_ID,
			'com_user_id' => $comment->user_id,
			'com_type' => $comment->comment_type,
			'com_date' => $comment->comment_date,
			'com_content' => $comment->comment_content,
			'com_status' => wp_get_comment_status($id),
			'com_rating' => get_comment_meta($id, 'rating', true),
		));
		return $variables;
	}
	
	public function addOrderVariables( $variables, $id ) {
		if (!function_exists('wc_get_order')) {
			return $variables;
		}
		$order = wc_get_order((int) $id);
		if (!$order) {
			return $variables;
		}
		$products = $this->getOrderProducts($order, array('ids' => 0, 'cats' => 0, 'tags' => 0));
		$paid = $order->get_date_paid();
		$completed = $order->get_date_completed();
		$variables = array_merge($variables, array(
			'order_ID' => $id,
			'order_status' => $order->get_status(),
			'order_currency' => $order->get_currency(),
			'order_total' => $order->get_total(),
			'order_subtotal' => $order->get_subtotal(),
			'order_coupons' => $order->get_coupon_codes(),
			'order_discount_total' => $order->get_discount_total(),
			'order_shipping_total' => $order->get_shipping_total(),
			'order_shipping_method' => $order->get_shipping_method(),
			'order_item_count' => $order->get_item_count(),
			'order_products' => $products['ids'],
			'order_categories' => $products['cats'],
			'order_tags' => $products['tags'],
			'order_payment_method' => $order->get_payment_method(),
			'order_date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
			'order_date_paid' => $paid && !is_null($paid) ? $paid->format('Y-m-d H:i:s') : '',
			'order_date_completed' => $completed && !is_null($completed) ? $completed->format('Y-m-d H:i:s') : '',
			'customer_id' => $order->get_customer_id(),
			'billing_email' => $order->get_billing_email(),
			'billing_first_name' => $order->get_billing_first_name(),
			'billing_last_name' => $order->get_billing_last_name(),
		));
		return $variables;
	}
	public function getOrderProducts( $order, $need = array() ) {
		$needIds = isset($need['ids']);
		$needCats = isset($need['cats']);
		$needTags = isset($need['tags']);
		foreach ($need as $k => $d) {
			$need[$k] = array();
		}
		foreach ($order->get_items() as $item) {
			$prId = $item->get_product_id();
			if ($needIds && !in_array($prId, $need['ids'])) {
				$need['ids'][] = $prId;
			}
			if ($needCats) {
				$need['cats'] = $this->getObjTerms($prId, 'product_cat');
			}
			if ($needTags) {
				$need['tags'] = $this->getObjTerms($prId, 'product_tag');
			}
        }
		return $need;
	}
	public function getObjTerms( $id, $taxonomy ) {
		$list = array();
		$terms = get_the_terms($id, $taxonomy);
		if (!empty($terms)) {
			foreach ($terms as $term) {
				$termId = $term->term_id;
				if (!in_array($termId, $list)) {
					$list[] = $termId;
				}
			}
		}
		return $list;
	}
	public function sendAdminError( $taskId, $error ) {
		$email = get_option('admin_email');
		$headers = array(
			'Content-type: text/html; charset=utf-8',
			'Content-Transfer-Encoding: 8bit',
			'From: ' . get_bloginfo('name') . ' <' . $email . '>',
		);
		$subject = 'AIWU Error notification';
		$message = 'An error occurred during workflow execution.' . PHP_EOL . 
			'Error text: ' . $error . PHP_EOL . 
			'Review the log: ' . WaicFrame::_()->getModule('workspace')->getTaskUrl($taskId, 'workflow');
		if (!wp_mail($email, $subject, $message, $headers)) {
			WaicFrame::_()->pushError('Error by sending email notification');
		}
		return true;
	}
	public function getFlowLog( $taskId = 0, $dd = false ) {
		$forDate = !empty($dd);
		$query = 'SELECT r.id as run_id, l.id as log_id, f.version, f.tr_id, f.tr_code, r.obj_id,' .
			' r.status as run_status, l.status as log_status, r.added,' .
			' r.started as run_started, l.started as log_started,' .
			' r.ended as run_ended, l.ended as log_ended,' .
			' r.error as run_error, l.error as log_error,' .
			' l.bl_code, l.bl_id, l.bl_type, l.step, l.cnt, l.result' .
			' FROM @__flowruns as r' .
			' INNER JOIN @__workflows f ON (f.id=r.fl_id)' .
			' LEFT JOIN @__flowlogs l ON (l.run_id=r.id)' .
			' WHERE f.task_id=' . ( (int) $taskId ) .
			( $forDate ? " AND r.added BETWEEN '" . $dd . " 00:00:00' AND '" . $dd . " 23:59:59'" : '' ) .
			' ORDER BY run_id, log_id' .
			( $forDate ? '' : ' LIMIT 100' ); 
		$log = WaicDb::get($query);

		return $log && !empty($log) ? $log : array();
	}
	public function registerWebhookRoutes() {
		$flows = $this->setSelectFields('id, tr_hook')->setWhere(array('status' => 1, 'tr_type' => 3))->getFromTbl();
		if ($flows) {
			foreach ($flows as $flow) {
				$flId = $flow['id'];
				$hook = empty($flow['tr_hook']) ? array() : WaicUtils::jsonDecode($flow['tr_hook']);
				$route = WaicUtils::getArrayValue($hook, 'url');
				$method = WaicUtils::getArrayValue($hook, 'method', 'POST');
				if (!empty($route) && !empty($method)) {
					register_rest_route('aiwu/v1', $route, array(
						'methods' => $method,
						'aiwu_flow_id' => $flId,
						'callback' => array($this, 'runWebhookFlow' ),
						'permission_callback' => function( $request ) {
							return $this->canAccessWebhook($request);
						},
					));
				}
			}
		}
	}
	public function runWebhookFlow( WP_REST_Request $request ) {
		$attributes = $request->get_attributes();
		$flId = !empty($attributes) && isset($attributes['aiwu_flow_id']) ? (int) $attributes['aiwu_flow_id'] : 0;
		if (empty($flId)) {
			wp_send_json_error(array(
				'message' => 'Workflow error',
				'code' => 'aiwu_flow_failed'
			), 403);
			return false;
		}
		$flow = $this->getFlow($flId);
		if ($flow['status'] != 1 || empty($flow['tr_id'])) {
			wp_send_json_error(array(
				'message' => 'Workflow error',
				'code' => 'aiwu_flow_status'
			), 403);
			return false;
		}

		$params = WaicUtils::getArrayValue($flow, 'params');
		$nodes = WaicUtils::getArrayValue($params, 'nodes', array(), 2);
		if (!empty($nodes)) {
			$node = $this->getNodeById($nodes, $flow['tr_id']);
			if ($node) {
				$block = $this->getFlowBlock($node);
				if ($block) {
					$result = $block->controlRun($request);
					if (false === $result) {
						wp_send_json_error(array(
							'message' => 'Authorization error',
							'code' => 'aiwu_auth_failed'
						), 403);
					} else {
						$objId = WaicUtils::getArrayValue($result, 'obj_id', 0, 1);
						/*if ($this->needSkip($flow['flags'], $flId, $objId, $flow['sch_period'])) {
							return;
						}*/
						$this->getModule()->getModel('flowruns')->createRun($flow['task_id'], $flId, $result, $objId);
					}
				}
			}
		}
		wp_send_json_success();
		return;
	}
	public function canAccessWebhook( WP_REST_Request $request ) {
		return true;
	}

	public function runUrlTriggers( $url ) {
		$flows = $this->setSelectFields('id, tr_id, task_id, tr_hook, params')->setWhere(array('status' => 1, 'tr_type' => 4))->getFromTbl();
		if ($flows) {
			foreach ($flows as $flow) {
				$regex = $flow['tr_hook'];
				if (empty($regex) || !preg_match($regex, $url)) {
					return false;
				}
				$params = WaicUtils::jsonDecode(WaicUtils::getArrayValue($flow, 'params'));
				$nodes = WaicUtils::getArrayValue($params, 'nodes', array(), 2);
				$node = $this->getNodeById($nodes, $flow['tr_id']);
				if ($node) {
					$block = $this->getFlowBlock($node);
					if ($block) {
						$result = $block->controlRun($url);
						if (false !== $result) {
							$objId = WaicUtils::getArrayValue($result, 'obj_id', 0, 1);
							/*if ($this->needSkip($flow['flags'], $flId, $objId, $flow['sch_period'])) {
								return;
							}*/
							$this->getModule()->getModel('flowruns')->createRun($flow['task_id'], $flow['id'], $result, $objId);
						}
					}
				}
			}
		}
	}
	
	public function getWorkflowTemplates() {
		$taskModel = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		
		$list = array();
		$tasks = $taskModel->setSelectFields('id, title, message, mode')->setWhere(array('feature' => 'template'))->getFromTbl();
		foreach ($tasks as $task) {
			$list[$task['id']] = array(
				'title' => $task['title'],
				'desc' => $task['message'],
				'mode' => $task['mode'],
			);
		}
		return $list;
	}
	public function createWorkflowByTemplate( $tmpId ) {
		$taskModel = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		$template = $taskModel->getTask($tmpId);
		if ($template && 'template' == $template['feature']) {
			return $taskModel->saveTask('workflow', 0, $template['params']);
		}
		return 0;
	}
	public function createTemplate( $params ) {
		$id = WaicUtils::getArrayValue($params, 'task_id', 0, 1);
		if (empty($id)) {
			WaicFrame::_()->pushError('Empty Task ID');
			return false;
		}
		$name = WaicUtils::getArrayValue($params, 'name');
		$desc = WaicUtils::getArrayValue($params, 'desc');
		$taskModel = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		$task = $taskModel->getTask($id);
		if (!$task) {
			WaicFrame::_()->pushError('Task ID not found');
			return false;
		}
		$data = $task['params'];
		$data['task_title'] = empty($name) ? $task['title'] : addslashes(WaicUtils::mbsubstr($name, 0, 240));
		
		$newId = $taskModel->saveTask('template', 0, $data);
		if ($newId && !empty($desc)) {
			$taskModel->updateTask($newId, array('message' => addslashes(WaicUtils::mbsubstr($desc, 0, 240))));
		}
		return true;
	}
	public function importTemplate( $params ) {
		$name = WaicUtils::getArrayValue($params, 'name');
		if (empty($name)) {
			WaicFrame::_()->pushError('Template Name is required');
			return false;
		}
		$json = WaicUtils::getArrayValue($params, 'json');
		if (empty($json)) {
			WaicFrame::_()->pushError('JSON is required');
			return false;
		}
		$param = json_decode($json, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			WaicFrame::_()->pushError('Invalid JSON');
			return false;
		}
		if (!$this->controlJSON($param)) {
			WaicFrame::_()->pushError('Invalid JSON.');
			return false;
		}
		
		$data = $param;
		$data['task_title'] = addslashes(WaicUtils::mbsubstr($name, 0, 240));
		$taskModel = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		
		$newId = $taskModel->saveTask('template', 0, $data);
		$desc = WaicUtils::getArrayValue($params, 'desc');
		if ($newId && !empty($desc)) {
			$taskModel->updateTask($newId, array('message' => addslashes(WaicUtils::mbsubstr($desc, 0, 240))));
		}
		return true;
	}
	public function controlJSON( $json ) {
		if (empty($json) || !is_array($json) || empty($json['nodes']) || empty($json['edges'])) {
			return false;
		}
		if (!is_array($json['nodes']) || !is_array($json['edges'])) {
			return false;
		}
		foreach ($json['nodes'] as $node) {
			if (empty($node['id']) || !is_numeric($node['id'])) {
				return false;
			}
			if (empty($node['type']) || !in_array($node['type'],$this->_blTypes)) {
				return false;
			}
			if (empty($node['position']) || empty($node['position']['x']) || empty($node['position']['y'])) {
				return false;
			}
			if (empty($node['data']) || empty($node['data']['category']) || empty($node['data']['code']) || empty($node['data']['label']) || empty($node['data']['settings']) || empty($node['data']['type'])) {
				return false;
			}
		}
		foreach ($json['edges'] as $edge) {
			if (empty($edge['id']) || !is_numeric($edge['id'])) {
				return false;
			}
			if (empty($edge['source']) || empty($edge['sourceHandle']) || empty($edge['target']) || empty($edge['targetHandle']) || empty($edge['type'])) {
				return false;
			}
		}
		return true;
	}
	
	public function deleteTemplate( $tmpId ) {
		$taskModel = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		$template = $taskModel->getTask($tmpId);
		if ($template && 'template' == $template['feature'] && empty($template['mode'])) {
			$taskModel->delete(array('id' => $tmpId));
		}
		return true;
	}
	public function getJSON( $taskId ) {
		$taskModel = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		$task = $taskModel->getById($taskId);
		if ($task) {
			return stripslashes($task['params']);
		}
		WaicFrame::_()->pushError('Task ID not found');
		return false;
	}
	/**
     * BizCity debug helper: mark that TWF trigger was handled by WAIC and log run id.
     */
    private function bizcity_mark_twf_handled($workflowId = null, $runId = null, array $extra = []) {
        $GLOBALS['waic_twf_process_flow_handled'] = true;

        $payload = array_merge([
            'workflow_id' => $workflowId,
            'run_id'      => $runId,
            'blog_id'     => function_exists('get_current_blog_id') ? get_current_blog_id() : null,
        ], $extra);

        error_log('[TWF][AIWU] HANDLED | created run' . ' | ' . wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * BizCity debug helper: best-effort get latest run id from flowruns table.
     * (Used only for logging; does not affect logic.)
     */
    private function bizcity_guess_last_run_id() {
        global $wpdb;
        $table = $wpdb->prefix . 'waic_flowruns';
        // Table might not exist on some installs.
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return null;

        // Best-effort: latest run id.
        $rid = $wpdb->get_var("SELECT id FROM {$table} ORDER BY id DESC LIMIT 1");
        return $rid ? (int) $rid : null;
    }
}
