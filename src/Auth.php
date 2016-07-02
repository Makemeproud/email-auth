<?php

/**
 * Authentication via email class
 *
 * @package		EmailAuth
 * @author		Vladimir Osipov <vladimir.g.osipov@gmail.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */

namespace EmailAuth;

class Auth
{
    const STATUS_NO_LOGIN       = -1;       // No login attempt was done
    const STATUS_OK             = 0;        // Successful login
    const STATUS_OAUTH_NEEDED   = 1;        // Email provider will most probably require OAuth
    const STATUS_WRONG_PASSWORD = 2;        // Wrong password
    const STATUS_UNKNOWN        = 3;        // IMAP server was not found

    private $email;
    private $password;
    private $config;
    private $imapHost = null;

    public $status = self::STATUS_NO_LOGIN;

    /**
     * Auth constructor.
     * @param bool $tryRestricted
     */
    public function __construct($tryRestricted = false)
    {
        $this->config = new \stdClass();
        $this->config->tryRestricted = $tryRestricted;
    }

    /**
     * Tries to find an auth server and login
     *
     * @param $email
     * @param $password
     * @return bool
     */
    public function login($email, $password)
    {
        $this->email = $email;
        $this->password = $password;
        $this->status = self::STATUS_UNKNOWN;

        $domain = explode('@', $email);

        if (!$mxServer = Dns::getTopMx($domain[1]))
        {
            // no MX record found, try direct login
            return $this->imapAuth('imap.' . $domain[1]);
        }

        // assure that it's not Google or some other
        $mxServerDomains = explode('.', $mxServer);

        $mxServerRoot = @implode('.', array_slice($mxServerDomains, -2, 2));

        if (in_array($mxServerRoot, ['google.com', 'outlook.com']))
        {
            if ($this->config->tryRestricted)
            {
                return $this->imapAuth('imap.' . $mxServerRoot);
            }

            $this->status = self::STATUS_OAUTH_NEEDED;
            return false;
        }

        // try mail server directly
        if ($this->pingPort('imap.' . $domain[1]))
        {
            return $this->imapAuth('imap.' . $domain[1]);
        }

        // try MX-server
        if ($this->pingPort($mxServer))
        {
            // IMAP server found => try to authenticate
            return $this->imapAuth($mxServer);
        }

        // last chance, try MX-server root
        if ($this->pingPort('imap.' . $mxServerRoot))
        {
            return $this->imapAuth('imap.' . $mxServerRoot);
        }

        return false;
    }

    /**
     * Service function to get an address of an associated IMAP server
     *
     * @param $email
     * @return null|string
     */
    public function imapHost($email)
    {
        $domain = explode('@', $email);

        if ($this->pingPort('imap.' . $domain[1]))
        {
            $this->imapHost = 'imap.' . $domain[1];
        }
        elseif ($mxServer = Dns::getTopMx($domain[1]))
        {
            $mxServerDomains = explode('.', $mxServer);
            $mxServerRoot = @implode('.', array_slice($mxServerDomains, -2, 2));

            if ($this->pingPort($mxServer))
            {
                $this->imapHost = $mxServer;
            }
            else if ($this->pingPort('imap.' . $mxServerRoot))
            {
                $this->imapHost = 'imap.' . $mxServerRoot;
            }
        }

        return $this->imapHost;
    }

    /**
     * Tells if port is opened or not
     *
     * @param $host
     * @param int $port
     * @return bool
     */
    private function pingPort($host, $port = 993)
    {
        if (!$fp = @fsockopen($host, $port, $errno, $errstr, 1))
        {
            return false;
        }

        fclose($fp);
        return true;
    }

    /**
     * Tries to authenticate by logging to an IMAP server
     *
     * @param $host
     * @param int $port
     * @return bool
     */
    private function imapAuth($host, $port = 993)
    {
        if (!$box = @imap_open('{' . $host . ':' . $port . '/imap/ssl/novalidate-cert/readonly}', $this->email, $this->password))
        {
            $this->status = self::STATUS_WRONG_PASSWORD;
            return false;
        }

        imap_close($box);
        $this->status = self::STATUS_OK;

        return true;
    }
}
