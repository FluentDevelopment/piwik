<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package Dashboard
 */
namespace Piwik\Plugins\Dashboard;

use Exception;
use Piwik\Piwik;
use Piwik\Common;
use Piwik\Site;
use Piwik\Db;
use Piwik\Db\Factory;
use Piwik\WidgetsList;
use Zend_Registry;

/**
 * @package Dashboard
 */
class Dashboard extends \Piwik\Plugin
{
    /**
     * @see Piwik_Plugin::getListHooksRegistered
     */
    public function getListHooksRegistered()
    {
        return array(
            'AssetManager.getJsFiles'  => 'getJsFiles',
            'AssetManager.getCssFiles' => 'getCssFiles',
            'UsersManager.deleteUser'  => 'deleteDashboardLayout',
            'Menu.add'                 => 'addMenus',
            'TopMenu.add'              => 'addTopMenu',
        );
    }

    /**
     * Returns the layout in the DB for the given user, or false if the layout has not been set yet.
     * Parameters must be checked BEFORE this function call
     *
     * @param string $login
     * @param int $idDashboard
     *
     * @return bool|string
     */
    public function getLayoutForUser($login, $idDashboard)
    {
        $UserDashboard = Factory::getDAO('user_dashboard');
        $return = $UserDashboard->getLayoutByLoginDashboard($login, $idDashboard);

        if (count($return) == 0) {
            return false;
        }

        return $return[0]['layout'];
    }

    public function getDefaultLayout()
    {
        $defaultLayout = $this->getLayoutForUser('', 1);

        if (empty($defaultLayout)) {
            if (Piwik::isUserIsSuperUser()) {
                $topWidget = '{"uniqueId":"widgetCoreHomegetDonateForm",'
                    . '"parameters":{"module":"CoreHome","action":"getDonateForm"}},';
            } else {
                $topWidget = '{"uniqueId":"widgetCoreHomegetPromoVideo",'
                    . '"parameters":{"module":"CoreHome","action":"getPromoVideo"}},';
            }

            $defaultLayout = '[
                [
                    {"uniqueId":"widgetVisitsSummarygetEvolutionGraphcolumnsArray","parameters":{"module":"VisitsSummary","action":"getEvolutionGraph","columns":"nb_visits"}},
                    {"uniqueId":"widgetLivewidget","parameters":{"module":"Live","action":"widget"}},
                    {"uniqueId":"widgetVisitorInterestgetNumberOfVisitsPerVisitDuration","parameters":{"module":"VisitorInterest","action":"getNumberOfVisitsPerVisitDuration"}}
                ],
                [
                    ' . $topWidget . '
                    {"uniqueId":"widgetReferersgetKeywords","parameters":{"module":"Referers","action":"getKeywords"}},
                    {"uniqueId":"widgetReferersgetWebsites","parameters":{"module":"Referers","action":"getWebsites"}}
                ],
                [
                    {"uniqueId":"widgetUserCountryMapvisitorMap","parameters":{"module":"UserCountryMap","action":"visitorMap"}},
                    {"uniqueId":"widgetUserSettingsgetBrowser","parameters":{"module":"UserSettings","action":"getBrowser"}},
                    {"uniqueId":"widgetReferersgetSearchEngines","parameters":{"module":"Referers","action":"getSearchEngines"}},
                    {"uniqueId":"widgetVisitTimegetVisitInformationPerServerTime","parameters":{"module":"VisitTime","action":"getVisitInformationPerServerTime"}},
                    {"uniqueId":"widgetExampleRssWidgetrssPiwik","parameters":{"module":"ExampleRssWidget","action":"rssPiwik"}}
                ]
            ]';
        }

        $defaultLayout = $this->removeDisabledPluginFromLayout($defaultLayout);

