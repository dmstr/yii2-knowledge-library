<?php

/**
 * List of the topics, see `../master-data/_index.php`.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use dmstr\knowledgeLibrary\models\Topic;

$this->title = Yii::t('knowledge-library', 'Topics');
$this->context->setBreadcrumbs([$this->title]);

echo $this->render('../master-data/_index', [
    'dataProvider' => $dataProvider,
    'cssClass' => 'knowledge-library-topic-index',
    'createLabel' => Yii::t('knowledge-library', 'Create topic'),
    'emptyText' => Yii::t('knowledge-library', 'No topics yet. Create the first one with "Create topic".'),
    'confirm' => static fn (Topic $model) => Yii::t('knowledge-library', 'Delete topic "{name}"?', [
        'name' => $model->name,
    ]),
    'itemFilter' => static fn (Topic $model) => ['topicIds' => [$model->id], 'archived' => 'all'],
    'columns' => [],
]);
