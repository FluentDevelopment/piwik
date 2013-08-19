<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package Provider
 */
namespace Piwik\Plugins\Provider;

use Exception;
use Piwik\Common;
use Piwik\FrontController;
use Piwik\IP;
use Piwik\ArchiveProcessor;
use Piwik\Db;
use Piwik\Plugins\Provider\Archiver;
use Piwik\WidgetsList;
use Zend_Registry;

/**
 *
 * @package Provider
 */
class Provider extends \Piwik\Plugin
{
    /**
     * @see Piwik_Plugin::getListHooksRegistered
     */
    public function getListHooksRegistered()
    {
        $hooks = array(
            'ArchiveProcessing_Day.compute'            => 'archiveDay',
            'ArchiveProcessing_Period.compute'         => 'archivePeriod',
            'Tracker.newVisitorInformation'            => 'logProviderInfo',
            'WidgetsList.add'                          => 'addWidget',
            'Menu.add'                                 => 'addMenu',
            'API.getReportMetadata'                    => 'getReportMetadata',
            'API.getSegmentsMetadata'                  => 'getSegmentsMetadata',
            'ViewDataTable.getReportDisplayProperties' => 'getReportDisplayProperties',
        );
        return $hooks;
    }

    public function getReportMetadata(&$reports)
    {
        $reports[] = array(
            'category'      => Piwik_Translate('General_Visitors'),
            'name'          => Piwik_Translate('Provider_ColumnProvider'),
            'module'        => 'Provider',
            'action'        => 'getProvider',
            'dimension'     => Piwik_Translate('Provider_ColumnProvider'),
            'documentation' => Piwik_Translate('Provider_ProviderReportDocumentation', '<br />'),
            'order'         => 50
        );
    }

    public function getSegmentsMetadata(&$segments)
    {
        $segments[] = array(
            'type'           => 'dimension',
            'category'       => 'Visit Location',
            'name'           => Piwik_Translate('Provider_ColumnProvider'),
            'segment'        => 'provider',
            'acceptedValues' => 'comcast.net, proxad.net, etc.',
            'sqlSegment'     => 'log_visit.location_provider'
        );
    }

    public function install()
    {
        // add column hostname / hostname ext in the visit table
        $LogVisit = Piwik_Db_Factory::getDAO('log_visit');
        $LogVisit->addColLocationProvider();
    }

    public function uninstall()
    {
        // remove column hostname / hostname ext in the visit table
        $LogVisit = Piwik_Db_Factory::getDAO('log_visit');
        $LogVisit->removeColLocationProvider();
    }

    public function addWidget()
    {
        WidgetsList::add('General_Visitors', 'Provider_WidgetProviders', 'Provider', 'getProvider');
    }

    public function addMenu()
    {
        Piwik_RenameMenuEntry('General_Visitors', 'UserCountry_SubmenuLocations',
            'General_Visitors', 'Provider_SubmenuLocationsProvider');
    }

    public function postLoad()
    {
        Piwik_AddAction('template_footerUserCountry', array('Piwik\Plugins\Provider\Provider', 'footerUserCountry'));
    }

    /**
     * Logs the provider in the log_visit table
     */
    public function logProviderInfo(&$visitorInfo)
    {
        // if provider info has already been set, abort
        if (!empty($visitorInfo['location_provider'])) {
            return;
        }

        $ip = IP::N2P($visitorInfo['location_ip']);

        // In case the IP was anonymized, we should not continue since the DNS reverse lookup will fail and this will slow down tracking
        if (substr($ip, -2, 2) == '.0') {
            Common::printDebug("IP Was anonymized so we skip the Provider DNS reverse lookup...");
            return;
        }

        $hostname = $this->getHost($ip);
        $hostnameExtension = $this->getCleanHostname($hostname);

        // add the provider value in the table log_visit
        $visitorInfo['location_provider'] = $hostnameExtension;
        $visitorInfo['location_provider'] = substr($visitorInfo['location_provider'], 0, 100);

        // improve the country using the provider extension if valid
        $hostnameDomain = substr($hostnameExtension, 1 + strrpos($hostnameExtension, '.'));
        if ($hostnameDomain == 'uk') {
            $hostnameDomain = 'gb';
        }
        if (array_key_exists($hostnameDomain, Common::getCountriesList())) {
            $visitorInfo['location_country'] = $hostnameDomain;
        }
    }

    /**
     * Returns the hostname extension (site.co.jp in fvae.VARG.ceaga.site.co.jp)
     * given the full hostname looked up from the IP
     *
     * @param string $hostname
     *
     * @return string
     */
    private function getCleanHostname($hostname)
    {
        $extToExclude = array(
            'com', 'net', 'org', 'co'
        );

        $off = strrpos($hostname, '.');
        $ext = substr($hostname, $off);

        if (empty($off) || is_numeric($ext) || strlen($hostname) < 5) {
            return 'Ip';
        } else {
            $cleanHostname = null;
            Piwik_PostEvent('Provider.getCleanHostname', array(&$cleanHostname, $hostname));
            if ($cleanHostname !== null) {
                return $cleanHostname;
            }

            $e = explode('.', $hostname);
            $s = sizeof($e);

            // if extension not correct
            if (isset($e[$s - 2]) && in_array($e[$s - 2], $extToExclude)) {
                return $e[$s - 3] . "." . $e[$s - 2] . "." . $e[$s - 1];
            } else {
                return $e[$s - 2] . "." . $e[$s - 1];
            }
        }
    }

    /**
     * Returns the hostname given the IP address string
     *
     * @param string $ip IP Address
     * @return string hostname (or human-readable IP address)
     */
    private function getHost($ip)
    {
        return trim(strtolower(@IP::getHostByAddr($ip)));
    }

    static public function footerUserCountry(&$out)
    {
        $out = '<div>
			<h2>' . Piwik_Translate('Provider_WidgetProviders') . '</h2>';
        $out .= FrontController::getInstance()->fetchDispatch('Provider', 'getProvider');
        $out .= '</div>';
    }

    /**
     * Daily archive: processes the report Visits by Provider
     */
    public function archiveDay(ArchiveProcessor\Day $archiveProcessor)
    {
        $archiving = new Archiver($archiveProcessor);
        if ($archiving->shouldArchive()) {
            $archiving->archiveDay();
        }
    }

    public function archivePeriod(ArchiveProcessor\Period $archiveProcessor)
    {
        $archiving = new Archiver($archiveProcessor);
        if ($archiving->shouldArchive()) {
            $archiving->archivePeriod();
        }
    }

    public function getReportDisplayProperties(&$properties)
    {
        $properties['Provider.getProvider'] = $this->getDisplayPropertiesForGetProvider();
    }

    private function getDisplayPropertiesForGetProvider()
    {
        return array(
            'translations' => array('label' => Piwik_Translate('Provider_ColumnProvider')),
            'filter_limit' => 5
        );
    }
}
