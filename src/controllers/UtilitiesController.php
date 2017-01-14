<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\controllers;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\UtilityInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\tasks\FindAndReplace as FindAndReplaceTask;
use craft\utilities\ClearCaches;
use craft\web\Controller;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use ZipArchive;

class UtilitiesController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Index
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to any utilities
     */
    public function actionIndex(): Response
    {
        $utilities = Craft::$app->getUtilities()->getAuthorizedUtilityTypes();

        if (empty($utilities)) {
            throw new ForbiddenHttpException('User not permitted to view Utilities');
        }

        /** @var string|UtilityInterface $firstUtility */
        $firstUtility = reset($utilities);

        return $this->redirect('utilities/'.$firstUtility::id());
    }

    /**
     * Show a utility page.
     *
     * @param string $id
     *
     * @return string
     * @throws NotFoundHttpException if $id is invalid
     * @throws ForbiddenHttpException if the user doesn't have access to the requested utility
     * @throws Exception in case of failure
     */
    public function actionShowUtility(string $id): string
    {
        $utilitiesService = Craft::$app->getUtilities();

        if (($class = $utilitiesService->getUtilityTypeById($id)) === null) {
            throw new NotFoundHttpException('Invalid utility ID: '.$id);
        }

        /** @var UtilityInterface $class */
        if ($utilitiesService->checkAuthorization($class) === false) {
            throw new ForbiddenHttpException('User not permitted to access the "'.$class::displayName().'".');
        }

        Craft::$app->getView()->registerCssResource('css/utilities.css');

        return $this->renderTemplate('utilities/_index', [
            'id' => $id,
            'displayName' => $class::displayName(),
            'contentHtml' => $class::contentHtml(),
            'utilities' => $this->_utilityInfo(),
        ]);
    }

    /**
     * View stack trace for a deprecator log entry.
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to the Deprecation Errors utility
     */
    public function actionGetDeprecationErrorTracesModal(): Response
    {
        $this->requirePermission('utility:deprecation-errors');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $logId = Craft::$app->request->getRequiredParam('logId');
        $html = Craft::$app->getView()->renderTemplate('_components/utilities/DeprecationErrors/traces_modal', [
            'log' => Craft::$app->deprecator->getLogById($logId)
        ]);

        return $this->asJson([
            'html' => $html
        ]);
    }

    /**
     * Deletes all deprecation errors.
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to the Deprecation Errors utility
     */
    public function actionDeleteAllDeprecationErrors(): Response
    {
        $this->requirePermission('utility:deprecation-errors');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Craft::$app->deprecator->deleteAllLogs();

        return $this->asJson([
            'success' => true
        ]);
    }

    /**
     * Deletes a deprecation error.
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to the Deprecation Errors utility
     */
    public function actionDeleteDeprecationError(): Response
    {
        $this->requirePermission('utility:deprecation-errors');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $logId = Craft::$app->getRequest()->getRequiredBodyParam('logId');
        Craft::$app->deprecator->deleteLogById($logId);

        return $this->asJson([
            'success' => true
        ]);
    }

    /**
     * Performs an Asset Index action
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to the Asset Indexes utility
     */
    public function actionAssetIndexPerformAction(): Response
    {
        $this->requirePermission('utility:asset-indexes');

        $params = Craft::$app->getRequest()->getRequiredBodyParam('params');


        // Initial request
        if (!empty($params['start'])) {
            $batches = [];
            $sessionId = Craft::$app->getAssetIndexer()->getIndexingSessionId();

            // Selection of sources or all sources?
            if (is_array($params['sources'])) {
                $sourceIds = $params['sources'];
            } else {
                $sourceIds = Craft::$app->getVolumes()->getViewableVolumeIds();
            }

            $missingFolders = [];
            $skippedFiles = [];
            $grandTotal = 0;

            foreach ($sourceIds as $sourceId) {
                // Get the indexing list
                $indexList = Craft::$app->getAssetIndexer()->prepareIndexList($sessionId, $sourceId);

                if (!empty($indexList['error'])) {
                    return $this->asJson($indexList);
                }

                if (isset($indexList['missingFolders'])) {
                    $missingFolders += $indexList['missingFolders'];
                }

                if (isset($indexList['skippedFiles'])) {
                    $skippedFiles = $indexList['skippedFiles'];
                }

                $batch = [];

                for ($i = 0; $i < $indexList['total']; $i++) {
                    $batch[] = [
                        'params' => [
                            'sessionId' => $sessionId,
                            'sourceId' => $sourceId,
                            'total' => $indexList['total'],
                            'offset' => $i,
                            'process' => 1
                        ]
                    ];
                }

                $batches[] = $batch;
            }

            $batches[] = [
                [
                    'params' => [
                        'overview' => true,
                        'sessionId' => $sessionId,
                    ]
                ]
            ];

            Craft::$app->getSession()->set('assetsSourcesBeingIndexed', $sourceIds);
            Craft::$app->getSession()->set('assetsMissingFolders', $missingFolders);
            Craft::$app->getSession()->set('assetsSkippedFiles', $skippedFiles);

            return $this->asJson([
                'batches' => $batches,
                'total' => $grandTotal
            ]);
        } else if (!empty($params['process'])) {
            // Index the file
            Craft::$app->getAssetIndexer()->processIndexForVolume($params['sessionId'], $params['offset'], $params['sourceId']);

            return $this->asJson([
                'success' => true
            ]);
        } else if (!empty($params['overview'])) {
            $sourceIds = Craft::$app->getSession()->get('assetsSourcesBeingIndexed', []);
            $missingFiles = Craft::$app->getAssetIndexer()->getMissingFiles($sourceIds, $params['sessionId']);
            $missingFolders = Craft::$app->getSession()->get('assetsMissingFolders', []);
            $skippedFiles = Craft::$app->getSession()->get('assetsSkippedFiles', []);

            $responseArray = [];

            if (!empty($missingFiles) || !empty($missingFolders) || !empty($skippedFiles)) {
                $responseArray['confirm'] = Craft::$app->getView()->renderTemplate('assets/_missing_items',
                    [
                        'missingFiles' => $missingFiles,
                        'missingFolders' => $missingFolders,
                        'skippedFiles' => $skippedFiles
                    ]);
                $responseArray['params'] = ['finish' => 1];
            }

            // Clean up stale indexing data (all sessions that have all recordIds set)
            $sessionsInProgress = (new Query())
                ->select(['sessionId'])
                ->from(['{{%assetindexdata}}'])
                ->where(['recordId' => null])
                ->groupBy(['sessionId'])
                ->scalar();

            if (empty($sessionsInProgress)) {
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%assetindexdata}}')
                    ->execute();
            } else {
                Craft::$app->getDb()->createCommand()
                    ->delete(
                        '{{%assetindexdata}}',
                        ['not', ['sessionId' => $sessionsInProgress]])
                    ->execute();
            }

            return $this->asJson([
                'batches' => [
                    [
                        $responseArray
                    ]
                ]
            ]);
        } else if (!empty($params['finish'])) {
            if (!empty($params['deleteAsset']) && is_array($params['deleteAsset'])) {
                Craft::$app->getDb()->createCommand()
                    ->delete('assettransformindex', ['assetId' => $params['deleteAsset']])
                    ->execute();

                /** @var Asset[] $assets */
                $assets = Asset::find()
                    ->status(null)
                    ->enabledForSite(false)
                    ->id($params['deleteAsset'])
                    ->all();

                foreach ($assets as $asset) {
                    $asset->keepFileOnDelete = true;
                    Craft::$app->getElements()->deleteElement($asset);
                }
            }

            if (!empty($params['deleteFolder']) && is_array($params['deleteFolder'])) {
                Craft::$app->getAssets()->deleteFoldersByIds($params['deleteFolder'], false);
            }

            return $this->asJson([
                'finished' => 1
            ]);
        }

        return $this->asJson([]);
    }

    /**
     * Performs a Clear Caches action
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to the Clear Caches utility
     */
    public function actionClearCachesPerformAction(): Response
    {
        $this->requirePermission('utility:clear-caches');

        $params = Craft::$app->getRequest()->getRequiredBodyParam('params');

        if (!isset($params['caches'])) {
            return $this->asJson([
                'success' => true
            ]);
        }

        foreach (ClearCaches::cacheOptions() as $cacheOption) {
            if (is_array($params['caches']) && !in_array($cacheOption['key'], $params['caches'], true)) {
                continue;
            }

            $action = $cacheOption['action'];

            if (is_string($action)) {
                try {
                    FileHelper::clearDirectory($action);
                } catch (\Exception $e) {
                    Craft::warning("Could not clear the directory {$action}: ".$e->getMessage());
                }
            } else if (isset($cacheOption['params'])) {
                call_user_func_array($action, $cacheOption['params']);
            } else {
                $action();
            }
        }

        return $this->asJson([
            'success' => true
        ]);
    }

    /**
     * Performs a DB Backup action
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to the DB Backup utility
     * @throws Exception if the backup could not be created
     */
    public function actionDbBackupPerformAction(): Response
    {
        $this->requirePermission('utility:db-backup');

        $params = Craft::$app->getRequest()->getRequiredBodyParam('params');

        try {
            $backupPath = Craft::$app->getDb()->backup();
        } catch (\Exception $e) {
            throw new Exception('Could not create backup: '.$e->getMessage());
        }

        if (!is_file($backupPath)) {
            throw new Exception("Could not create backup: the backup file doesn't exist.");
        }

        if (empty($params['downloadBackup'])) {
            return null;
        }

        $zipPath = Craft::$app->getPath()->getTempPath().DIRECTORY_SEPARATOR.pathinfo($backupPath, PATHINFO_FILENAME).'.zip';

        if (is_file($zipPath)) {
            try {
                FileHelper::removeFile($zipPath);
            } catch (ErrorException $e) {
                Craft::warning("Unable to delete the file \"{$zipPath}\": ".$e->getMessage());
            }
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new Exception('Cannot create zip at '.$zipPath);
        }

        $filename = pathinfo($backupPath, PATHINFO_BASENAME);
        $zip->addFile($backupPath, $filename);
        $zip->close();

        return $this->asJson([
            'backupFile' => pathinfo($filename, PATHINFO_FILENAME)
        ]);
    }

    /**
     * Returns a database backup zip file to the browser.
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to the DB Backup utility
     * @throws NotFoundHttpException if the requested backup cannot be found
     */
    public function actionDownloadBackupFile(): Response
    {
        $this->requirePermission('utility:db-backup');

        $filename = Craft::$app->getRequest()->getRequiredQueryParam('filename');
        $filePath = Craft::$app->getPath()->getTempPath().DIRECTORY_SEPARATOR.$filename.'.zip';

        if (!is_file($filePath)) {
            throw new NotFoundHttpException(Craft::t('app', 'Invalid backup name: {filename}', [
                'filename' => $filename
            ]));
        }

        return Craft::$app->getResponse()->sendFile($filePath);
    }

    /**
     * Performs a Find And Replace action
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to the Find an Replace utility
     */
    public function actionFindAndReplacePerformAction(): Response
    {
        $this->requirePermission('utility:find-replace');

        $params = Craft::$app->getRequest()->getRequiredBodyParam('params');

        if (!empty($params['find']) && !empty($params['replace'])) {
            Craft::$app->getTasks()->queueTask([
                'type' => FindAndReplaceTask::class,
                'find' => $params['find'],
                'replace' => $params['replace']
            ]);
        }

        return $this->asJson([
            'success' => true
        ]);
    }

    /**
     * Performs a Search Index action
     *
     * @return Response
     * @throws ForbiddenHttpException if the user doesn't have access to the Search Indexes utility
     */
    public function actionSearchIndexPerformAction(): Response
    {
        $this->requirePermission('utility:search-indexes');

        $params = Craft::$app->getRequest()->getRequiredBodyParam('params');

        if (!empty($params['start'])) {
            // Truncate the searchindex table
            Craft::$app->getDb()->createCommand()
                ->truncateTable('{{%searchindex}}')
                ->execute();

            // Get all the element IDs ever
            $elements = (new Query())
                ->select(['id', 'type'])
                ->from(['{{%elements}}'])
                ->all();

            $batch = [];

            foreach ($elements as $element) {
                $batch[] = ['params' => $element];
            }

            return $this->asJson([
                'batches' => [$batch]
            ]);
        } else {
            /** @var ElementInterface $class */
            $class = $params['type'];

            if ($class::isLocalized()) {
                $siteIds = Craft::$app->getSites()->getAllSiteIds();
            } else {
                $siteIds = [Craft::$app->getSites()->getPrimarySite()->id];
            }

            $query = $class::find()
                ->id($params['id'])
                ->status(null)
                ->enabledForSite(false);

            foreach ($siteIds as $siteId) {
                $query->siteId($siteId);
                $element = $query->one();

                if ($element) {
                    /** @var Element $element */
                    Craft::$app->getSearch()->indexElementAttributes($element);

                    if ($class::hasContent()) {
                        $fieldLayout = $element->getFieldLayout();
                        $keywords = [];

                        foreach ($fieldLayout->getFields() as $field) {
                            /** @var Field $field */
                            // Set the keywords for the content's site
                            $fieldValue = $element->getFieldValue($field->handle);
                            $fieldSearchKeywords = $field->getSearchKeywords($fieldValue, $element);
                            $keywords[$field->id] = $fieldSearchKeywords;
                        }

                        Craft::$app->getSearch()->indexElementFields($element->id, $siteId, $keywords);
                    }
                }
            }
        }

        return $this->asJson([
            'success' => true
        ]);
    }

    // Public Methods
    // =========================================================================

    /**
     * Returns info about all of the utilities.
     *
     * @return array
     * @throws Exception in case of failure
     */
    private function _utilityInfo()
    {
        $info = [];

        foreach (Craft::$app->getUtilities()->getAuthorizedUtilityTypes() as $class) {
            /** @var UtilityInterface $class */
            $iconPath = $class::iconPath();

            if (!is_file($iconPath)) {
                throw new Exception("Utility icon file doesn't exist: {$iconPath}");
            }

            if (FileHelper::getMimeType($iconPath) !== 'image/svg+xml') {
                throw new Exception("Utility icon file is not an SVG: {$iconPath}");
            }

            $iconSvg = file_get_contents($iconPath);

            $info[] = [
                'id' => $class::id(),
                'iconSvg' => $iconSvg,
                'displayName' => $class::displayName(),
                'iconPath' => $class::iconPath(),
                'badgeCount' => $class::badgeCount(),
            ];
        }

        return $info;
    }
}
