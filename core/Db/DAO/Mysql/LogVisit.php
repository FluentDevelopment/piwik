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
namespace Piwik\Db\DAO\Mysql;

use Piwik\Common;
use Piwik\Config;
use Piwik\Date;
use Piwik\Db;
use Piwik\Db\Factory;
use Piwik\Db\DAO\Base;
use Piwik\Segment;

/**
 * @package Piwik
 * @subpackage Piwik_Db
 */

class LogVisit extends Base
{ 
    // Used only with the "recognizeVisitor" function
    // To avoid passing in and out too many parameters.
    protected $recognize = null;

    protected $Generic = null;

    public function __construct($db, $table)
    {
        parent::__construct($db, $table);
    }

    public function addColLocationProvider()
    {
        $sql = 'ALTER IGNORE TABLE ' . $this->table . ' ADD COLUMN '
             . 'location_provider VARCHAR(100) DEFAULT NULL';
        // if the column already exist do not throw error. Could be installed twice...
        try {
            $this->db->exec($sql);
        }
        catch(\Exception $e) {
            if (!$this->db->isErrNo($e, '1060')) {
                throw $e;
            }
        }
    }

    public function removeColLocationProvider()
    {
        $sql = 'ALTER TABLE ' . $this->table . ' DROP COLUMN location_provider';
        $this->db->exec($sql);
    }

    public function getDeleteIdVisitOffset($date, $maxIdVisit, $segmentSize)
    {
        $sql = 'SELECT idvisit '
             . 'FROM ' . $this->table . ' '
             . "WHERE '" . $date ."' > visit_last_action_time "
             . '  AND idvisit <= ? '
             . '  AND idvisit > ? '
             . 'ORDER BY idvisit DESC '
             . 'LIMIT 1';

        return Db::segmentedFetchFirst($sql, $maxIdVisit, 0, $segmentSize);
    }

    public function updateVisits($valuesToUpdate, $idvisit)
    {
        $Generic = Factory::getGeneric($this->db);
        $binary_columns = array('idvisitor', 'config_id', 'location_ip');
        foreach ($binary_columns as $bc) {
            if (array_key_exists($bc, $valuesToUpdate)) {
                $valuesToUpdate[$bc] = $Generic->bin2db($valuesToUpdate[$bc]);
            }
        }

        $updateParts = $sqlBind = array();
        foreach ($valuesToUpdate AS $name => $value) {
            // Case where bind parameters don't work
            if(strpos($value, $name) !== false) {
                //$name = 'visit_total_events'
                //$value = 'visit_total_events + 1';
                $updateParts[] = " $name = $value ";
            } else {
                $updateParts[] = $name . " = ?";
                $sqlBind[] = $value;
            }
        }
        
        array_push($sqlBind, $idvisit);
        $sql = 'UPDATE ' . $this->table . ' SET '
             . implode(', ', $updateParts) . ' '
             . 'WHERE idvisit = ?';
        $result = $this->db->query($sql, $sqlBind);
    }

    public function add($visitor_info)
    {
        $fields = implode(', ', array_keys($visitor_info));
        $values = Common::getSqlStringFieldsArray($visitor_info);
        $bind   = array_values($visitor_info);

        $sql = 'INSERT INTO ' . $this->table . '( ' . $fields . ') VALUES (' . $values . ')';

        $this->db->query($sql, $bind);

        return $this->db->lastInsertId();
    }

    /**
     * Deletes visits with the supplied IDs from log_visit. This method does not cascade, so rows in other tables w/
     * the same visit ID will still exist.
     *
     * @param int[] $idVisits
     * @return int The number of deleted rows.
     */
    public function deleteVisits($idVisits)
    {
        $sql = 'DELETE FROM ' . $this->table . ' WHERE idvisit IN (' . implode(', ', $idVisits) . ')';
        $statement = $this->db->query($sql);
        return $statement->rowCount();
    }

