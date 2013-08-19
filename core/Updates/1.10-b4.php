<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Updates
 */
use Piwik\Updates;

/**
 * @package Updates
 */
class Piwik_Updates_1_10_b4 extends Updates
{
    static function isMajorUpdate()
    {
        return false;
    }

    static function update()
    {
        try {
            \Piwik\PluginsManager::getInstance()->activatePlugin('MobileMessaging');
        } catch (Exception $e) {
            // pass
        }
    }
}