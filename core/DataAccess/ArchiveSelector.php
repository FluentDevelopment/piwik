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
namespace Piwik\DataAccess;

use Exception;
use Piwik\ArchiveProcessor\Rules;
use Piwik\ArchiveProcessor;
use Piwik\Common;
use Piwik\Date;
use Piwik\Db;
use Piwik\Log;

use Piwik\Period;
use Piwik\Period\Range;
use Piwik\Piwik;
use Piwik\Segment;
use Piwik\Site;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Db\Factory;

/**
 * Data Access object used to query archives
 *
 * A record in the Database for a given report is defined by
 * - idarchive     = unique ID that is associated to all the data of this archive (idsite+period+date)
 * - idsite        = the ID of the website
 * - date1         = starting day of the period
 * - date2         = ending day of the period
 * - period        = integer that defines the period (day/week/etc.). @see period::getId()
 * - ts_archived   = timestamp when the archive was processed (UTC)
 * - name          = the name of the report (ex: uniq_visitors or search_keywords_by_search_engines)
 * - value         = the actual data (a numeric value, or a blob of compressed serialized data)
 *
 */
class ArchiveSelector
{
    const NB_VISITS_RECORD_LOOKED_UP = "nb_visits";

    const NB_VISITS_CONVERTED_RECORD_LOOKED_UP = "nb_visits_converted";

    static public function getArchiveIdAndVisits(Site $site, Period $period, Segment $segment, $minDatetimeArchiveProcessedUTC, $requestedPlugin)
    {
        $pluginOrVisitsSummary = array("VisitsSummary", $requestedPlugin);
        $pluginOrVisitsSummary = array_unique($pluginOrVisitsSummary);
        $sqlWhereArchiveName = self::getNameCondition($pluginOrVisitsSummary, $segment);

        $Archive = Factory::getDAO('archive');
        $results = $Archive->getArchiveIdAndVisits(
                        ArchiveTableCreator::getNumericTable($period->getDateStart()),
                        $site,
                        $period,
                        $minDatetimeArchiveProcessedUTC,
                        $sqlWhereArchiveName
                   );
        if (empty($results)) {
            return false;
        }

        $idArchive = self::getMostRecentIdArchiveFromResults($segment, $requestedPlugin, $results);
        $idArchiveVisitsSummary = self::getMostRecentIdArchiveFromResults($segment, "VisitsSummary", $results);

        list($visits, $visitsConverted) = self::getVisitsMetricsFromResults($idArchive, $idArchiveVisitsSummary, $results);

        if ($visits === false
            && $idArchive === false
        ) {
            return false;
        }

        return array($idArchive, $visits, $visitsConverted);
    }

    protected static function getVisitsMetricsFromResults($idArchive, $idArchiveVisitsSummary, $results)
    {
        $visits = $visitsConverted = false;
        $archiveWithVisitsMetricsWasFound = ($idArchiveVisitsSummary !== false);
        if ($archiveWithVisitsMetricsWasFound) {
            $visits = $visitsConverted = 0;
        }
        foreach ($results as $result) {
            if (in_array($result['idarchive'], array($idArchive, $idArchiveVisitsSummary))) {
                $value = (int)$result['value'];
                if (empty($visits)
                    && $result['name'] == self::NB_VISITS_RECORD_LOOKED_UP
                ) {
                    $visits = $value;
                }
                if (empty($visitsConverted)
                    && $result['name'] == self::NB_VISITS_CONVERTED_RECORD_LOOKED_UP
                ) {
                    $visitsConverted = $value;
                }
            }
        }
        return array($visits, $visitsConverted);
    }

    protected static function getMostRecentIdArchiveFromResults(Segment $segment, $requestedPlugin, $results)
    {
        $idArchive = false;
        $namesRequestedPlugin = Rules::getDoneFlags(array($requestedPlugin), $segment);
        foreach ($results as $result) {
            if ($idArchive === false
                && in_array($result['name'], $namesRequestedPlugin)
            ) {
                $idArchive = $result['idarchive'];
                break;
            }
        }
        return $idArchive;
    }