    /**
     * Returns the list of the website IDs that received some visits between the specified timestamp.
     *
     * @param string $fromDateTime
     * @param string $toDateTime
     * @return bool true if there are visits for this site between the given timeframe, false if not
     */
    public function hasSiteVisitsBetweenTimeframe($fromDateTime, $toDateTime, $idSite)
    {
        $sql = "SELECT 1
                  FROM {$this->table}
                 WHERE idsite = ?
                   AND visit_last_action_time > ?
                   AND visit_last_action_time < ?
                 LIMIT 1";
        $sites = $this->db->fetchOne($sql, array($idSite, $fromDateTime, $toDateTime));

        return (bool) $sites;
    }

    public function getByIdvisit($idVisit)
    {
        $sql = "SELECT * FROM {$this->table} WHERE idvisit = ?";
        return $this->db->query($sql, array($idVisit));
    }

    /**
     *  recognizeVisitor
     *
     *  Uses tracker db
     *
     *  @param bool $customVariablesSet If custom variables are set in request
     *  @param array $persistedVisitAttributes Array of fields to be selected
     *  @param string $timeLookBack Timestamp from which records will be checked
     *  @param bool $shouldMatchOneFieldOnly
     *  @param bool $matchVisitorId
     *  @param int  $idSite
     *  @param int  $configId
     *  @param int  $idVisitor
     *  @return array
     */
    public function recognizeVisitor($customVariablesSet, $persistedVisitAttributes,
                                     $timeLookBack, $timeLookAhead,
                                     $shouldMatchOneFieldOnly, $matchVisitorId,
                                     $idSite, $configId, $idVisitor)
    {
        $this->Generic = Factory::getGeneric($this->db);

        $this->recognize = array();
        $this->recognize['persistedVisitAttributes'] = $persistedVisitAttributes;
        $this->recognize['matchVisitorId'] = $matchVisitorId;
        $this->recognize['configId'] = $configId;
        $this->recognize['idVisitor'] = $idVisitor;
        $this->recognize['whereCommon'] = ' visit_last_action_time >= ? AND visit_last_action_time <= ? AND idsite = ? ';
        $this->recognize['bind'] = array($timeLookBack, $timeLookAhead, $idSite);

        $this->recognizeVisitorSelect($customVariablesSet);

        // Two use cases:
        // 1) there is no visitor ID so we try to match only on config_id (heuristics)
        //      Possible causes of no visitor ID: no browser cookie support, direct Tracking API request without visitor ID passed, etc.
        //      We can use config_id heuristics to try find the visitor in the past, there is a risk to assign 
        //      this page view to the wrong visitor, but this is better than creating artificial visits.
        // 2) there is a visitor ID and we trust it (config setting trust_visitors_cookies), so we force to look up this visitor id
        if ($shouldMatchOneFieldOnly) {
            $this->recognizeVisitorOneField();
        }
        /* We have a config_id AND a visitor_id. We match on either of these.
                Why do we also match on config_id?
                we do not tru st the visitor ID only. Indeed, some browsers, or browser addons, 
                cause the visitor id from the 1st party cookie to be different on each page view! 
                It is not acceptable to create a new visit every time such browser does a page view, 
                so we also backup by searching for matching config_id. 
         We use a UNION here so that each sql query uses its own INDEX
        */
        else {
            $this->recognizeVisitorTwoFields();
        }

        $result = $this->db->fetchRow($this->recognize['sql'], $this->recognize['bind']);

        return array($result, $this->recognize['selectCustomVariables']);
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
     * @param string $from
     * @param string $to
     * @return int
     */
    public function countVisitsWithDatesLimit($from, $to)
    {
        $sql = "SELECT COUNT(*) AS num_rows"
             . " FROM " . $this->table
             . " WHERE visit_last_action_time >= ? AND visit_last_action_time < ?";

        $bind = array($from, $to);

        return (int) $this->db->fetchOne($sql, $bind);
    }

    public function loadLastVisitorDetailsSelect()
    {
        return 'log_visit.*';
    }

    public function loadLastVisitorDetails($subQuery, $sqlLimit, $orderByParent)
    {
        $sql = 'SELECT sub.* FROM ( '
             .   $subQuery['sql'] . $sqlLimit
             . ' ) AS sub '
             . 'GROUP BY sub.idvisit '
             . 'ORDER BY ' . $orderByParent;
        
        return $this->db->fetchAll($sql, $subQuery['bind']);
    }

    public function getCounters($sql, $bind)
    {
        return $this->db->fetchAll($sql, $bind);
    }

    public function updateVisit($idVisit, $uaDetails)
    {
        $q = "UPDATE {$this->table} SET " .
            "config_browser_name = '" . $uaDetails['config_browser_name'] . "' ," .
            "config_browser_version = '" . $uaDetails['config_browser_version'] . "' ," .
            "config_os = '" . $uaDetails['config_os'] . "' ," .
            "config_os_version = '" . $uaDetails['config_os_version'] . "' ," .
            "config_device_type =  " . (isset($uaDetails['config_device_type']) ? "'" . $uaDetails['config_device_type'] . "'" : "NULL") . " ," .
            "config_device_model = " . (isset($uaDetails['config_device_model']) ? "'" . $uaDetails['config_device_model'] . "'" : "NULL") . " ," .
            "config_device_brand = " . (isset($uaDetails['config_device_brand']) ? "'" . $uaDetails['config_device_brand'] . "'" : "NULL") . "
                    WHERE idvisit = " . $idVisit;
        $this->db->query($q);
    }

    public function getAdjacentVisitorId($idSite, $visitorId, $visitLastActionTime, $segment, $getNext)
    {
        $visitorId = $this->adjacentVisitorId($idSite, @Common::hex2bin($visitorId), $visitLastActionTime, $segment, $getNext);
        if (!empty($visitorId)) {
            $visitorId = bin2hex($visitorId);
        }

        return $visitorId;
    }

    public function devicesDetectionInstall()
    {
// we catch the exception
        try {
            $q1 = "ALTER TABLE `" . $this->table . "`
                ADD `config_os_version` VARCHAR( 100 ) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `config_os` ,
                ADD `config_device_type` VARCHAR( 100 ) NULL DEFAULT NULL AFTER `config_browser_version` ,
                ADD `config_device_brand` VARCHAR( 100 ) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `config_device_type` ,
                ADD `config_device_model` VARCHAR( 100 ) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `config_device_brand`";
            $this->db->exec($q1);
            // conditionaly add this column
            if (@Config::getInstance()->Debug['store_user_agent_in_visit']) {
                $q2 = "ALTER TABLE `" . $this->table . "`
                ADD `config_debug_ua` VARCHAR( 512 ) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `config_device_model`";
                $this->db->exec($q2);
            }
        } catch (\Exception $e) {
            if (!$this->db->isErrNo($e, '1060')) {
                throw $e;
            }
        }
    }

    protected function recognizeVisitorSelect($customVariablesSet)
    {
        if ($customVariablesSet) {
            $this->recognize['selectCustomVariables'] = '';
        }
        else {
            // No custom var were found in the request, so let's copy the previous one in a potential conversion later
            $this->recognize['selectCustomVariables'] = ', 
                custom_var_k1, custom_var_v1,
                custom_var_k2, custom_var_v2,
                custom_var_k3, custom_var_v3,
                custom_var_k4, custom_var_v4,
                custom_var_k5, custom_var_v5 ';
        }

        $idvisitorPos = array_search('idvisitor', $this->recognize['persistedVisitAttributes']);
        if ($idvisitorPos !== false) {
            $this->recognize['persistedVisitAttributes'][$idvisitorPos] = $this->Generic->binaryColumn('idvisitor');
        }
        $selectFields = implode(",\n", $this->recognize['persistedVisitAttributes']);
        $select = "SELECT   visit_last_action_time,
                            visit_first_action_time,
                            {$selectFields}
                            {$this->recognize['selectCustomVariables']}
        ";
        $this->recognize['select'] = $select;
        $this->recognize['from'] = ' FROM ' . $this->table . ' ';
    }

    protected function recognizeVisitorOneField()
    {
        $bind = $this->recognize['bind'];
        $where = $this->recognize['whereCommon'];

        if ($this->recognize['matchVisitorId']) {
            $where .= ' AND idvisitor = ? ';
            $bind[] = $this->Generic->bin2db($this->recognize['idVisitor']);
        }
        else {
            $where .= ' AND config_id = ? ';
            $bind[] = $this->Generic->bin2db($this->recognize['configId']);
        }

        $this->recognize['sql'] = $this->recognize['select']
             . $this->recognize['from']
             . 'WHERE ' . $where . ' '
             . 'ORDER BY visit_last_action_time DESC '
             . 'LIMIT 1';
        $this->recognize['bind'] = $bind;
    }

    protected function recognizeVisitorTwoFields()
    {
        $bind = $this->recognize['bind'];
        $whereSameBothQueries = $this->recognize['whereCommon'];
        
        
        $where = ' AND config_id = ?';
        $bind[] = $this->Generic->bin2db($this->recognize['configId']);
        $configSql = $this->recognize['select']." ,
                0 as priority
                {$this->recognize['from']}
                WHERE $whereSameBothQueries $where
                ORDER BY visit_last_action_time DESC
                LIMIT 1
        ";
    
        // will use INDEX index_idsite_idvisitor (idsite, idvisitor)
        $bind = array_merge($bind, $this->recognize['bind']);
        $where = ' AND idvisitor = ? ';
        $bind[] = $this->Generic->bin2db($this->recognize['idVisitor']);
        $visitorSql = "{$this->recognize['select']} ,
                1 as priority
                {$this->recognize['from']} 
                WHERE $whereSameBothQueries $where
                ORDER BY visit_last_action_time DESC
                LIMIT 1
        ";
        
        // We join both queries and favor the one matching the visitor_id if it did match
        $sql = " ( $configSql ) 
                UNION 
                ( $visitorSql ) 
                ORDER BY priority DESC 
                LIMIT 1";
        $this->recognize['sql'] = $sql;
        $this->recognize['bind'] = $bind;
    }

    protected function adjacentVisitorId($idSite, $visitorId, $visitLastActionTime, $segment, $getNext)
    {
        if ($getNext) {
            $visitLastActionTimeCondition = "sub.visit_last_action_time <= ?";
            $orderByDir = "DESC";
        } else {
            $visitLastActionTimeCondition = "sub.visit_last_action_time >= ?";
            $orderByDir = "ASC";
        }

        $visitLastActionDate = Date::factory($visitLastActionTime);
        $dateOneDayAgo       = $visitLastActionDate->subDay(1);
        $dateOneDayInFuture  = $visitLastActionDate->addDay(1);

        $Generic = Factory::getGeneric();
        $bin_idvisitor = $Generic->binaryColumn('log_visit.idvisitor');
        $select = "$bin_idvisitor, MAX(log_visit.visit_last_action_time) as visit_last_action_time";
        $from = "log_visit";
        $where = "log_visit.idsite = ? AND log_visit.idvisitor <> ? AND visit_last_action_time >= ? and visit_last_action_time <= ?";
        $whereBind = array($idSite, $visitorId, $dateOneDayAgo->toString('Y-m-d H:i:s'), $dateOneDayInFuture->toString('Y-m-d H:i:s'));
        $orderBy = "MAX(log_visit.visit_last_action_time) $orderByDir";
        $groupBy = "log_visit.idvisitor";

        $segment = new Segment($segment, $idSite);
        $queryInfo = $segment->getSelectQuery($select, $from, $where, $whereBind, $orderBy, $groupBy);

        $sql = "SELECT sub.idvisitor, sub.visit_last_action_time
                  FROM ({$queryInfo['sql']}) as sub
                 WHERE $visitLastActionTimeCondition
                 LIMIT 1";
        $bind = array_merge($queryInfo['bind'], array($visitLastActionTime));

        return $this->db->fetchOne($sql, $bind);
    }
}
