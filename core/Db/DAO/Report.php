<?php
/**
 * Piwik - Open source web analytics
 $*
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

class Piwik_Db_DAO_Report extends Piwik_Db_DAO_Base
{
	public function __construct($db, $table)
	{
		parent::__construct($db, $table);
	}

	public function getMaxIdreport()
	{
		$sql = 'SELECT MAX(idreport) + 1 FROM ' . $this->table;
		return $this->db->fetchOne($sql);
	}

	public function insert($idreport, $idsite, $login, $description, $period,
						   $type, $format, $parameters, $reports, $ts_created, $deleted)
	{
		$this->db->insert($this->table,
			array(
				'idreport' => $idreport,
				'idsite'   => $idsite,
				'login'    => $login,
				'description' => $description,
				'period'      => $period,
				'type'        => $type,
				'format'      => $format,
				'parameters'  => $parameters,
				'reports'     => $reports,
				'ts_created'  => $ts_created,
				'deleted'     => $deleted
			)
		);
	}

	public function updateByIdreport($values, $idreport)
	{
		$this->db->update($this->table, $values, "idreport = '$idreport'");
	}

	public function getAllActive($idSite, $period, $idReport, $ifSuperUserReturnOnlySuperUserReports)
	{
		list($where, $params) = $this->varsGetAllActive($idSite, $period, $idReport, $ifSuperUserReturnOnlySuperUserReports);
		if (empty($where)) {
			$where = '';
		}
		else {
			$where = ' AND ' . implode(' AND ', $where) . ' ';
		}

		$sql = 'SELECT * FROM ' . $this->table . ' '
			 . 'INNER JOIN ' . Piwik_Common::prefixTable('site') . ' '
			 . '	USING (idsite) '
			 . 'WHERE deleted = 0 ' . $where;

		return $this->db->fetchAll($sql, $params);
	}

	public function deleteByLogin($login)
	{
		$sql = 'DELETE FROM ' . $this->table . ' WHERE login = ?';
		$this->db->query($sql, array($login));
	}

	public function createTable()
	{
        $sql = 'CREATE TABLE '.$this->table . '(
					idreport INT(11) NOT NULL AUTO_INCREMENT,
					idsite INTEGER(11) NOT NULL,
					login VARCHAR(100) NOT NULL,
					description VARCHAR(255) NOT NULL,
					period VARCHAR(10) NOT NULL,
					type VARCHAR(10) NOT NULL,
					format VARCHAR(10) NOT NULL,
					reports TEXT NOT NULL,
					parameters TEXT NULL,
					ts_created TIMESTAMP NULL,
					ts_last_sent TIMESTAMP NULL,
					deleted tinyint(4) NOT NULL default 0,
					PRIMARY KEY (idreport)
				) DEFAULT CHARSET=utf8';
		try {
			$this->db->exec($sql);
		}
		catch (Exception $e) {
			if (!$this->db->isErrNo($e, '1050'))
			{
				throw $e;
			}
		}
	}

	public function truncateTable()
	{
		$sql = 'TRUNCATE TABLE ' . $this->table;
		$this->db->query($sql);
	}

	protected function varsGetAllActive($idsite, $period, $idreport, $ifSuperUserReturnOnlySuperUserReports)
	{
		$where = array();
		$params = array();
		if (!Piwik::isUserIsSuperUser() || $ifSuperUserReturnOnlySuperUserReports)
		{
			$where[] = ' login = ? ';
			$params[] = Piwik::getCurrentUserLogin();
		}
		if (!empty($period))
		{
			$where[] = ' period = ? ';
			$params[] = $period;
		}
		if (!empty($idsite))
		{
			// Joining with the site table to work around pre-1.3 where reports could still be linked to a deleted site
			$where[] = Piwik_Common::prefixTable('site') . '.idsite = ? ';
			$params[] = $idsite;
		}
		if (!empty($idreport))
		{
			$where[] = ' idreport = ? ';
			$params[] = $idreport;
		}

		return array($where, $params);
	}
}
