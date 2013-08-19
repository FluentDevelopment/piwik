<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package UsersManager
 */
namespace Piwik\Plugins\UsersManager;

use Exception;
use Piwik\API\ResponseBuilder;
use Piwik\Controller\Admin;
use Piwik\Piwik;
use Piwik\Common;
use Piwik\Config;
use Piwik\Tracker\IgnoreCookie;
use Piwik\View;
use Piwik\Url;
use Piwik\Site;
use Piwik\Plugins\SitesManager\API as SitesManagerAPI;
use Piwik\Plugins\UsersManager\API as UsersManagerAPI;

/**
 *
 * @package UsersManager
 */
class Controller extends Admin
{
    static function orderByName($a, $b)
    {
        return strcmp($a['name'], $b['name']);
    }

    /**
     * The "Manage Users and Permissions" Admin UI screen
     */
    function index()
    {
        Piwik::checkUserIsNotAnonymous();

        $view = new View('@UsersManager/index');

        $IdSitesAdmin = SitesManagerAPI::getInstance()->getSitesIdWithAdminAccess();
        $idSiteSelected = 1;

        if (count($IdSitesAdmin) > 0) {
            $defaultWebsiteId = $IdSitesAdmin[0];
            $idSiteSelected = Common::getRequestVar('idSite', $defaultWebsiteId);
        }

        if ($idSiteSelected === 'all') {
            $usersAccessByWebsite = array();
            $defaultReportSiteName = Piwik_Translate('UsersManager_ApplyToAllWebsites');
        } else {
            $usersAccessByWebsite = UsersManagerAPI::getInstance()->getUsersAccessFromSite($idSiteSelected);
            $defaultReportSiteName = Site::getNameFor($idSiteSelected);
        }

        // we dont want to display the user currently logged so that the user can't change his settings from admin to view...
        $currentlyLogged = Piwik::getCurrentUserLogin();
        $usersLogin = UsersManagerAPI::getInstance()->getUsersLogin();
        foreach ($usersLogin as $login) {
            if (!isset($usersAccessByWebsite[$login])) {
                $usersAccessByWebsite[$login] = 'noaccess';
            }
        }
        unset($usersAccessByWebsite[$currentlyLogged]);

        // $usersAccessByWebsite is not supposed to contain unexistant logins, but it does when upgrading from some old Piwik version
        foreach ($usersAccessByWebsite as $login => $access) {
            if (!in_array($login, $usersLogin)) {
                unset($usersAccessByWebsite[$login]);
                continue;
            }
        }

        ksort($usersAccessByWebsite);

        $users = array();
        $usersAliasByLogin = array();
        if (Piwik::isUserHasSomeAdminAccess()) {
            $users = UsersManagerAPI::getInstance()->getUsers();
            foreach ($users as $user) {
                $usersAliasByLogin[$user['login']] = $user['alias'];
            }
        }
        $view->anonymousHasViewAccess = $this->hasAnonymousUserViewAccess($usersAccessByWebsite);
        $view->idSiteSelected = $idSiteSelected;
        $view->defaultReportSiteName = $defaultReportSiteName;
        $view->users = $users;
        $view->usersAliasByLogin = $usersAliasByLogin;
        $view->usersCount = count($users) - 1;
        $view->usersAccessByWebsite = $usersAccessByWebsite;
        $websites = SitesManagerAPI::getInstance()->getSitesWithAdminAccess();
        uasort($websites, array('Piwik\Plugins\UsersManager\Controller', 'orderByName'));
        $view->websites = $websites;
        $this->setBasicVariablesView($view);
        echo $view->render();
    }

    private function hasAnonymousUserViewAccess($usersAccessByWebsite)
    {
        $anonymousHasViewAccess = false;
        foreach ($usersAccessByWebsite as $login => $access) {
            if ($login == 'anonymous'
                && $access != 'noaccess'
            ) {
                $anonymousHasViewAccess = true;
            }
        }
        return $anonymousHasViewAccess;
    }

