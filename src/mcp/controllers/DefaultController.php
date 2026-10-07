<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\controllers;

use dmstr\knowledgeLibrary\mcp\JsonRpcException;
use dmstr\knowledgeLibrary\mcp\Module;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\web\Controller;
use yii\web\Response;

/**
 * The MCP endpoint: every POST carries one JSON-RPC message (or a batch) and
 * is answered on its own.
 *
 * GET is not offered: without sessions there is no server-initiated stream
 * to open. DELETE is not offered either, there is no session to end; both
 * are answered with 405, as the Streamable HTTP transport specifies for
 * servers without these features.
 *
 * Access control is done by the module (authenticator and route permission),
 * the controller adds no filter of its own.
 *
 * @property Module $module
 */
class DefaultController extends Controller
{
    /**
     * Requests are authenticated per request, not by a session cookie.
     */
    public $enableCsrfValidation = false;

    public function init()
    {
        parent::init();

        // Also for errors thrown before the action, e.g. 401 of the authenticator.
        $this->response->format = Response::FORMAT_JSON;
    }

    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index' => ['POST'],
                ],
            ],
        ]);
    }

    /**
     * Handles the message(s) of the request body.
     *
     * @return array|null the response message(s) as JSON; null with status
     * 202 if there is nothing to answer (notifications)
     */
    public function actionIndex(): ?array
    {
        $server = $this->module->createServer();

        try {
            $result = $server->handle($this->request->getRawBody());
        } catch (JsonRpcException $e) {
            $this->response->setStatusCode(400);

            return $server::errorResponse(null, $e);
        }

        if ($result === null) {
            $this->response->setStatusCode(202);
        }

        return $result;
    }
}
