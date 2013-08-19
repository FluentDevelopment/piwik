<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package LanguagesManager
 *
 */
namespace Piwik\Plugins\LanguagesManager;

use Piwik\Piwik;
use Piwik\Common;
use Piwik\Plugins\LanguagesManager\API;
use Piwik\Url;
use Piwik\Plugins\LanguagesManager\LanguagesManager;
use Zend_Registry;

/**
 * @package LanguagesManager
 */
class Controller extends \Piwik\Controller
{
    /**
     * anonymous = in the session
     * authenticated user = in the session and in DB
     */
    public function saveLanguage()
    {
        $language = Common::getRequestVar('language');

        // Prevent CSRF only when piwik is not installed yet (During install user can change language)
        if (Piwik::isInstalled()) {
            $this->checkTokenInUrl();
        }
        LanguagesManager::setLanguageForSession($language);
        if (Zend_Registry::isRegistered('access')) {
            $currentUser = Piwik::getCurrentUserLogin();
            if ($currentUser && $currentUser !== 'anonymous') {
                API::getInstance()->setLanguageForUser($currentUser, $language);
            }
        }
        Url::redirectToReferer();
    }
}
