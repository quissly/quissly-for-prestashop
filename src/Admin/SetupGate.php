<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Admin;

/**
 * Until Quissly Setup is finished, the module's pages open Setup instead (as the other plugins'
 * menus do); a notice for the next page (Setup's "you're live") rides on the employee's cookie.
 */
final class SetupGate
{
    private const NOTICE = 'quissly_notice';

    /** Leave the page for Setup when Setup is not finished. */
    public static function guard(\AdminController $controller): void
    {
        if (empty($controller->ajax) && !SetupFlow::onboarding()->isComplete()) {
            \Tools::redirectAdmin(\Context::getContext()->link->getAdminLink('AdminQuisslySetup'));
        }
    }

    public static function notify(string $message): void
    {
        $cookie = \Context::getContext()->cookie;
        $cookie->__set(self::NOTICE, $message);
        $cookie->write();
    }

    /** The waiting notice, once. */
    public static function takeNotice(): string
    {
        $cookie = \Context::getContext()->cookie;
        $message = (string) $cookie->__get(self::NOTICE);
        if ($message !== '') {
            $cookie->__unset(self::NOTICE);
            $cookie->write();
        }

        return $message;
    }

    private function __construct()
    {
    }
}
