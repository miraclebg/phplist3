<?php

error_reporting(E_ALL ^ E_NOTICE);
ini_set('memory_limit', '256M');
set_time_limit(600);

///////////////////////////////////////////////////////////////

define('BOUNCES_IMPORT_ENABLED', true);
define('BOUNCES_SILENT_EXEC', false);

define('BOUNCES_EMAIL', 'bounces@hostname.com');
define('BOUNCES_IMAP_SERVER_HOSTNAME', 'localhost');
define('BOUNCES_EMAIL_PASSWORD', '');
define('BOUNCES_ALLOWED_FROM', 'no-reply@sns.amazonaws.com');
define('BOUNCES_ARN_TOPIC', 'arn:sample:topic');
define('BOUNCES_DELETE_PROCESSED', true);

define('IMPORTER_DB_CONNECTION_STRING', 'mysql:host=localhost;dbname=phplist');
define('IMPORTER_DB_USERNAME', 'root');
define('IMPORTER_DB_PASSWORD', '');
define('IMPORTER_PHPLIST_TBL_PREFIX', 'phplist_');
define('IMPORTER_TEST_MODE', false);

///////////////////////////////////////////////////////////////

class AWSBouncesHandler
{
    const DEFAULT_SERVER_PORT = 143;
    const DEFAULT_SERVER_HOSTNAME = 'localhost';
    const DEFAULT_FOLDER = 'INBOX';

    /**
     * @var string
     */
    protected $bounces_email;

    /**
     * @var array
     */
    protected $bounces_email_auth;

    /**
     * @var array
     */
    protected $bounces_email_server = array(
        'hostname' => self::DEFAULT_SERVER_HOSTNAME,
        'port' => self::DEFAULT_SERVER_PORT
    );

    /**
     * @var string
     */
    protected $bounces_email_folder = self::DEFAULT_FOLDER;

    /**
     * @var bool
     */
    private $is_connected;

    /**
     * @var resource
     */
    protected $imap;

    /**
     * @var bool
     */
    protected $opt_delete_processed;

    /**
     * @var array
     */
    protected $allowed_from_addresses;

    /**
     * @var array
     */
    protected $allowed_arn_topics;

    /**
     * @var array|null
     */
    protected $bounces;

    /**
     * @var bool
     */
    protected $silent;

    /**
     * @param string $email
     * @param array $server
     * @param array $auth
     * @return AWSBouncesHandler
     */
    public function setBouncesEmail($email, array $server, array $auth)
    {
        $this->bounces_email = $email;
        $this->bounces_email_server = array(
            'hostname' => null !== $server['hostname'] ? $server['hostname'] : self::DEFAULT_SERVER_HOSTNAME,
            'port' => null !== $server['port'] ? (int)$server['port'] : self::DEFAULT_SERVER_PORT,
        );

        $this->bounces_email_auth = array(
            'username' => null !== $auth['username'] ? $auth['username'] : $email,
            'password' => null !== $auth['password'] ? $auth['password'] : null,
        );

        return $this;
    }

    /**
     * @param string $folder
     * @return AWSBouncesHandler
     */
    public function setInboxFolder($folder)
    {
        $this->bounces_email_folder = $folder;
        return $this;
    }

    /**
     * @param array|string $from
     * @return AWSBouncesHandler
     */
    public function setAllowedFrom($from)
    {
        $this->allowed_from_addresses = is_array($from) ? $from : array($from);
        return $this;
    }

    /**
     * @param array|string $topics
     * @return AWSBouncesHandler
     */
    public function setAllowedArnTopics($topics)
    {
        $this->allowed_arn_topics = is_array($topics) ? $topics : array($topics);
        return $this;
    }

    /**
     * @return AWSBouncesHandler
     */
    protected function connect()
    {
        if (!$this->is_connected) {
            $this->imap = imap_open('{' . $this->bounces_email_server['hostname'] . ':' .
                $this->bounces_email_server['port'] . '}' . $this->bounces_email_folder,
                $this->bounces_email_auth['username'],
                $this->bounces_email_auth['password']
            );

            $this->is_connected = $this->imap != null;

            //$this->log('Connected');
        }

        return $this;
    }

