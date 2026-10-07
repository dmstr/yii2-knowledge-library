<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp;

use dmstr\knowledgeLibrary\mcp\tools\GetFileTool;
use dmstr\knowledgeLibrary\mcp\tools\GetItemTool;
use dmstr\knowledgeLibrary\mcp\tools\ListTopicsTool;
use dmstr\knowledgeLibrary\mcp\tools\ListTypesTool;
use dmstr\knowledgeLibrary\mcp\tools\SearchItemsTool;
use dmstr\knowledgeLibrary\mcp\tools\ToolInterface;
use dmstr\knowledgeLibrary\Module as BackendModule;
use dmstr\web\traits\AccessBehaviorTrait;
use Yii;
use yii\base\InvalidConfigException;
use yii\filters\auth\HttpBearerAuth;
use yii\web\User;

/**
 * MCP module: a stateless Model Context Protocol server over Streamable HTTP
 * giving AI clients read access to the knowledge items valid today, with the
 * same visibility rules as the frontend module.
 *
 * The endpoint is the module URL (`default/index`, POST only); the module
 * also delivers the files of the valid versions (`file/download`), so a
 * client can fetch them with the same credentials.
 *
 * Access is granted by a permission named exactly like the module ID, e.g.
 * `knowledge-mcp` when the module is registered as `knowledge-mcp`. Clients
 * authenticate with the `authenticator` filter, which runs before the access
 * check; without a session, as every request carries its own credentials.
 */
class Module extends \yii\base\Module
{
    use AccessBehaviorTrait {
        behaviors as private accessBehaviors;
    }

    /**
     * ID of the backend module whose configuration (file storage) the module
     * shares.
     */
    public ?string $backendModuleId = 'knowledge-library';

    /**
     * The module URL is the MCP endpoint.
     */
    public $defaultRoute = 'default';

    /**
     * Configuration of the authentication filter (an `AuthMethod`, e.g.
     * HttpBearerAuth, bizley's JwtHttpBearerAuth or CompositeAuth) attached
     * to the module before the access check. The default bearer filter
     * resolves the token with `findIdentityByAccessToken()` of the identity
     * class. Null attaches no filter: clients then need a session of the
     * application, e.g. a browser-based client on the same host.
     *
     * @var array|string|null
     */
    public array|string|null $authenticator = ['class' => HttpBearerAuth::class];

    /**
     * Name and version announced to the client on `initialize`.
     */
    public string $serverName = 'Knowledge Library';

    public string $serverVersion = '1.0.0';

    /**
     * Instructions for the model announced on `initialize`, null for the
     * default text.
     */
    public ?string $instructions = null;

    /**
     * Largest file in bytes that `knowledge_get_file` returns inline; larger
     * files are answered with their download URL.
     */
    public int $maxInlineFileSize = 5 * 1024 * 1024;

    /**
     * Tools of the server: class names or configuration arrays, each created
     * with the module as constructor argument. Override to add or remove
     * tools.
     *
     * @var array<string|array>
     */
    public array $tools = [
        SearchItemsTool::class,
        GetItemTool::class,
        GetFileTool::class,
        ListTypesTool::class,
        ListTopicsTool::class,
    ];

    public function behaviors()
    {
        $behaviors = $this->accessBehaviors();
        if ($this->authenticator !== null) {
            // Attached first, so it logs the client in before the access
            // filter of the trait checks the route permission: the filters
            // run in the order they are attached.
            $behaviors = ['authenticator' => $this->authenticator] + $behaviors;
        }

        return $behaviors;
    }

    public function beforeAction($action)
    {
        // Every request carries its own credentials; no session is started
        // or written for the login of an MCP request.
        $user = Yii::$app->getUser();
        if ($user instanceof User) {
            $user->enableSession = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Backend module registered in the application as `backendModuleId`.
     *
     * @throws InvalidConfigException if no module is registered under that ID
     * or it is no backend module of the package
     */
    public function getBackendModule(): BackendModule
    {
        $module = $this->backendModuleId === null || $this->backendModuleId === ''
            ? null
            : Yii::$app->getModule($this->backendModuleId);

        if (!$module instanceof BackendModule) {
            throw new InvalidConfigException(sprintf(
                'The backend module "%s" must be registered as %s, got %s.',
                (string)$this->backendModuleId,
                BackendModule::class,
                get_debug_type($module)
            ));
        }

        return $module;
    }

    /**
     * The server with the configured tools.
     *
     * @throws InvalidConfigException if a tool definition yields no tool
     */
    public function createServer(): Server
    {
        $server = new Server($this->serverName, $this->serverVersion, $this->instructions ?? static::defaultInstructions());

        foreach ($this->tools as $definition) {
            $tool = Yii::createObject($definition, [$this]);
            if (!$tool instanceof ToolInterface) {
                throw new InvalidConfigException(sprintf(
                    'Tools of the MCP module must implement %s, got %s.',
                    ToolInterface::class,
                    get_debug_type($tool)
                ));
            }
            $server->addTool($tool);
        }

        return $server;
    }

    /**
     * Default instructions announced to the client.
     */
    public static function defaultInstructions(): string
    {
        return 'The knowledge library holds the knowledge objects (e.g. guidelines, laws, work instructions) '
            . 'that are valid today, each with title, summary, type, topics, an optional text in Markdown and '
            . 'files. Find objects with knowledge_search, read the full text, files and relations of one object '
            . 'with knowledge_get_item and fetch a file with knowledge_get_file. Drafts, versions in review, '
            . 'withdrawn, historical and upcoming versions and archived objects are not available.';
    }
}
