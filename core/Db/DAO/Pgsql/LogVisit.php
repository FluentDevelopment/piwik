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
namespace Piwik\Db\DAO\Pgsql;

use Piwik\Common;
use Piwik\Db\Factory;

/**
 * @package Piwik
 * @subpackage Piwik_Db
 */

class LogVisit extends \Piwik\Db\DAO\Mysql\LogVisit
{ 
    public function __construct($db, $table)
    {
        parent::__construct($db, $table);
    }

    public function addColLocationProvider()
    {
        $sql = 'ALTER TABLE ' . $this->table . ' ADD COLUMN '
             . 'location_provider VARCHAR(100) DEFAULT NULL';
        // if the column already exist do not throw error. Could be installed twice...
        try {
            $this->db->exec($sql);
        }
        catch (Exception $e) {
            if ($e->getCode() != '42701') {
                throw $e;
            }
        }
    }

    public function removeColLocationProvider()
    {
        $sql = 'ALTER TABLE ' . $this->table . ' DROP COLUMN IF EXISTS location_provider';
        $this->db->exec($sql);
    }

    public function add($visitor_info)
    {
        $fields = implode(', ', array_keys($visitor_info));
        $values = Common::getSqlStringFieldsArray($visitor_info);
        $visitor_info['config_id'] = bin2hex($visitor_info['config_id']);
        $visitor_info['idvisitor'] = bin2hex($visitor_info['idvisitor']);
        $visitor_info['location_ip'] = bin2hex($visitor_info['location_ip']);
        $bind   = array_values($visitor_info);

        $sql = 'INSERT INTO ' . $this->table . '( ' . $fields . ') VALUES (' . $values . ')';

        $this->db->query($sql, $bind);

        return $this->db->lastInsertId($this->table.'_idvisit');
    }

    /**
     *  idvisitor, config_id and location_ip are bytea columns.
     *  They have to be casted to text, otherwise pdo is retrieving
     *  them as resources. e.g. "Resource #247" etc.
     *
     *  After retrieving the results in "loadLastVisitorDetails"
     *  idvisitor_text is converted to binary and assigned to idvisitor
     *  config_id_text is converted to binary and assinged to config_id
     *  location_ip_text is converted to binary and assigned to location_ip.
     *  idvisitor_text, config_id_text and location_ip_text are unset, so that
     *  the result set is same as the one result by Piwik_Db_DAO_LogVisit
     */
    public function loadLastVisitorDetailsSelect()
    {
        return 'log_visit.*, log_visit.idvisitor::text AS idvisitor_text, '
             . 'log_visit.config_id::text AS config_id_text, '
             . 'log_visit.location_ip::text AS location_ip_text ';
    }

    public function loadLastVisitorDetails($subQuery, $sqlLimit, $orderByParent)
    {
        $Generic = Factory::getGeneric($this->db);
        $Generic->checkByteaOutput();

        $sql = 'SELECT DISTINCT sub.* FROM ( '
             .   $subQuery['sql'] . $sqlLimit
             . ' ) AS sub '
             . 'ORDER BY ' . $orderByParent;
        
        $rows = $this->db->fetchAll($sql, $subQuery['bind']);
        while (list($k, $row) = each($rows)) {
            if (!empty($row['idvisitor'])) {
                $rows[$k]['idvisitor'] = $Generic->db2bin($row['idvisitor_text']);
            }
            unset($rows[$k]['idvisitor_text']);
            if (!empty($row['config_id'])) {
                $rows[$k]['config_id'] = $Generic->db2bin($row['config_id_text']);
            }
            unset($rows[$k]['config_id_text']);
            if (!empty($row['location_ip'])) {
                $rows[$k]['location_ip'] = $Generic->db2bin($row['location_ip_text']);
            }
            unset($rows[$k]['location_ip_text']);
        }
        reset($rows);

        return $rows;
    }

    /**
     *  recognizeVisitor
     *
     *  Uses tracker db
     *
     *  @param bool $customVariablesSet If custom variables are set in request
     *  @param string $timeLookBack Timestamp from which records will be checked
     *  @param bool $shouldMatchOneFieldOnly
     *  @param bool $matchVisitorId
     *  @param int  $idSite
     *  @param int  $configId
     *  @param int  $idVisitor
     *  @return array
     */
    public function recognizeVisitor($customVariablesSet, $timeLookBack,
                                     $shouldMatchOneFieldOnly, $matchVisitorId,
                                     $idSite, $configId, $idVisitor)
    {
        list($result, $customVariables) 
            = parent::recognizeVisitor($customVariablesSet, $timeLookBack,
                $shouldMatchOneFieldOnly, $matchVisitorId, $idSite, $configId, $idVisitor
              );

        $this->Generic->checkByteaOutput();

        if ($result['idvisitor']) {
            $result['idvisitor'] = $this->Generic->db2bin($result['idvisitor']);
        }

        return array($result, $customVariables);
    }

    /**
     *  fetchAll
     *
     *  Returns all the rows of the table.
     *
     *  @return array
     */
    public function fetchAll()
    {
        $generic = Factory::getGeneric();
        $generic->checkByteaOutput();

        $sql = 'SELECT *, idvisitor::text AS idvisitor_text, config_id::text as config_id_text,
                location_ip::text AS location_ip_text FROM ' . $this->table;
        $rows = $this->db->fetchAll($sql);
        while (list($k, $row) = each($rows)) {
            if (!empty($row['idvisitor'])) {
                $rows[$k]['idvisitor'] = $generic->db2bin($row['idvisitor_text']);
            }
            unset($rows[$k]['idvisitor_text']);
            if (!empty($row['config_id'])) {
                $rows[$k]['config_id'] = $generic->db2bin($row['config_id_text']);
            }
            unset($rows[$k]['config_id_text']);
            if (!empty($row['location_ip'])) {
                $rows[$k]['location_ip'] = $generic->db2bin($row['location_ip_text']);
            }
            unset($rows[$k]['location_ip_text']);
        }
        reset($rows);

        return $rows;
    }
}
