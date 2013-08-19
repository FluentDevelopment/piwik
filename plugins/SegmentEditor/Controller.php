<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package SegmentEditor
 */
namespace Piwik\Plugins\SegmentEditor;

use Piwik\Piwik;
use Piwik\Common;
use Piwik\Plugins\SegmentEditor\API;
use Piwik\View;
use Piwik\Plugins\API\API as MetaAPI;

/**
 * @package SegmentEditor
 */
class Controller extends \Piwik\Controller
{

    public function getSelector()
    {
        $view = new View('@SegmentEditor/getSelector');
        $idSite = Common::getRequestVar('idSite');
        $this->setGeneralVariablesView($view);
        $segments = MetaAPI::getInstance()->getSegmentsMetadata($idSite);

        $segmentsByCategory = $customVariablesSegments = array();
        foreach ($segments as $segment) {
            if ($segment['category'] == Piwik_Translate('General_Visit')
                && $segment['type'] == 'metric'
            ) {
                $metricsLabel = Piwik_Translate('General_Metrics');
                $metricsLabel[0] = strtolower($metricsLabel[0]);
                $segment['category'] .= ' (' . $metricsLabel . ')';
            }
            $segmentsByCategory[$segment['category']][] = $segment;
        }
        uksort($segmentsByCategory, array($this, 'sortSegmentCategories'));

        $view->segmentsByCategory = $segmentsByCategory;

        $savedSegments = API::getInstance()->getAll($idSite);
        foreach ($savedSegments as &$savedSegment) {
            $savedSegment['name'] = Common::sanitizeInputValue($savedSegment['name']);
        }
        $view->savedSegmentsJson = Common::json_encode($savedSegments);
        $view->authorizedToCreateSegments = !Piwik::isUserIsAnonymous();

        $view->segmentTranslations = Common::json_encode($this->getTranslations());
        $out = $view->render();
        echo $out;
    }

    public function sortSegmentCategories($a, $b)
    {
        // Custom Variables last
        if ($a == Piwik_Translate('CustomVariables_CustomVariables')) {
            return 1;
        }
        return 0;
    }

    private function getTranslations()
    {
        $translationKeys = array(
            'General_OperationEquals',
            'General_OperationNotEquals',
            'General_OperationAtMost',
            'General_OperationAtLeast',
            'General_OperationLessThan',
            'General_OperationGreaterThan',
            'General_OperationContains',
            'General_OperationDoesNotContain',
            'General_OperationIs',
            'General_OperationIsNot',
            'General_OperationContains',
            'General_OperationDoesNotContain',
            'SegmentEditor_DefaultAllVisits',
            'General_DefaultAppended',
            'SegmentEditor_AddNewSegment',
            'General_Edit',
            'General_Search',
            'General_SearchNoResults',
            '',
            '',
            '',
        );
        foreach ($translationKeys as $key) {
            $translations[$key] = Piwik_Translate($key);
        }
        return $translations;
    }
}
