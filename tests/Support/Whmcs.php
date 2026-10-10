<?php

namespace WHMCS\Authentication;

class CurrentUser
{
    public static $client;

    public static $user;

    public static $admin;

    public function client()
    {
        return self::$client;
    }

    public function user()
    {
        return self::$user;
    }

    public function admin()
    {
        return self::$admin;
    }

    public function isAuthenticatedUser()
    {
        return self::$user !== null;
    }

    public function isAuthenticatedAdmin()
    {
        return self::$admin !== null;
    }
}
