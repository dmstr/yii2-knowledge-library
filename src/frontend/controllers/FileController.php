<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\frontend\controllers;

use dmstr\knowledgeLibrary\files\FileService;
use dmstr\knowledgeLibrary\models\File;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Delivers the files of the versions valid today.
 *
 * The files are read from the file storage of the backend module.
 */
class FileController extends BaseController
{
    protected function verbs(): array
    {
        return array_merge(parent::verbs(), [
            'download' => ['GET'],
        ]);
    }

    /**
     * Sends a file of the version valid today of an active item as download
     * (`Content-Disposition: attachment`) with its original name, MIME type
     * and size.
     *
     * @throws NotFoundHttpException if the file does not exist, belongs to an
     * archived item or to a version that is not the one valid today, or the
     * stored file does not exist
     */
    public function actionDownload(string $id): Response
    {
        $file = File::find()
            ->andWhere([File::tableName() . '.[[id]]' => $id])
            ->with('version.item')
            ->one();
        if ($file === null || !$file->belongsToValidVersion()) {
            throw new NotFoundHttpException(Yii::t('knowledge-library', 'The requested file does not exist.'));
        }

        $response = (new FileService($this->module->getBackendModule()))->send($file, $this->response);
        if ($response === null) {
            throw new NotFoundHttpException(Yii::t('knowledge-library', 'The requested file does not exist.'));
        }

        return $response;
    }
}
