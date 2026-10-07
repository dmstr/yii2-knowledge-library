<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\controllers;

use dmstr\knowledgeLibrary\frontend\controllers\FileController as FrontendFileController;
use dmstr\knowledgeLibrary\mcp\Module;

/**
 * Delivers the files of the versions valid today to MCP clients, with the
 * credentials of the MCP endpoint instead of a session. Same rules and
 * responses as the frontend download, see the parent class.
 *
 * @property Module $module
 */
class FileController extends FrontendFileController
{
}
