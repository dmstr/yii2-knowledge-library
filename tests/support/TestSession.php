<?php

namespace dmstr\knowledgeLibrary\tests\support;

use yii\web\Session;

/**
 * Session for web tests that keeps its data in `$_SESSION` without starting a
 * PHP session, so no headers or cookies are sent. Flash messages work as in a
 * real session.
 */
class TestSession extends Session
{
    private bool $active = false;

    public function open()
    {
        if ($this->active) {
            return;
        }

        $this->active = true;
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $this->updateFlashCounters();
    }

    public function close()
    {
        $this->active = false;
    }

    public function destroy()
    {
        $_SESSION = [];
        $this->active = false;
    }

    public function getIsActive()
    {
        return $this->active;
    }

    public function getHasSessionId()
    {
        return $this->active;
    }

    public function getId()
    {
        return $this->active ? 'test-session' : '';
    }

    public function regenerateID($deleteOldSession = false)
    {
    }
}