    /**
     * Returns default date for Piwik reports
     *
     * @param string $user
     * @return string today, yesterday, week, month, year
     */
    protected function getDefaultDateForUser($user)
    {
        return UsersManagerAPI::getInstance()->getUserPreference($user, UsersManagerAPI::PREFERENCE_DEFAULT_REPORT_DATE);
    }

    /**
     * The "User Settings" admin UI screen view
     */
    public function userSettings()
    {
        Piwik::checkUserIsNotAnonymous();

        $view = new View('@UsersManager/userSettings');

        $userLogin = Piwik::getCurrentUserLogin();
        if (Piwik::isUserIsSuperUser()) {
            $view->userAlias = $userLogin;
            $view->userEmail = Piwik::getSuperUserEmail();
            $this->displayWarningIfConfigFileNotWritable($view);
        } else {
            $user = UsersManagerAPI::getInstance()->getUser($userLogin);
            $view->userAlias = $user['alias'];
            $view->userEmail = $user['email'];
        }

        $defaultReport = UsersManagerAPI::getInstance()->getUserPreference($userLogin, UsersManagerAPI::PREFERENCE_DEFAULT_REPORT);
        if ($defaultReport === false) {
            $defaultReport = $this->getDefaultWebsiteId();
        }
        $view->defaultReport = $defaultReport;

        if ($defaultReport == 'MultiSites') {
            $view->defaultReportSiteName = Site::getNameFor($this->getDefaultWebsiteId());
        } else {
            $view->defaultReportSiteName = Site::getNameFor($defaultReport);
        }

        $view->defaultDate = $this->getDefaultDateForUser($userLogin);
        $view->availableDefaultDates = array(
            'today'      => Piwik_Translate('General_Today'),
            'yesterday'  => Piwik_Translate('General_Yesterday'),
            'previous7'  => Piwik_Translate('General_PreviousDays', 7),
            'previous30' => Piwik_Translate('General_PreviousDays', 30),
            'last7'      => Piwik_Translate('General_LastDays', 7),
            'last30'     => Piwik_Translate('General_LastDays', 30),
            'week'       => Piwik_Translate('General_CurrentWeek'),
            'month'      => Piwik_Translate('General_CurrentMonth'),
            'year'       => Piwik_Translate('General_CurrentYear'),
        );

        $view->ignoreCookieSet = IgnoreCookie::isIgnoreCookieFound();
        $this->initViewAnonymousUserSettings($view);
        $view->piwikHost = Url::getCurrentHost();
        $this->setBasicVariablesView($view);
        echo $view->render();
    }

    public function setIgnoreCookie()
    {
        Piwik::checkUserHasSomeViewAccess();
        Piwik::checkUserIsNotAnonymous();
        $this->checkTokenInUrl();

        IgnoreCookie::setIgnoreCookie();
        Piwik::redirectToModule('UsersManager', 'userSettings', array('token_auth' => false));
    }

    /**
     * The Super User can modify Anonymous user settings
     * @param View $view
     */
    protected function initViewAnonymousUserSettings($view)
    {
        if (!Piwik::isUserIsSuperUser()) {
            return;
        }
        $userLogin = 'anonymous';

        // Which websites are available to the anonymous users?
        $anonymousSitesAccess = UsersManagerAPI::getInstance()->getSitesAccessFromUser($userLogin);
        $anonymousSites = array();
        foreach ($anonymousSitesAccess as $info) {
            $idSite = $info['site'];
            $site = SitesManagerAPI::getInstance()->getSiteFromId($idSite);
            // Work around manual website deletion
            if (!empty($site)) {
                $anonymousSites[$idSite] = $site;
            }
        }
        $view->anonymousSites = $anonymousSites;

        // Which report is displayed by default to the anonymous user?
        $anonymousDefaultReport = UsersManagerAPI::getInstance()->getUserPreference($userLogin, UsersManagerAPI::PREFERENCE_DEFAULT_REPORT);
        if ($anonymousDefaultReport === false) {
            if (empty($anonymousSites)) {
                $anonymousDefaultReport = Piwik::getLoginPluginName();
            } else {
                // we manually imitate what would happen, in case the anonymous user logs in
                // and is redirected to the first website available to him in the list
                // @see getDefaultWebsiteId()
                reset($anonymousSites);
                $anonymousDefaultReport = key($anonymousSites);
            }
        }
        $view->anonymousDefaultReport = $anonymousDefaultReport;

        $view->anonymousDefaultDate = $this->getDefaultDateForUser($userLogin);
    }

