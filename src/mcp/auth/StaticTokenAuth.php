<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\auth;

use yii\filters\auth\HttpBearerAuth;
use yii\web\IdentityInterface;

/**
 * Bearer authentication with configured tokens, e.g. a service token from the
 * environment for a technical user, independent of the identity class:
 *
 * ```php
 * 'authenticator' => [
 *     'class' => StaticTokenAuth::class,
 *     'tokens' => [getenv('KNOWLEDGE_MCP_TOKEN')],
 *     'identity' => static fn () => User::findOne(['username' => 'mcp-client']),
 * ],
 * ```
 *
 * A request whose token matches none of the configured ones is not
 * authenticated by this filter (null), so it can be combined with other
 * filters in a `CompositeAuth`. Empty tokens never match.
 */
class StaticTokenAuth extends HttpBearerAuth
{
    /**
     * Accepted tokens; a single string or a list.
     *
     * @var string|string[]
     */
    public string|array $tokens = [];

    /**
     * Callable returning the identity to log in for an accepted token, called
     * with the token; null or no identity leaves the request unauthenticated.
     *
     * @var callable|null
     */
    public $identity;

    public function authenticate($user, $request, $response)
    {
        $authHeader = $request->getHeaders()->get($this->header);
        if ($authHeader === null || !preg_match($this->pattern, $authHeader, $matches)) {
            return null;
        }
        $token = $matches[1];

        $accepted = false;
        foreach ((array)$this->tokens as $candidate) {
            $candidate = (string)$candidate;
            // No early return: every candidate is compared, in constant time each.
            if ($candidate !== '' && hash_equals($candidate, $token)) {
                $accepted = true;
            }
        }
        if (!$accepted || !is_callable($this->identity)) {
            return null;
        }

        $identity = call_user_func($this->identity, $token);
        if (!$identity instanceof IdentityInterface || !$user->login($identity)) {
            return null;
        }

        return $identity;
    }
}
