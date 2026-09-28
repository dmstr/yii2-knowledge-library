# yii2-knowledge-library

[![tests](https://github.com/dmstr/yii2-knowledge-library/actions/workflows/tests.yml/badge.svg)](https://github.com/dmstr/yii2-knowledge-library/actions/workflows/tests.yml)

A knowledge library for Yii2 applications. It consists of two modules:

- a **backend module** for managing knowledge items with versioned, time-valid content, review by a second person, relations between items and a change history
- a **frontend module** that renders the currently valid content server-side, e.g. for a RAG crawler

## Data model

The library stores knowledge **types** and **topics**, knowledge **items**, their content **versions** (each valid for a time range), **files** attached to versions, **relations** between items and a **history** of changes.

| Entity | Model | Purpose |
| --- | --- | --- |
| Type | `Type` | Kind of item; defines whether versions have a validity period (`has_validity_period`) and whether publishing requires a review (`requires_review`) |
| Topic | `Topic` | Keyword; an item can be assigned to several topics (`Item::$topicIds`) |
| Item | `Item` | The knowledge item with title, summary, source information and archive flag |
| Version | `Version` | Numbered content version of an item: Markdown text and/or files, validity dates, review and publication data |
| File | `File` | File of a version in the file storage, either a `main` file (carries the content) or an `attachment` |
| Relation | `Relation` | Directed relation between two items: `based_on`, `supplements` or `replaces` |
| History | `History` | Change log entry of an item, optionally for one version |

### Version statuses

- `draft`: being edited, at most one per item; may be empty
- `in_review`: submitted to a reviewer (`submitForReview()`), at most one per item
- `published`: released (`publish()`); a draft can be published directly only if its type does not require a review
- `withdrawn`: no longer valid

Versions in review or published need content: a non-empty text or at least one main file.

### Effective state

A published version has an effective state at a given date (`Version::getEffectiveState()`):

- `in_force`: valid at the date
- `upcoming`: starts after the date
- `historical`: ended before the date, or superseded

For types **with** a validity period a version is valid from `valid_from` until `valid_until` (open-ended if empty). A new version must start strictly after the latest published version; publishing it ends the predecessor the day before, unless the predecessor already ends earlier (gaps are allowed). Corrections (`corrects_version_id`) are exempt from this rule.

For types **without** a validity period versions have no dates; the published version with the highest number is in force, all older ones are historical.

`Version::find()->validAt($date)` returns the valid version of each item at a date, at most one per item; `Item::getValidVersion()` returns it for one item.

## Requirements

- PHP 8.1 or later
- Yii 2.0.45 or later
- [dmstr/yii2-rbac-migration](https://github.com/dmstr/yii2-rbac-migration) for the RBAC setup
- [dmstr/yii2-web](https://github.com/dmstr/yii2-web) for route-based access control
- [yiisoft/yii2-bootstrap](https://github.com/yiisoft/yii2-bootstrap) and [kartik-v/yii2-widget-select2](https://github.com/kartik-v/yii2-widget-select2) for the backend views
- [league/flysystem](https://flysystem.thephpleague.com/) 3 and an application component providing a flysystem filesystem as file storage (default ID `fs`), see [Files](#files)

The default user provider works with the identity and user UUIDs of [2amigos/yii2-usuario](https://github.com/2amigos/yii2-usuario).

## Installation

Install the package with Composer:

```bash
composer require dmstr/yii2-knowledge-library
```

To install directly from the repository, add it as a VCS repository first:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/dmstr/yii2-knowledge-library"
        }
    ]
}
```

## Configuration

Register both modules in the application configuration:

```php
return [
    'modules' => [
        'knowledge-library' => [
            'class' => \dmstr\knowledgeLibrary\Module::class,
            'fileStorage' => 'fs',
            'targetPath' => 'knowledge-library',
            'allowedExtensions' => ['pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
            'maxFileSize' => 20 * 1024 * 1024,
            'userProvider' => null,
        ],
        'knowledge' => [
            'class' => \dmstr\knowledgeLibrary\frontend\Module::class,
            'backendModuleId' => 'knowledge-library',
        ],
    ],
];
```

### Backend module properties

| Property | Default | Description |
| --- | --- | --- |
| `fileStorage` | `'fs'` | Name of the application component used as file storage for version files; the component must implement `League\Flysystem\FilesystemOperator` or provide one through `getFilesystem()` (e.g. `eluhr\flysystemRestApi\components\FileStorage` of eluhr/yii2-flysystem-rest-api), otherwise an `InvalidConfigException` is thrown |
| `targetPath` | `'knowledge-library'` | Target directory inside the file storage |
| `allowedExtensions` | `['pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp']` | File extensions allowed for uploaded version files (lower case, without dot); the MIME type detected from the content must match the extension |
| `maxFileSize` | `20971520` (20 MB) | Maximum size of an uploaded version file in bytes, checked against the actual file size; the PHP and web server upload limits must allow at least this size |
| `userProvider` | `null` | Definition of a user provider object (class name, configuration array or object), resolved via `Yii::createObject()`; `null` uses the default provider |

### Frontend module properties

| Property | Default | Description |
| --- | --- | --- |
| `backendModuleId` | `'knowledge-library'` | ID of the backend module whose configuration (file storage, user provider) the frontend module shares |

## Backend pages

The backend module provides the following controllers. Routes are relative to the module ID, e.g. `/knowledge-library/item/index` for the configuration above. The module URL itself (`/knowledge-library`) opens the item library (`defaultRoute` is `item`).

| Route | Purpose |
| --- | --- |
| `item/index` | Item library: list with filters, sorting and paging |
| `item/create` | Create an item (title and type) |
| `item/view` | Detail page of an item |
| `item/update` | Edit the master data of an item |
| `item/source` | Edit the source data of an item |
| `item/delete` | Delete an item with all its versions, files, relations and history (POST only) |
| `type/index` | List of types |
| `type/create` | Create a type |
| `type/update` | Edit a type |
| `type/delete` | Delete a type that is not used by any item (POST only) |
| `topic/index` | List of topics |
| `topic/create` | Create a topic |
| `topic/update` | Edit a topic |
| `topic/delete` | Delete a topic that is not used by any item (POST only) |
| `version/create` | Start the version wizard of an item: creates its draft or continues the existing one (POST only) |
| `version/update` | Step of the version wizard (`step` 1 to 4) |
| `version/publish` | Publish a draft of a type without review (POST only) |
| `version/discard` | Discard a draft with its files (POST only) |
| `file/download` | Download a file of a version |
| `relation/create` | Add a relation from an item to another (POST only) |
| `relation/delete` | Remove a relation (POST only) |

The views use `yii\bootstrap\ActiveForm` and `yii\grid\GridView` with Bootstrap 3 markup, and the Select2 widget of `kartik-v/yii2-widget-select2`. They set `$this->title` and the breadcrumbs (`$this->params['breadcrumbs']`) and are rendered in the layout configured for the module (`layout` property).

### Version wizard

New versions are created in a wizard that works on the draft of the item (at most one per item). A new draft takes over the text, the files and the details (title, summary, topics) of the latest published version. The wizard has four steps, each saved on its own; "Save as draft" returns to the detail page in every step:

1. **Content**: Markdown text, main files (`mainFiles[]`, several at once) and attachments (`attachments[<i>]` with the title `attachmentTitles[<i>]`). Files of the draft can be removed (`remove[<file-id>]=1`); files uploaded in the draft are highlighted. An attachment whose content is already attached to another item shows a hint with a link to create an item of its own from it; the hint does not block. The step is complete with a text or at least one main file, attachments alone do not count. Rejected uploads are shown with the reason and keep the wizard on the step.
2. **Validity**: Valid From and Valid Until for types with a validity period, with the consequences for the previous version and a preview of the timeline.
3. **Details**: title, topics and summary; they are applied to the item when the version is published.
4. **Check**: summary of the version and "Publish" for types without review.

### Delete log

Deleting an item is logged with `Yii::info()` in the category `knowledge-library` after the deletion, including the reference of the current user, the ID and title of the item, the number of deleted versions and file rows, and the number of stored files deleted, kept (still referenced) and failed. A stored file that cannot be deleted does not stop the deletion of the item; it is additionally logged with `Yii::warning()`. Info messages are usually not routed to a log target in production; to keep the entries, the application adds a target for the category:

```php
'components' => [
    'log' => [
        'targets' => [
            'knowledge-library' => [
                'class' => \yii\log\FileTarget::class,
                'levels' => ['info'],
                'categories' => ['knowledge-library*'],
                'logVars' => [],
            ],
        ],
    ],
],
```

## Files

Version files are stored in the flysystem filesystem of the component named by `fileStorage` under `<targetPath>/<item-id>/<file-id>.<ext>`, e.g. `knowledge-library/<item-uuid>/<file-uuid>.pdf`. The ID is the UUID of the file row, the extension comes from the original name; the original name (base name only) is kept in the file row together with MIME type, size, position and the SHA-256 hash of the content (`content_hash`). Uploads are read from their temporary file as stream, never loaded into memory as a whole.

Uploads are checked against `allowedExtensions` (also by the MIME type detected from the content) and `maxFileSize`; rejected files write neither a row nor a stored file. Uploading a main file records the upload at the item (`source_uploaded_at`, `source_uploaded_by`, the latest upload wins).

A new version takes over the files of its predecessor as new file rows pointing to the same stored file, the storage is not copied. A stored file is deleted only when no file row refers to it anymore: removing a taken-over file from a draft deletes the row only, removing a file uploaded in the draft deletes the stored file as well. Deleting an item deletes all its stored files.

The package works directly on the flysystem filesystem, so permission layers of a wrapper component do not apply. The files are not registered in a file manager (no `storage_item` rows of eluhr/yii2-flysystem-rest-api, `storage_item_id` stays empty); they do not appear in the file manager, and its download or stream routes do not deliver them. Files are delivered only through `file/download`, which checks the route permission of the package (`knowledge-library_file_download`) and sends the file as download (`Content-Disposition: attachment`) with its original name.

## Migrations

The package brings the following migrations:

| Migration | Purpose |
| --- | --- |
| `m260928_100000_knowledge_library_rbac` | Permissions and roles |
| `m260928_100100_knowledge_library_schema` | Tables of the data model |
| `m260928_185500_knowledge_library_routes` | Route permissions of items, types and topics |
| `m260928_203000_knowledge_library_versions` | Draft details of versions (`draft_title`, `draft_summary`, `draft_topic_ids`) and the content hash of files (`content_hash`) |
| `m260928_203100_knowledge_library_routes_2` | Route permissions of the version wizard, the file download and the relations |
| `i18n/m260928_100200_knowledge_library_translations` | Optional German translations, see [Translations](#translations) |

Add the migration path to the migrate controller of your console application:

```php
'controllerMap' => [
    'migrate' => [
        'class' => \yii\console\controllers\MigrateController::class,
        'migrationPath' => [
            '@vendor/dmstr/yii2-knowledge-library/src/migrations',
        ],
    ],
],
```

## Translations

Messages use the category `knowledge-library`. The package registers no message source of its own; the category is served by the message source the application configured for it.

phd5 applications map `'*'` to a `DbMessageSource` and need no further configuration. The German texts of the package are provided by an optional migration; add its path to the migrate controller next to the schema migrations:

```php
'controllerMap' => [
    'migrate' => [
        'class' => \yii\console\controllers\MigrateController::class,
        'migrationPath' => [
            '@vendor/dmstr/yii2-knowledge-library/src/migrations',
            '@vendor/dmstr/yii2-knowledge-library/src/migrations/i18n',
        ],
    ],
],
```

The migration writes its source messages and German translations into the tables of the `DbMessageSource` serving `knowledge-library` (`sourceMessageTable` and `messageTable`). Existing translations are never overwritten, so changes made by editors are kept, and running it again only adds missing rows. Languages missing in a `{{%language}}` table are skipped. `down` removes the migration's translations and those of its source messages that have no other translations left. If the category is not served by a `DbMessageSource`, the migration does nothing.

Applications without a database message source must configure a message source for `knowledge-library*` themselves; providing the translations is then up to the application:

```php
'components' => [
    'i18n' => [
        'translations' => [
            'knowledge-library*' => [
                'class' => \yii\i18n\DbMessageSource::class,
                'sourceLanguage' => 'en',
            ],
        ],
    ],
],
```

## Access control

Both modules use the route-based access control of `dmstr/yii2-web`.

The backend module defines the following permissions:

- `knowledge_library_editor`
- `knowledge_library_reviewer`
- `knowledge_library_admin`

They are grouped into roles that build on each other: `KnowledgeLibraryAdmin` contains `KnowledgeLibraryReviewer`, which contains `KnowledgeLibraryEditor`.

### Route permissions

Every action of the backend module is checked against a permission named `<module-id>_<controller>_<action>`, e.g. `knowledge-library_item_delete`. The migrations `m260928_185500_knowledge_library_routes` and `m260928_203100_knowledge_library_routes_2` create one permission per action and assign them to the roles:

| Permission | Role |
| --- | --- |
| `knowledge-library_item_index`, `knowledge-library_item_create`, `knowledge-library_item_view`, `knowledge-library_item_update`, `knowledge-library_item_source` | `KnowledgeLibraryEditor` |
| `knowledge-library_item_delete` | `KnowledgeLibraryAdmin` |
| `knowledge-library_type_index`, `knowledge-library_type_create`, `knowledge-library_type_update`, `knowledge-library_type_delete` | `KnowledgeLibraryAdmin` |
| `knowledge-library_topic_index`, `knowledge-library_topic_create`, `knowledge-library_topic_update`, `knowledge-library_topic_delete` | `KnowledgeLibraryAdmin` |
| `knowledge-library_version_create`, `knowledge-library_version_update`, `knowledge-library_version_publish`, `knowledge-library_version_discard` | `KnowledgeLibraryEditor` |
| `knowledge-library_file_download` | `KnowledgeLibraryEditor` |
| `knowledge-library_relation_create`, `knowledge-library_relation_delete` | `KnowledgeLibraryEditor` |

Reviewers and admins inherit the editor permissions through the role chain. The permission names assume the module ID `knowledge-library`; with another module ID the application creates the permissions itself.

Everyone who may open the detail page of an item may also download its files, including the files of drafts.

`dmstr\web\User` resolves route permissions by prefix: a permission `knowledge-library` grants every route of the module, `knowledge-library_item` every item action including `delete`. The package therefore creates no such permission; applications should not either unless they want to grant everything below it.

Access to the frontend module is granted by a permission named exactly like its module ID, i.e. `knowledge` for the configuration above.

## Running tests

```bash
composer install
vendor/bin/phpunit
```

The suite `unit` tests the models and migrations on an in-memory SQLite database. The suite `web` runs the backend pages through `Yii::$app->runAction()` in a web application (`tests/WebTestCase.php`) with the RBAC migrations of the package applied, so the route permissions are tested as well.

GitHub Actions runs both suites on PHP 8.1 to 8.4 for every push to `master` and every pull request (`.github/workflows/tests.yml`).

## License

MIT, see [LICENSE](LICENSE).
