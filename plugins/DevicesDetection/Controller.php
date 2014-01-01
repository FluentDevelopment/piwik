<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package DevicesDetection
 */
namespace Piwik\Plugins\DevicesDetection;

use Piwik\Common;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\View;
use Piwik\ViewDataTable\Factory;
use UserAgentParserEnhanced;

class Controller extends \Piwik\Plugin\Controller
{
    public function index()
    {
        $view = new View('@DevicesDetection/index');
        $view->deviceTypes = $view->deviceModels = $view->deviceBrands = $view->osReport = $view->browserReport = "blank";
        $view->deviceTypes = $this->getType(true);
        $view->deviceBrands = $this->getBrand(true);
        $view->deviceModels = $this->getModel(true);
        $view->osReport = $this->getOsFamilies(true);
        $view->browserReport = $this->getBrowserFamilies(true);
        return $view->render();
    }

    public function getType()
    {
        return $this->renderReport(__FUNCTION__);
    }

    public function getBrand()
    {
        return $this->renderReport(__FUNCTION__);
    }

    public function getModel()
    {
        return $this->renderReport(__FUNCTION__);
    }

    public function getOsFamilies()
    {
        return $this->renderReport(__FUNCTION__);
    }

    public function getOsVersions()
    {
        return $this->renderReport(__FUNCTION__);
    }

    public function getBrowserFamilies()
    {
        return $this->renderReport(__FUNCTION__);
    }

    public function getBrowserVersions()
    {
        return $this->renderReport(__FUNCTION__);
    }

    /**
     * You may manually call this controller action to force re-processing of past user agents
     */
    public function refreshParsedUserAgents()
    {
        Piwik::checkUserIsSuperUser();
        $q = "SELECT idvisit, config_debug_ua FROM " . Common::prefixTable("log_visit");
        $res = Db::fetchAll($q);

        $output = '';

        foreach ($res as $rec) {
            $UAParser = new UserAgentParserEnhanced($rec['config_debug_ua']);
            $UAParser->parse();
            $output .= "Processing idvisit = " . $rec['idvisit'] . "<br/>";
            $output .= "UserAgent string: " . $rec['config_debug_ua'] . "<br/> Decoded values:";
            $uaDetails = $this->getArray($UAParser);
            var_export($uaDetails);
            $output .= "<hr/>";
            $this->updateVisit($rec['idvisit'], $uaDetails);
            unset($UAParser);
        }
        $output .=  "Please remember to truncate your archives !";

        return $output;
    }

    private function getArray(UserAgentParserEnhanced $UAParser)
    {
        $UADetails['config_browser_name'] = $UAParser->getBrowser("short_name");
        $UADetails['config_browser_version'] = $UAParser->getBrowser("version");
        $UADetails['config_os'] = $UAParser->getOs("short_name");
        $UADetails['config_os_version'] = $UAParser->getOs("version");
        $UADetails['config_device_type'] = $UAParser->getDevice();
        $UADetails['config_device_model'] = $UAParser->getModel();
        $UADetails['config_device_brand'] = $UAParser->getBrand();
        return $UADetails;
    }

    private function updateVisit($idVisit, $uaDetails)
    {
        $LogVisit = \Piwik\Db\Factory::getDAO('log_visit');
        $LogVisit->updateVisit($idVisit, $uaDetails);
    }
}