    /**
     * Queries and returns archive IDs for a set of sites, periods, and a segment.
     *
     * @param array $siteIds
     * @param array $periods
     * @param Segment $segment
     * @param array $plugins List of plugin names for which data is being requested.
     * @return array Archive IDs are grouped by archive name and period range, ie,
     *               array(
     *                   'VisitsSummary.done' => array(
     *                       '2010-01-01' => array(1,2,3)
     *                   )
     *               )
     */
    static public function getArchiveIds($siteIds, $periods, $segment, $plugins)
    {
        $monthToPeriods = array();
        foreach ($periods as $period) {
            /** @var Period $period */
            $table = ArchiveTableCreator::getNumericTable($period->getDateStart());
            $monthToPeriods[$table][] = $period;
        }

        $Archive = Factory::getDAO('archive');
        $result = $Archive->getArchiveIds(
                    $siteIds,
                    $monthToPeriods,
                    self::getNameCondition($plugins, $segment)
                  );

        return $result;
    }

    /**
     * Queries and returns archive data using a set of archive IDs.
     *
     * @param array $archiveIds The IDs of the archives to get data from.
     * @param array $recordNames The names of the data to retrieve (ie, nb_visits, nb_actions, etc.)
     * @param string $archiveDataType The archive data type (either, 'blob' or 'numeric').
     * @param bool $loadAllSubtables Whether to pre-load all subtables
     * @throws Exception
     * @return array
     */
    static public function getArchiveData($archiveIds, $recordNames, $archiveDataType, $loadAllSubtables)
    {
        $Archive = Factory::getDAO('archive');
        $rows = $Archive->getArchiveData(
                    $archiveIds,
                    $recordNames,
                    $archiveDataType,
                    $loadAllSubtables
                );

        return $rows;
    }

    /**
     * Returns the SQL condition used to find successfully completed archives that
     * this instance is querying for.
     *
     * @param array $plugins
     * @param Segment $segment
     * @return string
     */
    static private function getNameCondition(array $plugins, $segment)
    {
        // the flags used to tell how the archiving process for a specific archive was completed,
        // if it was completed
        $doneFlags = Rules::getDoneFlags($plugins, $segment);

        $allDoneFlags = "'" . implode("','", $doneFlags) . "'";

        // create the SQL to find archives that are DONE
        return "(name IN ($allDoneFlags)) AND " .
        " (value = '" . ArchiveWriter::DONE_OK . "' OR " .
        " value = '" . ArchiveWriter::DONE_OK_TEMPORARY . "')";
    }

    static public function purgeOutdatedArchives(Date $dateStart)
    {
        $purgeArchivesOlderThan = Rules::shouldPurgeOutdatedArchives($dateStart);
        if (!$purgeArchivesOlderThan) {
            return;
        }

        $idArchivesToDelete = self::getTemporaryArchiveIdsOlderThan($dateStart, $purgeArchivesOlderThan);
        if (!empty($idArchivesToDelete)) {
            self::deleteArchiveIds($dateStart, $idArchivesToDelete);
        }
        self::deleteArchivesWithPeriodRange($dateStart);

        Log::debug("Purging temporary archives: done [ purged archives older than %s in %s ] [Deleted IDs: %s]",
            $purgeArchivesOlderThan, $dateStart->toString("Y-m"), implode(',', $idArchivesToDelete));
    }

    /*
     * Deleting "Custom Date Range" reports after 1 day, since they can be re-processed and would take up un-necessary space
     */
    protected static function deleteArchivesWithPeriodRange(Date $date)
    {
        $Archive = Factory::getDAO('archive');
        $Archive->deleteByPeriodRange($date);
    }

    protected static function deleteArchiveIds(Date $date, $idArchivesToDelete)
    {
        $Archive = Factory::getDAO('archive');
        $Archive->deleteByArchiveIds($date, $idArchivesToDelete);
    }

    protected static function getTemporaryArchiveIdsOlderThan(Date $date, $purgeArchivesOlderThan)
    {
        $Archive = Factory::getDAO('archive');
        $result = $Archive->getIdarchiveByValueTS(
                    ArchiveTableCreator::getNumericTable($date),
                    ArchiveProcessor::DONE_OK_TEMPORARY,
                    ArchiveProcessor::DONE_ERROR,
                    $purgeArchivesOlderThan
                  );
        $idArchivesToDelete = array();
        if (!empty($result)) {
            foreach ($result as $row) {
                $idArchivesToDelete[] = $row['idarchive'];
            }
        }
        return $idArchivesToDelete;
    }
}