    /**
     * Records settings for the anonymous users (default report, default date)
     */
    public function recordAnonymousUserSettings()
    {
        $response = new ResponseBuilder(Common::getRequestVar('format'));
        try {
            Piwik::checkUserIsSuperUser();
            $this->checkTokenInUrl();

            $anonymousDefaultReport = Common::getRequestVar('anonymousDefaultReport');
            $anonymousDefaultDate = Common::getRequestVar('anonymousDefaultDate');
            $userLogin = 'anonymous';
            UsersManagerAPI::getInstance()->setUserPreference($userLogin,
                UsersManagerAPI::PREFERENCE_DEFAULT_REPORT,
                $anonymousDefaultReport);
            UsersManagerAPI::getInstance()->setUserPreference($userLogin,
                UsersManagerAPI::PREFERENCE_DEFAULT_REPORT_DATE,
                $anonymousDefaultDate);
            $toReturn = $response->getResponse();
        } catch (Exception $e) {
            $toReturn = $response->getResponseException($e);
        }
        echo $toReturn;
    }

    /**
     * Records settings from the "User Settings" page
     * @throws Exception
     */
    public function recordUserSettings()
    {
        $response = new ResponseBuilder(Common::getRequestVar('format'));
        try {
            $this->checkTokenInUrl();

            $alias = Common::getRequestVar('alias');
            $email = Common::getRequestVar('email');
            $defaultReport = Common::getRequestVar('defaultReport');
            $defaultDate = Common::getRequestVar('defaultDate');

            $newPassword = false;
            $password = Common::getRequestvar('password', false);
            $passwordBis = Common::getRequestvar('passwordBis', false);
            if (!empty($password)
                || !empty($passwordBis)
            ) {
                if ($password != $passwordBis) {
                    throw new Exception(Piwik_Translate('Login_PasswordsDoNotMatch'));
                }
                $newPassword = $password;
            }

            // UI disables password change on invalid host, but check here anyway
            if (!Url::isValidHost()
                && $newPassword !== false
            ) {
                throw new Exception("Cannot change password with untrusted hostname!");
            }

            $userLogin = Piwik::getCurrentUserLogin();
            if (Piwik::isUserIsSuperUser()) {
                $superUser = Config::getInstance()->superuser;
                $updatedSuperUser = false;

                if ($newPassword !== false) {
                    $newPassword = Common::unsanitizeInputValue($newPassword);
                    $md5PasswordSuperUser = md5($newPassword);
                    $superUser['password'] = $md5PasswordSuperUser;
                    $updatedSuperUser = true;
                }
                if ($superUser['email'] != $email) {
                    $superUser['email'] = $email;
                    $updatedSuperUser = true;
                }
                if ($updatedSuperUser) {
                    Config::getInstance()->superuser = $superUser;
                    Config::getInstance()->forceSave();
                }
            } else {
                UsersManagerAPI::getInstance()->updateUser($userLogin, $newPassword, $email, $alias);
                if ($newPassword !== false) {
                    $newPassword = Common::unsanitizeInputValue($newPassword);
                }
            }

            // logs the user in with the new password
            if ($newPassword !== false) {
                $info = array(
                    'login'       => $userLogin,
                    'md5Password' => md5($newPassword),
                    'rememberMe'  => false,
                );
                Piwik_PostEvent('Login.initSession', array($info));
            }

            UsersManagerAPI::getInstance()->setUserPreference($userLogin,
                UsersManagerAPI::PREFERENCE_DEFAULT_REPORT,
                $defaultReport);
            UsersManagerAPI::getInstance()->setUserPreference($userLogin,
                UsersManagerAPI::PREFERENCE_DEFAULT_REPORT_DATE,
                $defaultDate);
            $toReturn = $response->getResponse();
        } catch (Exception $e) {
            $toReturn = $response->getResponseException($e);
        }
        echo $toReturn;
    }
}
