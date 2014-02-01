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
use Piwik\Plugin\ControllerAdmin;
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

    public function deviceDetection()
    {
        Piwik::checkUserHasSomeAdminAccess();

        $view = new View('@DevicesDetection/detection');
        $this->setBasicVariablesView($view);
        ControllerAdmin::setBasicVariablesAdminView($view);

        $userAgent = Common::getRequestVar('ua', $_SERVER['HTTP_USER_AGENT'], 'string');

        $parsedUA = UserAgentParserEnhanced::getInfoFromUserAgent($userAgent);

        $view->userAgent           = $userAgent;
        $view->browser_name        = $parsedUA['browser']['name'];
        $view->browser_short_name  = $parsedUA['browser']['short_name'];
        $view->browser_version     = $parsedUA['browser']['version'];
        $view->browser_logo        = getBrowserLogoExtended($parsedUA['browser']['short_name']);
        $view->browser_family      = $parsedUA['browser_family'];
        $view->browser_family_logo = getBrowserFamilyLogoExtended($parsedUA['browser_family']);
        $view->os_name             = $parsedUA['os']['name'];
        $view->os_logo             = getOsLogoExtended($parsedUA['os']['short_name']);
        $view->os_short_name       = $parsedUA['os']['short_name'];
        $view->os_family           = $parsedUA['os_family'];
        $view->os_family_logo      = getOsFamilyLogoExtended($parsedUA['os_family']);
        $view->os_version          = $parsedUA['os']['version'];
        $view->device_type         = $parsedUA['device']['type'];
        $view->device_type_logo    = getDeviceTypeLogo(ucfirst($view->device_type));
        $view->device_model        = $parsedUA['device']['model'];
        $view->device_brand        = getDeviceBrandLabel($parsedUA['device']['brand']);
        $view->device_brand_logo   = getBrandLogo($view->device_brand);

        return $view->render();
    }
}