    /**
     * @return AWSBouncesHandler
     */
    protected function disconnect()
    {
        if ($this->is_connected && $this->imap) {
            imap_close($this->imap);
            $this->imap = null;

            //$this->log('Disconnected');
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->is_connected;
    }

    protected function validateEmail($headers, $mail)
    {
        // validate From:
        if ($this->allowed_from_addresses) {
            $from = $headers->from;
            $from = isset($from[0]) ? $from[0]->mailbox . '@' . $from[0]->host : null;

            if (!$from || !in_array($from, $this->allowed_from_addresses)) {
                return false;
            }
        }

        // validate ARN Topics
        if ($this->allowed_arn_topics) {
            $splh = imap_fetchheader($this->imap, $mail);

            $matches = array();
            $matched = preg_match_all('/([^: ]+): (.+?(?:\r\n\s(?:.+?))*)\r\n/m', $splh, $matches);

            if (!$matched || !in_array('x-amz-sns-subscription-arn', $matches[1])) {
                return false;
            }

            $key = array_search('x-amz-sns-subscription-arn', $matches[1]);
            $arn_value = $matches[2][$key];
            $arn_value = substr($arn_value, 0, strrpos($arn_value, ':'));

            if (!in_array($arn_value, $this->allowed_arn_topics)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int $mail
     * @return bool
     */
    protected function processEmail($mail)
    {
        $headers = imap_headerinfo($this->imap, $mail);

        if (!$headers) {
            return false;
        }

        if (!$this->validateEmail($headers, $mail)) {
            return false;
        }

        $body = imap_body($this->imap, $mail);

        return $this->parseEmailBody($body);
    }

    protected function parseEmailBody($body)
    {
        $body = str_replace(array("\n", "\r"), '', $body);

        $matches = array();
        preg_match_all("/\{\"bounceType\"(.*?)\}\}/", $body, $matches);

        if (!$matches) {
            return false;
        }

        foreach ((array)$matches[0] as $match) {

            $d = '{"bounce":' . $match;
            $notification_data = json_decode($d);

            if ($notification_data) {
                //$this->log('Detected a bounce: ' . print_r($notification_data->bounce, true));
                $this->bounces[] = $notification_data->bounce;
            }

            unset($match);
        }

        return true;
    }

    protected function handleEmails()
    {
        $this->bounces = array();

        $emails = imap_search($this->imap, 'ALL');

        if ($emails) {
            foreach ((array)$emails as $mail) {

                $ret = $this->processEmail($mail);

                if ($ret && $this->opt_delete_processed) {
                    imap_delete($this->imap, $mail);
                }

                unset($mail);
            }
        }
    }

    protected function expungeEmails()
    {
        imap_expunge($this->imap);
    }

    /**
     * @return AWSBouncesHandler
     * @throws Exception
     */
    public function handleBounces()
    {
        $this->connect();

        if ($this->is_connected) {
            try {
                $this->handleEmails();

                $this->expungeEmails();

                $this->disconnect();
            } catch (Exception $e) {
                $this->disconnect();
                throw $e;
            }
        }

        return $this;
    }

    protected function log($str)
    {
        if (!$this->silent) {
            echo '[' . date('Y-m-d H:i:s') . ']: ' . $str . "\n";
        }
    }

    /**
     * @return array|null
     */
    public function getBounces()
    {
        return $this->bounces;
    }

    /**
     * @param bool $silent
     * @return AWSBouncesHandler
     */
    public function setSilent($silent)
    {
        $this->silent = $silent;
        return $this;
    }

    /**
     * @param bool $opt_delete_processed
     * @return AWSBouncesHandler
     */
    public function setOptDeleteProcessed($opt_delete_processed)
    {
        $this->opt_delete_processed = $opt_delete_processed;
        return $this;
    }
}

class PHPListBouncesImporter
{
    const DEFAULT_BLACKLIST_AMOUNT = 3;

    /**
     * @var string
     */
    private $db_dsn;

    /**
     * @var string
     */
    private $db_username;

    /**
     * @var string
     */
    private $db_password;

    /**
     * @var PDO
     */
    protected $db;

    /**
     * @var array
     */
    protected $bounces;

    /**
     * @var bool
     */
    protected $silent;

    /**
     * @var bool
     */
    protected $test_mode;

    /**
     * @var int
     */
    protected $opt_blacklist_on_number_of_bounces = self::DEFAULT_BLACKLIST_AMOUNT;

    /**
     * @var string
     */
    protected $opt_phplist_tbl_prefix;

    /**
     * @param array $bounces
     * @return PHPListBouncesImporter
     */
    public function setBounces($bounces)
    {
        $this->bounces = $bounces;
        return $this;
    }

    public function setDatabaseConnectDetails($dsn, $username, $password)
    {
        $this->db_dsn = $dsn;
        $this->db_username = $username;
        $this->db_password = $password;

        return $this;
    }

    /**
     * @param bool $silent
     * @return PHPListBouncesImporter
     */
    public function setSilent($silent)
    {
        $this->silent = $silent;
        return $this;
    }

    protected function log($str)
    {
        if (!$this->silent) {
            echo '[' . date('Y-m-d H:i:s') . ']: ' . $str . "\n";
        }
    }

    protected function connect()
    {
        if (!$this->db) {
            $this->db = new PDO(
                $this->db_dsn,
                $this->db_username,
                $this->db_password
            );

            if (!$this->db) {
                throw new Exception('Could not connect to database');
            }
        }
    }

    protected function blacklistEmail($email, $notes = null)
    {
        $this->log('BLACKLISTED: ' . $email);

        if (!$this->test_mode) {
            $sql = 'UPDATE `' . $this->opt_phplist_tbl_prefix . 'user_user` SET blacklisted = 1';
            $this->db->exec($sql);

            $sql = 'REPLACE INTO `' . $this->opt_phplist_tbl_prefix . 'user_blacklist` (email, added) VALUES(:email, CURRENT_TIMESTAMP)';
            $sth = $this->db->prepare($sql);
            $sth->bindValue(':email', $email, PDO::PARAM_STR);
            $sth->execute();

            $sql = 'INSERT INTO `' . $this->opt_phplist_tbl_prefix . 'user_blacklist_data` (email, name, data) VALUES(:email, \'reason\', :notes)';
            $sth = $this->db->prepare($sql);
            $sth->bindValue(':email', $email, PDO::PARAM_STR);
            $sth->bindValue(':notes', 'Blacklisted from AWS Bounce Importer' . ($notes ? ' (' . $notes . ')' : null), PDO::PARAM_STR);
            $sth->execute();
        }

        return true;
    }

    protected function handleBouncedRecipient(stdClass $recipient)
    {
        $email = $recipient->emailAddress;
        $diag_code = $recipient->diagnosticCode;

        if (!$email) {
            return false;
        }

        // inc the number of bounces - if max reached - blacklist
        $sql = 'SELECT * FROM `' . $this->opt_phplist_tbl_prefix . 'user_user` WHERE email = :email';
        $sth = $this->db->prepare($sql);
        $sth->bindValue(':email', $email, PDO::PARAM_STR);
        $sth->execute();

        if (!$sth->rowCount()) {
            return false;
        }

        $row = $sth->fetch();

        // already blacklisted
        if ($row['blacklisted']) {
            return false;
        }

        $row['bouncecount']++;

        $this->log($email . ': ' . $row['bouncecount'] . ' bounce(s)');

        if (!$this->test_mode) {
            // inc the number of bounces
            $sql = 'UPDATE `' . $this->opt_phplist_tbl_prefix . 'user_user` SET bouncecount = bouncecount + 1';
            $this->db->exec($sql);
        }

        if ($this->opt_blacklist_on_number_of_bounces &&
            $row['bouncecount'] >= $this->opt_blacklist_on_number_of_bounces
        ) {
            $this->blacklistEmail($email, $diag_code);
        }

        return true;
    }

    protected function handleBounce(stdClass $bounce)
    {
        $recipients = $bounce->bouncedRecipients;

        if ($recipients) {
            foreach ((array)$recipients as $recipient) {

                $this->handleBouncedRecipient($recipient);

                unset($recipient);
            }
        }
    }

    /**
     * @return PHPListBouncesImporter
     */
    public function import()
    {
        $this->connect();

        if (!$this->bounces) {
            return $this;
        }

        foreach ($this->bounces as $bounce) {

            $this->handleBounce($bounce);

            unset($bounce);
        }

        return $this;
    }

    /**
     * @param int $opt_blacklist_on_number_of_bounces
     * @return PHPListBouncesImporter
     */
    public function setOptBlacklistOnNumberOfBounces($opt_blacklist_on_number_of_bounces)
    {
        $this->opt_blacklist_on_number_of_bounces = $opt_blacklist_on_number_of_bounces;
        return $this;
    }

    /**
     * @param string $opt_phplist_tbl_prefix
     * @return PHPListBouncesImporter
     */
    public function setOptPhplistTblPrefix($opt_phplist_tbl_prefix)
    {
        $this->opt_phplist_tbl_prefix = $opt_phplist_tbl_prefix;
        return $this;
    }

    /**
     * @param bool $test_mode
     * @return PHPListBouncesImporter
     */
    public function setTestMode($test_mode)
    {
        $this->test_mode = $test_mode;
        return $this;
    }
}

//////////////////////////////////////////////////////////////////

if (BOUNCES_IMPORT_ENABLED) {
    $aws_handler = new AWSBouncesHandler();

    $aws_handler->setBouncesEmail(BOUNCES_EMAIL, array(
        'hostname' => BOUNCES_IMAP_SERVER_HOSTNAME
    ), array(
        'username' => BOUNCES_EMAIL,
        'password' => BOUNCES_EMAIL_PASSWORD
    ))
        ->setSilent(BOUNCES_SILENT_EXEC)
        ->setOptDeleteProcessed(BOUNCES_DELETE_PROCESSED)
        ->setAllowedFrom(BOUNCES_ALLOWED_FROM)
        ->setAllowedArnTopics(BOUNCES_ARN_TOPIC)
        ->handleBounces();

    $bounces = $aws_handler->getBounces();

    if ($bounces) {
        $importer = new PHPListBouncesImporter();
        $importer
            ->setDatabaseConnectDetails(
                IMPORTER_DB_CONNECTION_STRING,
                IMPORTER_DB_USERNAME,
                IMPORTER_DB_PASSWORD
            )
            ->setSilent(BOUNCES_SILENT_EXEC)
            ->setTestMode(IMPORTER_TEST_MODE)
            ->setOptPhplistTblPrefix(IMPORTER_PHPLIST_TBL_PREFIX)
            ->setBounces($bounces)
            ->import();
    }
}

exit(0);
