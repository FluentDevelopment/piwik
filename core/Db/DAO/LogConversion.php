<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */

/**
 * @package Piwik
 * @subpackage Piwik_Db
 */
class Piwik_Db_DAO_LogConversion extends Piwik_Db_DAO_Base
{
	public function __construct($db, $table)
	{
		parent::__construct($db, $table);
	}

	public function getAllByIdvisit($idvisit)
	{
		// Without "quoteIdentifier" postgresql will return column aliases
		// in lower case
		$sql = 'SELECT '
			 . "  'goal' AS type "
			 . ' , g.name AS ' . $this->db->quoteIdentifier('goalName').' '
			 . ' , g.revenue AS revenue '
			 . ' , lc.idlink_va AS ' . $this->db->quoteIdentifier('goalPageId') . ' '
			 . ' , lc.server_time AS ' . $this->db->quoteIdentifier('serverTimePretty') .' '
			 . ' , lc.url AS url '
			 . 'FROM ' . $this->table . ' AS lc '
			 . 'LEFT OUTER JOIN ' . Piwik_Common::prefixTable('goal') . ' AS g '
			 . '    ON (g.idsite = lc.idsite AND g.idgoal = lc.idgoal) '
			 . '   AND g.deleted = 0 '
			 . 'WHERE lc.idvisit = ? AND lc.idgoal > 0';
		
		return $this->db->fetchAll($sql, array($idvisit));
	}

	public function getEcommerceDetails($idvisit)
	{
		$Generic = Piwik_Db_Factory::getGeneric($this->db);
		$sql = 'SELECT '
			 . 'CASE idgoal '
			 . '  WHEN ' . Piwik_Tracker_GoalManager::IDGOAL_CART . ' '
			 . "  THEN '" . Piwik_Archive::LABEL_ECOMMERCE_CART . "' "
			 . "  ELSE '" . Piwik_Archive::LABEL_ECOMMERCE_ORDER . "' "
			 . 'END AS type, '
			 . 'idorder AS ' . $this->db->quoteIdentifier('orderId') . ', '
			 . $Generic->getSqlRevenue('revenue') . ' AS revenue, '
			 . $Generic->getSqlRevenue('revenue_subtotal') . ' AS ' . $this->db->quoteIdentifier('revenueSubTotal') . ', '
			 . $Generic->getSqlRevenue('revenue_tax') . ' AS ' . $this->db->quoteIdentifier('revenueTax') . ', '
			 . $Generic->getSqlRevenue('revenue_shipping') . ' AS ' . $this->db->quoteIdentifier('revenueShipping') . ', '
			 . $Generic->getSqlRevenue('revenue_discount') . ' AS ' . $this->db->quoteIdentifier('revenueDiscount') . ', '
			 . 'items, '
			 . 'server_time AS ' . $this->db->quoteIdentifier('serverTimePretty') . ' '
			 . 'FROM ' . $this->table . ' '
			 . 'WHERE idvisit = ? '
			 . '  AND idgoal <= ' . Piwik_Tracker_GoalManager::IDGOAL_ORDER;

		return $this->db->fetchAll($sql, array($idvisit));
	}

	public function recordGoal($goal, $mustUpdateNotInsert, $updateWhere)
	{

		if ($mustUpdateNotInsert)
		{
			$ret = $this->recordGoalUpdate($goal, $updateWhere);
		}
		else
		{
			$ret = $this->recordGoalInsert($goal);
		}

		return $ret;
	} 

	public function getCountByIdvisit($idvisit)
	{
		$sql = 'SELECT COUNT(*) FROM ' . $this->table . ' WHERE idvisit <= ?';
		return (int)$this->db->fetchOne($sql, array($idvisit));
	}

	public function getMaxIdvisit()
	{
		$sql = 'SELECT MAX(idvisit) FROM ' . $this->table;
		return $this->db->fetchOne($sql);
	}

	/**
	 *	uses tracker db
	 */
	protected function recordGoalUpdate($goal, $updateWhere)
	{
		$Generic = Piwik_Db_Factory::getGeneric($this->db);
	
		$updateParts = $sqlBind = $updateWhereParts = array();
		if (isset($goal['idvisitor']))
		{
			$goal['idvisitor'] = $Generic->bin2db($goal['idvisitor']);
		}
		if (isset($updateWhere['idvisitor']))
		{
			$updateWhere['idvisitor'] = $Generic->bin2db($goal['idvisitor']);
		}
		foreach($goal as $name => $value)
		{
			$updateParts[] = $name . ' = ? ';
			$sqlBind[] = $value;
		}
		foreach($updateWhere as $name => $value)
		{
			$updateWhereParts[] = $name . ' = ? ';
			$sqlBind[] = $value;
		}

		$sql = 'UPDATE ' . $this->table . ' SET '
			 . implode(', ', $updateParts) . ' '
			 . 'WHERE ' . implode(' AND ', $updateWhereParts);
		
		$this->db->query($sql, $sqlBind);
		return true;
	}

	/**
	 *	uses tracker db
	 */
	protected function recordGoalInsert($goal)
	{
		$fields = implode(', ', array_keys($goal));
		$bindFields = Piwik_Common::getSqlStringFieldsArray($goal);

		$sql = 'INSERT IGNORE INTO ' . $this->table . '( ' . $fields . ' ) '
			 . 'VALUES ( ' . $bindFields . ' ) ';
		$bind = array_values($goal);
		
		$result = $this->db->query($sql, $bind);

		return $this->db->rowCount($result) > 0;
	}
}