        return $defaultLayout;
    }

    public function getAllDashboards($login)
    {
        $UserDashboard = Factory::getDAO('user_dashboard');
        $dashboards = $UserDashboard->getByLogin($login);

        $nameless = 1;
        foreach ($dashboards AS &$dashboard) {

            if (empty($dashboard['name'])) {
                $dashboard['name'] = Piwik_Translate('Dashboard_DashboardOf', $login);
                if ($nameless > 1) {
                    $dashboard['name'] .= " ($nameless)";
                }

                $nameless++;
            }

            $dashboard['name'] = Common::unsanitizeInputValue($dashboard['name']);

            $layout = '[]';
            if (!empty($dashboard['layout'])) {
                $layout = $dashboard['layout'];
            }

            $dashboard['layout'] = $this->decodeLayout($layout);
        }

        return $dashboards;
    }

    private function isAlreadyDecodedLayout($layout)
    {
        return !is_string($layout);
    }

    public function removeDisabledPluginFromLayout($layout)
    {
        $layoutObject = $this->decodeLayout($layout);

        // if the json decoding works (ie. new Json format)
        // we will only return the widgets that are from enabled plugins

        if (is_array($layoutObject)) {
            $layoutObject = (object)array(
                'config'  => array('layout' => '33-33-33'),
                'columns' => $layoutObject
            );
        }

        if (empty($layoutObject) || empty($layoutObject->columns)) {
            $layoutObject = (object)array(
                'config'  => array('layout' => '33-33-33'),
                'columns' => array()
            );
        }

        foreach ($layoutObject->columns as &$row) {
            if (!is_array($row)) {
                $row = array();
                continue;
            }

            foreach ($row as $widgetId => $widget) {
                if (isset($widget->parameters->module)) {
                    $controllerName = $widget->parameters->module;
                    $controllerAction = $widget->parameters->action;
                    if (!WidgetsList::isDefined($controllerName, $controllerAction)) {
                        unset($row[$widgetId]);
                    }
                } else {
                    unset($row[$widgetId]);
                }
            }
        }
        $layout = $this->encodeLayout($layoutObject);
        return $layout;
    }

    public function decodeLayout($layout)
    {
        if ($this->isAlreadyDecodedLayout($layout)) {
            return $layout;
        }

        $layout = html_entity_decode($layout);
        $layout = str_replace("\\\"", "\"", $layout);
        $layout = str_replace("\n", "", $layout);

        return Common::json_decode($layout, $assoc = false);
    }

    public function encodeLayout($layout)
    {
        return Common::json_encode($layout);
    }

    public function addMenus()
    {
        Piwik_AddMenu('Dashboard_Dashboard', '', array('module' => 'Dashboard', 'action' => 'embeddedIndex', 'idDashboard' => 1), true, 5);

        if (!Piwik::isUserIsAnonymous()) {
            $login = Piwik::getCurrentUserLogin();

            $dashboards = $this->getAllDashboards($login);
            if (count($dashboards) > 1) {
                $pos = 0;
                foreach ($dashboards AS $dashboard) {
                    Piwik_AddMenu('Dashboard_Dashboard', $dashboard['name'], array('module' => 'Dashboard', 'action' => 'embeddedIndex', 'idDashboard' => $dashboard['iddashboard']), true, $pos);
                    $pos++;
                }
            }
        }
    }

    public function addTopMenu()
    {
        $tooltip = false;
        try {
            $idSite = Common::getRequestVar('idSite');
            $tooltip = Piwik_Translate('Dashboard_TopLinkTooltip', Site::getNameFor($idSite));
        } catch (Exception $ex) {
            // if no idSite parameter, show no tooltip
        }

        $urlParams = array('module' => 'CoreHome', 'action' => 'index');
        Piwik_AddTopMenu('General_Dashboard', $urlParams, true, 1, $isHTML = false, $tooltip);
    }

    public function getJsFiles(&$jsFiles)
    {
        $jsFiles[] = "plugins/Dashboard/javascripts/widgetMenu.js";
        $jsFiles[] = "libs/javascript/json2.js";
        $jsFiles[] = "plugins/Dashboard/javascripts/dashboardObject.js";
        $jsFiles[] = "plugins/Dashboard/javascripts/dashboardWidget.js";
        $jsFiles[] = "plugins/Dashboard/javascripts/dashboard.js";
    }

    public function getCssFiles(&$cssFiles)
    {
        $cssFiles[] = "plugins/CoreHome/stylesheets/dataTable.less";
        $cssFiles[] = "plugins/Dashboard/stylesheets/dashboard.less";
    }

    public function deleteDashboardLayout($userLogin)
    {
        $userLogin = $notification->getNotificationObject();
        $UserDashboard = Factory::getDAO('user_dashboard');
        $UserDashboard->deleteByLogin($userLogin);
    }

    public function install()
    {
        $UserDashboard = Factory::getDAO('user_dashboard');
        $UserDashboard->install();
    }

    public function uninstall()
    {
        $UserDashboard = Factory::getDAO('user_dashboard');
        $UserDashboard->uninstall();
    }
}
