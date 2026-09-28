# yii2-knowledge-library

A knowledge library for Yii2 applications. It consists of two modules:

- a **backend module** for managing knowledge items with versioned, time-valid content, review by a second person, relations between items and a change history
- a **frontend module** that renders the currently valid content server-side, e.g. for a RAG crawler

## Data model

The library stores knowledge **types** and **topics**, knowledge **items**, their content **versions** (each valid for a time range), **files** attached to versions, **relations** between items and a **history** of changes.

## Requirements

- PHP 8.1 or later
- Yii 2.0.45 or later
- [dmstr/yii2-rbac-migration](https://github.com/dmstr/yii2-rbac-migration) for the RBAC setup
- [dmstr/yii2-web](https://github.com/dmstr/yii2-web) for route-based access control
- An application component providing a flysystem-based file storage (default ID `fs`)

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
| `fileStorage` | `'fs'` | Name of the application component used as (flysystem-based) file storage for version files |
| `targetPath` | `'knowledge-library'` | Target directory inside the file storage |
| `userProvider` | `null` | Definition of a user provider object (class name, configuration array or object), resolved via `Yii::createObject()`; `null` uses the default provider |

### Frontend module properties

| Property | Default | Description |
| --- | --- | --- |
| `backendModuleId` | `'knowledge-library'` | ID of the backend module whose configuration (file storage, user provider) the frontend module shares |

## Migrations

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

Messages use the category `knowledge-library`. Both modules register a `PhpMessageSource` for `knowledge-library*` unless the application already configured that category.

Applications with a catch-all `*` message source (e.g. a `DbMessageSource`) must map `knowledge-library*` explicitly, otherwise the catch-all source takes precedence:

```php
'components' => [
    'i18n' => [
        'translations' => [
            'knowledge-library*' => [
                'class' => \yii\i18n\PhpMessageSource::class,
                'basePath' => '@dmstr/knowledgeLibrary/messages',
                'sourceLanguage' => 'en',
            ],
            '*' => [
                'class' => \yii\i18n\DbMessageSource::class,
                // ...
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

Access to the frontend module is granted by a permission named exactly like its module ID, i.e. `knowledge` for the configuration above.

## Running tests

```bash
composer install
vendor/bin/phpunit
```

## License

MIT, see [LICENSE](LICENSE).
