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
use Piwik\Db\DAO\Base;
use Piwik\Db\Factory;
use Piwik\Tracker\Action;

/**
 * @package Piwik
 * @subpackage Piwik_Db
 */
class LogLinkVisitAction extends Base
{
    public function __construct($db, $table)
    {
        parent::__construct($db, $table);
    }

    public function getActionDetailsOfIdvisit($customVariables, $idvisit, $actionsLimit)
    {
        // The second join is a LEFT join to allow returning records that don't have a matching page title
        // eg. Downloads, Outlinks. For these, idaction_name is set to 0
        $serverTimePretty = $this->db->quoteIdentifier('serverTimePretty');
        $pageId = $this->db->quoteIdentifier('pageId');
        $timeSpentRef = $this->db->quoteIdentifier('timeSpentRef');
        # ORDER BY clause has been added to make sure the result set is in specific order
        # so that the tests pass. Without ORDER BY postgresql is returning in descending order
        # of serverTimePretty; when serverTimePretty is equal, result set is in descending
        # order of the pageId.
        # MySQL on the other hand, is returning in ascending order of serverTimePretty; when
        # serverTimePretty is equal, result set is in ascending order of pageId.
        $sql = 'SELECT '
             . '   COALESCE(log_action.type,log_action_title.type) AS type '
             . ' , log_action.name AS url '
             . ' , log_action.url_prefix '
             . ' , log_action_title.name AS ' . $this->db->quoteIdentifier('pageTitle') .' '
             . ' , log_action.idaction AS ' . $this->db->quoteIdentifier('pageIdAction') .' '
             . ' , log_link_visit_action.idlink_va AS ' . $pageId . ' '
             . ' , log_link_visit_action.server_time AS ' . $serverTimePretty . ' '
             . ' , log_link_visit_action.time_spent_ref_action AS ' . $timeSpentRef . ' '
             . ' , log_link_visit_action.custom_float '
             . $customVariables . ' '
             . 'FROM ' . $this->table . ' AS log_link_visit_action '
             . 'LEFT OUTER JOIN ' . Common::prefixTable('log_action') . ' AS log_action '
             . '    ON log_link_visit_action.idaction_url = log_action.idaction '
             . 'LEFT OUTER JOIN ' . Common::prefixTable('log_action') . ' AS log_action_title '
             . '    ON log_link_visit_action.idaction_name = log_action_title.idaction '
             . 'WHERE log_link_visit_action.idvisit = ? '
             . 'ORDER BY ' . $serverTimePretty . ', ' . $pageId . ' '
             . 'LIMIT ' . $actionsLimit . ' OFFSET 0';

        return $this->db->fetchAll($sql, array($idvisit));
    }

    public function record($idvisit, $idsite, $idvisitor, $server_time,
                        $url, $name, $ref_url, $ref_name, $time_spent,
                        $time_generation, $custom_variables
                        )
    {
        list($sql, $bind) = $this->paramsRecord(
            $idvisit, $idsite, $idvisitor, $server_time,
            $url, $name, $ref_url, $ref_name, $time_spent,
            $time_generation, $custom_variables
        );

        $this->db->query($sql, $bind);

        return $this->db->lastInsertId();
    }

    public function add($idsite, $idvisitor, $server_time, $idvisit,
                        $idaction_url, $idaction_url_ref, $idaction_name,
                        $idaction_name_ref, $time_spent_ref_action)
    {
        $Generic = Factory::getGeneric($this->db);

        $sql = 'INSERT INTO ' . $this->table . ' (idsite '
             . ', idvisitor '
             . ', server_time '
             . ', idvisit '
             . ', idaction_url '
             . ', idaction_url_ref '
             . ', idaction_name '
             . ', idaction_name_ref '
             . ', time_spent_ref_action '
             . ') VALUES ( ' . $idsite 
             . ', ' . $Generic->bin2db($idvisitor)
             . ', ' . $server_time
             . ', ' . $idvisit
             . ', ' . $idaction_url
             . ', ' . $idaction_url_ref
             . ', ' . $idaction_name
             . ', ' . $idaction_name_ref
             . ', ' . $time_spent_ref_action
             . ');';

        $this->db->query($sql);
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
    
    protected function paramsRecord($idvisit, $idsite, $idvisitor, $server_time,
                        $url, $name, $ref_url, $ref_name, $time_spent,
                        $time_generation, $custom_variables
                        )
    {
        $Generic = Factory::getGeneric($this->db);
        $insert = array(
            'idvisit'   => $idvisit,
            'idsite'    => $idsite,
            'idvisitor' => $Generic->bin2db($idvisitor),
            'server_time'   => $server_time,
            'idaction_url'  => $url,
            'idaction_name' => $name,
            'idaction_url_ref'  => $ref_url,
            'idaction_name_ref' => $ref_name,
            'time_spent_ref_action' => $time_spent
        );
        if (!empty($time_generation)) {
            $insert[Action::DB_COLUMN_TIME_GENERATION] = $time_generation;
        }

        $insert = array_merge($insert, $custom_variables);

        // Mysqli apparently does not like NULL inserts?
        $insertWithoutNulls = array();
        foreach ($insert as $column => $value) {
            if (!is_null($value) || $column == 'idaction_url_ref') {
                $insertWithoutNulls[$column] = $value;
            }
        }

        $fields = implode(', ', array_keys($insertWithoutNulls));
        $bind   = array_values($insertWithoutNulls);
        $values = Common::getSqlStringFieldsArray($insertWithoutNulls);
        Common::printDebug($insertWithoutNulls);

        $sql = 'INSERT INTO ' . $this->table . '( ' . $fields . ') VALUES ( ' . $values . ')';

        return array($sql, $bind);
    }
} 
