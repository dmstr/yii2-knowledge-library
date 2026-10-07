# yii2-knowledge-library

[![tests](https://github.com/dmstr/yii2-knowledge-library/actions/workflows/tests.yml/badge.svg)](https://github.com/dmstr/yii2-knowledge-library/actions/workflows/tests.yml)

A knowledge library for Yii2 applications. It consists of three modules:

- a **backend module** for managing knowledge items with versioned, time-valid content, review by a second person, relations between items and a change history
- a **frontend module** that renders the currently valid content server-side, e.g. for a RAG crawler
- an **MCP module**, a stateless [Model Context Protocol](https://modelcontextprotocol.io/) server giving AI clients read access to the currently valid content

## Data model

The library stores knowledge **types** and **topics**, knowledge **items**, their content **versions** (each valid for a time range), **files** attached to versions, **relations** between items and a **history** of changes.

| Entity | Model | Purpose |
| --- | --- | --- |
| Type | `Type` | Kind of item; defines whether versions have a validity period (`has_validity_period`, locked once items of the type have versions) and whether publishing requires a review (`requires_review`) |
| Topic | `Topic` | Keyword; an item can be assigned to several topics (`Item::$topicIds`) |
| Item | `Item` | The knowledge item with title, summary, source information and archive flag |
| Version | `Version` | Numbered content version of an item: Markdown text and/or files, validity dates, review and publication data |
| File | `File` | File of a version in the file storage, either a `main` file (carries the content) or an `attachment`, with an optional title shown instead of the file name |
| Relation | `Relation` | Directed relation between two items: `based_on`, `supplements` or `replaces` |
| History | `History` | Change log entry of an item, optionally for one version |

### Version statuses

- `draft`: being edited, at most one per item; may be empty. No draft can be created while a version of the item is in review or the item is archived
- `in_review`: submitted to a reviewer (`submitForReview()`), at most one per item; the reviewer approves (`approve()`) or returns it as draft (`returnToDraft()`)
- `published`: released (`publish()`, `approve()`); a draft can be published directly only if its type does not require a review
- `withdrawn`: no longer valid, withdrawn (`withdraw()`) or replaced by a correction

Versions in review or published need content: a non-empty text or at least one main file.

### Effective state

A published version has an effective state at a given date (`Version::getEffectiveState()`):

- `in_force`: valid at the date
- `upcoming`: starts after the date
- `historical`: ended before the date, or superseded

For types **with** a validity period a version is valid from `valid_from` until `valid_until` (open-ended if empty). A new version must start strictly after the latest published version; publishing it ends the predecessor the day before, unless the predecessor already ends earlier (gaps are allowed). Corrections (`corrects_version_id`) are exempt from this rule, they keep the period of the corrected version (see [Withdrawal and corrections](#withdrawal-and-corrections)).

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

Register the modules in the application configuration:

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
        'knowledge-mcp' => [
            'class' => \dmstr\knowledgeLibrary\mcp\Module::class,
            'backendModuleId' => 'knowledge-library',
            'authenticator' => ['class' => \yii\filters\auth\HttpBearerAuth::class],
        ],
    ],
];
```

The MCP module is optional; leave it out if the application has no AI clients.

### Backend module properties

| Property | Default | Description |
| --- | --- | --- |
| `fileStorage` | `'fs'` | Name of the application component used as file storage for version files; the component must implement `League\Flysystem\FilesystemOperator` or provide one through `getFilesystem()` (e.g. `eluhr\flysystemRestApi\components\FileStorage` of eluhr/yii2-flysystem-rest-api), otherwise an `InvalidConfigException` is thrown |
| `targetPath` | `'knowledge-library'` | Target directory inside the file storage |
| `allowedExtensions` | `['pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp']` | File extensions allowed for uploaded version files (lower case, without dot); the MIME type detected from the content must match the extension |
| `maxFileSize` | `20971520` (20 MB) | Maximum size of an uploaded version file in bytes, checked against the actual file size; the PHP and web server upload limits must allow at least this size |
| `inlineMimeTypes` | `['application/pdf']` | MIME types that `file/download` sends for display in the browser (`Content-Disposition: inline`); all other files are sent as download (`attachment`). Empty to download everything |
| `userProvider` | `null` | Definition of a user provider object (class name, configuration array or object), resolved via `Yii::createObject()`; `null` uses the default provider |

### Frontend module properties

| Property | Default | Description |
| --- | --- | --- |
| `backendModuleId` | `'knowledge-library'` | ID of the backend module whose configuration (file storage, user provider) the frontend module shares |

### MCP module properties

| Property | Default | Description |
| --- | --- | --- |
| `backendModuleId` | `'knowledge-library'` | ID of the backend module whose file storage the module reads the files from |
| `authenticator` | `['class' => HttpBearerAuth::class]` | Configuration of the authentication filter (`yii\filters\auth\AuthMethod`) that logs the client in before the access check, e.g. `HttpBearerAuth`, `bizley\jwt\JwtHttpBearerAuth`, the package's `StaticTokenAuth` or a `CompositeAuth` of several; `null` attaches none, so only clients with a session of the application get access |
| `serverName`, `serverVersion` | `'Knowledge Library'`, `'1.0.0'` | Server information announced to the client on `initialize` |
| `instructions` | `null` | Instructions for the model announced on `initialize`; `null` uses the default text describing the tools |
| `maxInlineFileSize` | `5242880` (5 MB) | Largest file in bytes that `knowledge_get_file` returns inline; larger files are answered with their download URL |
| `tools` | the five tools below | Tool classes (or configuration arrays) of the server, each created with the module as constructor argument; override to add or remove tools |

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
| `item/archive` | Archive an item, body `reason` optional (POST only) |
| `item/restore` | Restore an archived item, body `reason` optional (POST only) |
| `type/index` | List of types |
| `type/create` | Create a type |
| `type/update` | Edit a type; the validity period cannot be changed once items of the type have versions |
| `type/delete` | Delete a type that is not used by any item (POST only) |
| `topic/index` | List of topics |
| `topic/create` | Create a topic |
| `topic/update` | Edit a topic |
| `topic/delete` | Delete a topic that is not used by any item (POST only) |
| `version/create` | Start the version wizard of an item: creates its draft or continues the existing one (POST only) |
| `version/update` | Step of the version wizard (`step` 1 to 4) |
| `version/publish` | Publish a draft of a type without review (POST only) |
| `version/discard` | Discard a draft with its files (POST only) |
| `version/review` | Review page of a version in review, for its reviewer |
| `version/approve` | Approve and publish a version in review, body `note` optional (POST only) |
| `version/return` | Return a version in review to its submitter, body `note` required (POST only) |
| `version/reviewer` | Change the reviewer of a version in review, body `reviewer` and `reason` optional |
| `version/withdraw` | Withdraw a published version, body `reason` and `successor` (`previous`, `correction` or `none`) |
| `version/correct` | Start the correction of a published version, body `reason` optional (POST only) |
| `file/download` | Download a file of a version |
| `relation/create` | Add a relation from an item to another (POST only) |
| `relation/delete` | Remove a relation (POST only) |

The views use `yii\bootstrap\ActiveForm` and `yii\grid\GridView` with Bootstrap 3 markup, and the Select2 widget of `kartik-v/yii2-widget-select2`. They set `$this->title` and the breadcrumbs (`$this->params['breadcrumbs']`) and are rendered in the layout configured for the module (`layout` property).

### Version wizard

New versions are created in a wizard that works on the draft of the item (at most one per item). A new draft takes over the text, the files and the details (title, summary, topics) of the latest published version. The wizard has four steps, each saved on its own; "Save as draft" returns to the detail page in every step and writes the history entry `draft_saved`:

1. **Content**: Markdown text, main files (`mainFiles[<i>]` with the title `mainFileTitles[<i>]`) and attachments (`attachments[<i>]` with the title `attachmentTitles[<i>]`); further upload rows are added with a button. Files of the draft can be removed (`remove[<file-id>]=1`) and retitled (`titles[<file-id>]`, empty removes the title; titles longer than 255 characters are rejected like uploads); files uploaded in the draft are highlighted. Retitling a file taken over from the predecessor changes the row of the draft only. An attachment whose content is already attached to another item shows a hint with a link to create an item of its own from it; the hint does not block. The step is complete with a text or at least one main file, attachments alone do not count. Rejected uploads are shown with the reason and keep the wizard on the step.
2. **Validity**: Valid From and Valid Until for types with a validity period, with the consequences for the previous version and a preview of the timeline. A correction shows the period of the corrected version read-only.
3. **Details**: title, topics and summary; they are applied to the item when the version is published.
4. **Check**: summary of the version and "Publish" for types without review; for types with review the reviewing person (`reviewer`), an optional message (`message`) and "Submit for approval" (`submit-for-review`).

The wizard of a correction is titled "Correct version n" with the number of the corrected version. Drafts of archived items cannot be continued, only discarded.

### Review workflow

Versions of a type with `requires_review` are published by a second person. The editor submits the draft in step 4 of the wizard to a reviewing person, optionally with a message; the version is then `in_review`, and no other draft can be created for the item until the review ends. The detail page shows who reviews the version; the list offers the filter "Awaiting my approval" (`ItemSearch[review]=mine`) with the number of items waiting for the current user.

The reviewer opens the review page (`version/review`) with the message, validity, consequences, topics, text and files of the version and either

- approves it (`version/approve`): the version is published like a direct publication, the note is kept as reason of the history entry, or
- returns it (`version/return`) with a required note: the version becomes a draft again, the note and the reviewer are shown on the detail page and in the wizard, and submitting again clears them.

Only the chosen reviewer may approve or return a version, and nobody can submit a version to themselves (four-eyes principle, checked in `Version`, not only in the forms). Admins can hand a review over to another person (`version/reviewer`); the submitter and the current reviewer cannot be chosen.

The reviewing persons come from `UserProviderInterface::getReviewerOptions()`. The default provider offers the users with a direct assignment of the role `KnowledgeLibraryReviewer` or `KnowledgeLibraryAdmin` (all users if the application has no `authManager`). Root users of `dmstr\web\User` without a role assignment are therefore not offered as reviewers, and as the model checks the reviewer, they cannot approve a review of someone else either.

### Withdrawal and corrections

A published version in force or upcoming can be withdrawn on the page `version/withdraw` with a required reason. The page asks what applies instead in the period of the version:

- **Previous version remains valid** (`previous`): the published version with the latest Valid From before the withdrawn one takes over its Valid Until (open-ended if empty). Not available without previous version.
- **Corrected version** (`correction`): continues to the correction below; the version stays published until the correction is published.
- **Nothing applies** (`none`): the item has no valid version in that period.

For types without a validity period the published version with the highest number is valid anyway: "previous version remains valid" only documents that, and "nothing applies" is available only if there is no previous version.

"Correct" (`version/correct`) creates the draft of a correction of any published version and opens the wizard. The correction takes over text, files and validity period of the faulty version; its period cannot be changed. Publishing the correction (directly or through the review) withdraws the faulty version, other versions are not changed. For types without a validity period only the version in force can be corrected. A correction is not possible while the item has a draft or a version in review, or when it is archived. The reason given on the withdrawal page is kept with the faulty version until the correction is published and cleared when its draft is discarded. The confirmation of "Correct" names the period and, for a period in the past, the years whose answers change.

### Archive

Admins archive and restore items (`item/archive`, `item/restore`). Archived items are hidden in the list by default and marked "Archived". No new versions can be created, corrected, continued, approved or published for them: the routes redirect to the detail page with the message "The knowledge object is archived.". Returning a review, withdrawing a version, discarding a draft, master data, source and relations remain possible. Open drafts and reviews are kept when an item is archived.

### History

Every change of an item is logged in the table `history` and shown in the tab "History" of the detail page (when, who, what, reason; newest first). Version transitions (submit, hand over, return, approve, publish, withdraw, correct) are logged by `Version` in the same transaction, archiving and restoring by `Item`, the other entries (item created, master data and source changed, draft saved and discarded, relations added and removed) by the controllers. Structured facts such as the version number, the reviewer or the successor of a withdrawal are kept as JSON in `history.details`, so an entry can still be described after its version was deleted (`History::describe()`). Deleting an item deletes its history.

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

## Frontend pages

The frontend module shows the content valid today to readers, e.g. as entry point for a crawler that indexes the knowledge items. It is read-only and has no search, no navigation and no view of another date. Routes are relative to the module ID, e.g. `/knowledge/item/index` for the configuration above; the module URL itself (`/knowledge`) opens the list (`defaultRoute` is `item`).

| Route | Purpose |
| --- | --- |
| `item/index` | List of the valid items: title as link to the detail page, type and topics, sorted by title and ID, without paging |
| `item/view` | Detail page of a valid item (`id`) |
| `file/download` | Download a file (`id`) of the version valid today |

All three routes accept GET only; other methods are answered with 405.

**Visibility.** An item is shown if it is not archived and has a version valid today (`ItemQuery::validAt()`, see "Effective state"): a published version whose validity period contains today, or for types without validity period the published version with the highest number. Drafts, versions in review, historical, upcoming and withdrawn versions are never shown. Today is `date('Y-m-d')` in the time zone of the application. Nothing is cached: publishing, withdrawing, correcting, archiving or restoring changes the pages with the next request.

**Detail page.** The page shows title, type, topics, validity ("Valid from <from> until <until>", "open-ended" without end; "Valid since <publication date>" for types without validity period), source, source reference and source URL (as link), the summary, the text of the valid version rendered as Markdown (`MarkdownHelper::render()`: HTML in the text is shown as text, links and images keep only safe URL schemes) its main files and attachments (title as link text with the file name next to it, the file name as link text without title) with size and download link, and the related items (outgoing and incoming relations, labelled as seen from the item and grouped in the order of the relation types, the items sorted by title). Relations to items the frontend does not show (archived or without a version valid today) are left out. Empty fields and empty sections are left out. The markup is plain semantic HTML without JavaScript: the list is `<ul class="knowledge-items">`, the detail page `<article class="knowledge-item" data-item-id="...">` with a `<dl>` of the fields and the sections `knowledge-summary`, `knowledge-content`, `knowledge-files` and `knowledge-relations` (a `<dl class="knowledge-relations-list">` with the label as `<dt>` and one `<dd>` link per related item). The views set `$this->title` and the breadcrumbs and are rendered in the layout configured for the module.

**Not found.** Unknown IDs, archived items and items without a version valid today all answer with 404 ("The requested knowledge object does not exist."), without telling the cases apart. The list without valid items answers with 200 and a hint.

**Downloads.** `file/download` delivers only files of the version valid today of an item that is not archived, with the original name: PDFs (the `inlineMimeTypes` of the backend module) for display in the browser (`Content-Disposition: inline`), all other files as download (`attachment`). Files of any other version (draft, in review, historical, upcoming, withdrawn) and files of archived items answer with 404 ("The requested file does not exist."), as do unknown IDs and missing stored files. The files are read from the file storage of the backend module (`backendModuleId`).

## MCP server

The MCP module gives AI clients (e.g. a RAG service, Claude or any other MCP client) read access to the knowledge items valid today, with exactly the visibility rules of the frontend pages: active items with a version valid today, their files and their relations to such items; drafts, versions in review, withdrawn, historical and upcoming versions and archived items do not exist for the server. The module URL is the endpoint, e.g. `https://example.org/knowledge-mcp` for the configuration above (route `default/index`); `file/download` delivers the files of the valid versions like the frontend download, so a client can fetch a file with the same credentials.

**Transport.** The server speaks JSON-RPC 2.0 over the Streamable HTTP transport, revisions 2025-03-26, 2025-06-18 and 2025-11-25, and is **stateless**: every POST is handled on its own, no `Mcp-Session-Id` is issued and none is expected, so clients keep working across deployments and restarts of the application. There is no server-initiated stream and no session to end, so GET and DELETE on the endpoint answer with 405. Only the `tools` capability is announced; `initialize`, `ping`, `tools/list` and `tools/call` are served, notifications and client responses are accepted and ignored (202), and other methods are answered with "method not found". A body that is no JSON-RPC message is answered with 400 and a JSON-RPC error.

**Tools.** All tools are read-only (`readOnlyHint`) and return their data as `structuredContent` and, for clients without support for it, as JSON text:

| Tool | Arguments | Result |
| --- | --- | --- |
| `knowledge_search` | `query` (substring of title, summary or text of the valid version, case-insensitive), `type_id`, `topic_id`, `limit` (1-100, default 20), `offset` | `total` and one page of `items` sorted by title, each with `id`, `title`, `type`, `topics`, `summary`, `valid_from`, `valid_until` (null for types without validity period), `published_at`, `version`, `updated_at` |
| `knowledge_get_item` | `id` | The item as above plus `source` (`name`, `reference`, `url`), `content` (Markdown source of the valid version), `files` (`id`, `kind`, `title`, `name`, `mime_type`, `size`, `download_url`) and `relations` (`relation` label as seen from the item, `id`, `title`; only to items the server shows) |
| `knowledge_get_file` | `id` | The file as embedded resource: `text/*` files as text, others as base64 `blob` with their MIME type, the download URL as `uri`; `structuredContent` carries the file data and the item. Files larger than `maxInlineFileSize` are a tool error naming the download URL |
| `knowledge_list_types` | – | `types` with `id`, `name`, `has_validity_period`, `requires_review` and `item_count` (items valid today) |
| `knowledge_list_topics` | – | `topics` with `id`, `name` and `item_count` (items valid today) |

An unknown tool and invalid arguments are JSON-RPC errors (`-32602`); a call with valid arguments that cannot be answered (unknown or invalid item, missing file) is a tool result with `isError`, so the client can show the message to the model.

**Authentication.** Every request carries its own credentials; the module disables the session of the user component for its requests, so no session is created or written. The `authenticator` filter is attached to the module before the route-based access check and must log the client in; a request it does not authenticate is answered with 401 and a `WWW-Authenticate: Bearer` challenge. The default `yii\filters\auth\HttpBearerAuth` resolves the token through `findIdentityByAccessToken()` of the identity class. `dmstr\knowledgeLibrary\mcp\auth\StaticTokenAuth` accepts configured tokens, e.g. a service token from the environment for a technical user, and leaves other requests to the next filter of a `CompositeAuth`:

```php
'knowledge-mcp' => [
    'class' => \dmstr\knowledgeLibrary\mcp\Module::class,
    'authenticator' => [
        'class' => \yii\filters\auth\CompositeAuth::class,
        'authMethods' => [
            [
                'class' => \dmstr\knowledgeLibrary\mcp\auth\StaticTokenAuth::class,
                'tokens' => [getenv('KNOWLEDGE_MCP_TOKEN')],
                'identity' => static fn () => User::findOne(['username' => getenv('KNOWLEDGE_MCP_USER')]),
            ],
            ['class' => \bizley\jwt\JwtHttpBearerAuth::class, 'throwException' => false],
        ],
    ],
],
```

Empty tokens never match, so an unset environment variable does not open the endpoint. The authenticated identity still needs the permission of the module, see [Access control](#access-control).

**Smoke test.** An `initialize` request must answer without `Mcp-Session-Id` header, and a `tools/list` request with an invented session ID must answer normally:

```bash
curl --silent --show-error --dump-header - \
  --header 'Authorization: Bearer <token>' \
  --header 'Content-Type: application/json' \
  --header 'Accept: application/json, text/event-stream' \
  --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' \
  https://example.org/knowledge-mcp
```

## Files

Version files are stored in the flysystem filesystem of the component named by `fileStorage` under `<targetPath>/<item-id>/<file-id>.<ext>`, e.g. `knowledge-library/<item-uuid>/<file-uuid>.pdf`. The ID is the UUID of the file row, the extension comes from the original name; the original name (base name only) is kept in the file row together with MIME type, size, position and the SHA-256 hash of the content (`content_hash`). Uploads are read from their temporary file as stream, never loaded into memory as a whole.

Uploads are checked against `allowedExtensions` (also by the MIME type detected from the content) and `maxFileSize`; rejected files write neither a row nor a stored file. Uploading a main file records the upload at the item (`source_uploaded_at`, `source_uploaded_by`, the latest upload wins).

A new version takes over the files of its predecessor as new file rows pointing to the same stored file, the storage is not copied. A stored file is deleted only when no file row refers to it anymore: removing a taken-over file from a draft deletes the row only, removing a file uploaded in the draft deletes the stored file as well. Deleting an item deletes all its stored files.

The package works directly on the flysystem filesystem, so permission layers of a wrapper component do not apply. The files are not registered in a file manager (no `storage_item` rows of eluhr/yii2-flysystem-rest-api, `storage_item_id` stays empty); they do not appear in the file manager, and its download or stream routes do not deliver them. Files are delivered only through `file/download`, which checks the route permission of the package (`knowledge-library_file_download`) and sends the file with its original name and `X-Content-Type-Options: nosniff`, PDFs (`inlineMimeTypes`) for display in the browser (`Content-Disposition: inline`) and all other files as download (`attachment`). The frontend module delivers the files of the versions valid today through its own `file/download`, see "Frontend pages".

## Migrations

The package brings the following migrations:

| Migration | Purpose |
| --- | --- |
| `m260928_100000_knowledge_library_rbac` | Permissions and roles |
| `m260928_100100_knowledge_library_schema` | Tables of the data model |
| `m260928_185500_knowledge_library_routes` | Route permissions of items, types and topics |
| `m260928_203000_knowledge_library_versions` | Draft details of versions (`draft_title`, `draft_summary`, `draft_topic_ids`) and the content hash of files (`content_hash`) |
| `m260928_203100_knowledge_library_routes_2` | Route permissions of the version wizard, the file download and the relations |
| `m260928_223000_knowledge_library_history_details` | Structured details of history entries (`history.details`) |
| `m260928_223100_knowledge_library_routes_3` | Route permissions of review, withdrawal, correction and archive |
| `m261007_100000_knowledge_library_mcp_rbac` | Permission `knowledge-mcp` of the MCP module |
| `i18n/m260928_100200_knowledge_library_translations` | Optional German translations, see [Translations](#translations) |
| `i18n/m260929_120000_knowledge_library_translations_2` | Optional German translations of the titles of main documents |
| `i18n/m261007_120000_knowledge_library_translations_3` | Optional German translation of the validity period lock of types |

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

phd5 applications map `'*'` to a `DbMessageSource` and need no further configuration. The German texts of the package are provided by optional migrations (`src/migrations/i18n`, one per release that adds texts); add their path to the migrate controller next to the schema migrations:

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

Each migration writes its source messages and German translations into the tables of the `DbMessageSource` serving `knowledge-library` (`sourceMessageTable` and `messageTable`). Existing translations are never overwritten, so changes made by editors are kept, and running it again only adds missing rows. Languages missing in a `{{%language}}` table are skipped. `down` removes the migration's translations and those of its source messages that have no other translations left. If the category is not served by a `DbMessageSource`, the migration does nothing.

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

All modules use the route-based access control of `dmstr/yii2-web`.

The backend module defines the following permissions:

- `knowledge_library_editor`
- `knowledge_library_reviewer`
- `knowledge_library_admin`

They are grouped into roles that build on each other: `KnowledgeLibraryAdmin` contains `KnowledgeLibraryReviewer`, which contains `KnowledgeLibraryEditor`.

### Route permissions

Every action of the backend module is checked against a permission named `<module-id>_<controller>_<action>`, e.g. `knowledge-library_item_delete`. The migrations `m260928_185500_knowledge_library_routes`, `m260928_203100_knowledge_library_routes_2` and `m260928_223100_knowledge_library_routes_3` create one permission per action and assign them to the roles:

| Permission | Role |
| --- | --- |
| `knowledge-library_item_index`, `knowledge-library_item_create`, `knowledge-library_item_view`, `knowledge-library_item_update`, `knowledge-library_item_source` | `KnowledgeLibraryEditor` |
| `knowledge-library_item_delete`, `knowledge-library_item_archive`, `knowledge-library_item_restore` | `KnowledgeLibraryAdmin` |
| `knowledge-library_type_index`, `knowledge-library_type_create`, `knowledge-library_type_update`, `knowledge-library_type_delete` | `KnowledgeLibraryAdmin` |
| `knowledge-library_topic_index`, `knowledge-library_topic_create`, `knowledge-library_topic_update`, `knowledge-library_topic_delete` | `KnowledgeLibraryAdmin` |
| `knowledge-library_version_create`, `knowledge-library_version_update`, `knowledge-library_version_publish`, `knowledge-library_version_discard` | `KnowledgeLibraryEditor` |
| `knowledge-library_version_withdraw`, `knowledge-library_version_correct` | `KnowledgeLibraryEditor` |
| `knowledge-library_version_review`, `knowledge-library_version_approve`, `knowledge-library_version_return` | `KnowledgeLibraryReviewer` |
| `knowledge-library_version_reviewer` | `KnowledgeLibraryAdmin` |
| `knowledge-library_file_download` | `KnowledgeLibraryEditor` |
| `knowledge-library_relation_create`, `knowledge-library_relation_delete` | `KnowledgeLibraryEditor` |

Reviewers and admins inherit the editor permissions, admins the reviewer permissions through the role chain. The route permission allows opening the review pages; approving or returning a version additionally requires being its chosen reviewer. The permission names assume the module ID `knowledge-library`; with another module ID the application creates the permissions itself.

Everyone who may open the detail page of an item may also download its files, including the files of drafts.

`dmstr\web\User` resolves route permissions by prefix: a permission `knowledge-library` grants every route of the module, `knowledge-library_item` every item action including `delete`. The package therefore creates no such permission; applications should not either unless they want to grant everything below it.

Access to the frontend module is granted by a permission named exactly like its module ID, i.e. `knowledge` for the configuration above. The RBAC migrations create the permission `knowledge` without assigning it; through the prefix resolution of `dmstr\web\User` it grants all frontend routes (`knowledge_item_index`, `knowledge_item_view`, `knowledge_file_download`), but no backend route, as `knowledge-library_...` does not start with `knowledge_`. The backend roles do not contain it: the application assigns it to the readers of the frontend, usually through a role of its own. Guests are redirected to the login, logged-in users without the permission get 403.

The MCP module works the same way with the permission `knowledge-mcp` (migration `m261007_100000_knowledge_library_mcp_rbac`), which grants its routes `knowledge-mcp_default_index` and `knowledge-mcp_file_download`. It is separate from `knowledge`, so the application decides whether the readers of the frontend may also use the MCP server, e.g. by adding both permissions to the same role. A client the authenticator does not log in gets 401, an authenticated client without the permission 403.

## Running tests

```bash
composer install
vendor/bin/phpunit
```

The suite `unit` tests the models, the migrations and the JSON-RPC handling of the MCP server on an in-memory SQLite database. The suite `web` runs the backend and frontend pages and the MCP endpoint through `Yii::$app->runAction()` in a web application (`tests/WebTestCase.php`, `tests/FrontendWebTestCase.php`, `tests/McpWebTestCase.php`) with the RBAC migrations of the package applied, so the route permissions are tested as well.

GitHub Actions runs both suites on PHP 8.1 to 8.4 for every push to `master` and every pull request (`.github/workflows/tests.yml`).

## License

MIT, see [LICENSE](LICENSE).
