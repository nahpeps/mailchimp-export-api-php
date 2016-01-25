<?php

use Guzzle\Http\Client;
use Guzzle\Stream\PhpStreamRequestFactory;
use Guzzle\Stream\StreamInterface;

require_once 'MailchimpExportDataCallbackInterface.php';
require_once 'MailchimpExport/Ecomm.php';
require_once 'MailchimpExport/Lists.php';
require_once 'MailchimpExport/CampaignSubscriberActivity.php';
require_once 'MailchimpExport/Exceptions.php';

class MailchimpExport
{

    const REQUEST_TIMEOUT = 600;
    public $apikey;
    public $ch;
    public $root = 'https://api.mailchimp.com/export/1.0';
    public $debug = false;
    /** @var \MailchimpExport_Ecomm */
    public $ecomm;
    /** @var \MailchimpExport_Lists */
    public $lists;
    /** @var \MailchimpExport_CampaignSubscriberActivity */
    public $campainSubscriberActivity;
    /** @var  Client $client */
    private $client;

    /**
     * @param null $apikey
     * @param array $opts
     */
    public function __construct($apikey = null, $opts = array())
    {

        $this->apikey = $this->initializeApikey($apikey);
        $this->root = $this->initializeRequestRoot($this->root, $this->apikey);
        $this->client = $this->initializeClient($this->root, $opts);

        // initialize provided apis
        $this->ecomm = new MailchimpExport_Ecomm($this);
        $this->lists = new MailchimpExport_Lists($this);
        $this->campainSubscriberActivity = new MailchimpExport_CampaignSubscriberActivity($this);
    }

    /**
     * @param $apikey
     * @return bool|string
     * @throws Mailchimp_Error
     */
    protected function initializeApikey($apikey)
    {

        if (!$apikey) {
            $apikey = getenv('MAILCHIMP_APIKEY');
        }

        if (!$apikey) {
            $apikey = $this->readConfigs();
        }
        if (!$apikey) {
            throw new Mailchimp_Error('You must provide a MailChimp API key');
        }
        return $apikey;
    }

    /**
     * @param $root
     * @param $apiKey
     * @return mixed|string
     */
    protected function initializeRequestRoot($root, $apiKey) {
        $dc = "us1";

        if (strstr($apiKey, "-")) {
            list($key, $dc) = explode("-", $apiKey, 2);
            if (!$dc) {
                $dc = "us1";
            }
        }

        $root = str_replace('https://api', 'https://' . $dc . '.api', $root);
        $root = rtrim($root, '/') . '/';
        return $root;
    }

    /**
     * @param $root
     * @param array $opts
     * @return Client
     */
    protected function initializeClient($root, array $opts) {
        if (!isset($opts['timeout']) || !is_int($opts['timeout'])) {
            $opts['timeout'] = self::REQUEST_TIMEOUT;
        }
        if (isset($opts['debug'])) {
            $this->debug = true;
        }

        $clientOptions = array(
            'curl.options' => array(
                'CURLOPT_POST' => true,
                'CURLOPT_CONNECTTIMEOUT' => 30,
                'CURLOPT_TIMEOUT' => $opts['timeout'],
            ),
        );

        $client = new Client($this->root, $clientOptions);
        $client->setUserAgent('Mailchimp-PHP/1.0');

        $client->setDefaultOption('allow_redirects', false);
        if (isset($opts['CURLOPT_FOLLOWLOCATION']) && $opts['CURLOPT_FOLLOWLOCATION'] === true) {
            $client->setDefaultOption('allow_redirects', true);
        }
        return $client;
    }

    /**
     * @return bool|string
     */
    public function readConfigs()
    {
        $paths = array('~/.mailchimp.key', '/etc/mailchimp.key');
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $apikey = trim(file_get_contents($path));
                if ($apikey) {
                    return $apikey;
                }
            }
        }

        return false;
    }

    /**
     * @param $url
     * @param $params
     * @param MailchimpExport_DataCallbackInterface $dataCallable
     */
    public function call($url, $params, MailchimpExport_DataCallbackInterface $dataCallable)
    {
        $params['apikey'] = $this->apikey;
        $params = $this->filterParameter($params);
        $stream = $this->createStream($url, $params);
        $this->readFromStream($stream, $dataCallable);
    }

    /**
     * @param array $params
     * @return array
     */
    protected function filterParameter(array $params)
    {
        // filter null values
        foreach ($params as $key => $value) {
            if ($value === null) {
                unset($params[$key]);
            }
        }

        return $params;
    }

    /**
     * @param $url
     * @param array $params
     * @return \Guzzle\Stream\StreamInterface
     */
    protected function createStream($url, array $params)
    {
        $request = $this->client->post($url, null, $params);

        $factory = new PhpStreamRequestFactory();

        return $factory->fromRequest($request);
    }

    /**
     * @param StreamInterface $stream
     * @param MailchimpExport_DataCallbackInterface $dataCallable
     * @throws MailchimpExport_Exception
     */
    protected function readFromStream(StreamInterface $stream, MailchimpExport_DataCallbackInterface $dataCallable)
    {
        $headings = null;
        $first = true;
        $hasHeadingRow = $dataCallable->hasHeadingRow();

        // Read until the stream is closed
        while (!$stream->feof()) {
            // Read a line from the stream
            $currentRow = trim($stream->readLine());
            if (strlen($currentRow) == 0) {
                continue;
            }
            $currentDataSet = json_decode($currentRow, true);

            if (!$currentDataSet) {
                throw new \MailchimpExport_Exception('invalid response: ' . $currentRow);
            }

            $currentRow = null;

            // check for errors
            if (isset($currentDataSet['error']) && isset($currentDataSet['code'])) {
                throw new \MailchimpExport_Exception($currentDataSet['error'], $currentDataSet['code']);
            }

            if ($first && $hasHeadingRow) {
                $headings = $currentDataSet;
                $first = false;
                continue;
            }

            // if we have headings => combine them with the dataset
            if ($headings !== null) {
                // Sometimes mailchimps gives an smaller array of data because of missing data.
                // This will cause an error in array_column
                while(count($headings) > count($currentDataSet)){
                    $currentDataSet[]= null;
                }
                
                $returnValue = array_combine($headings, $currentDataSet);
            } else {
                $returnValue = $currentDataSet;
            }

            $dataCallable->addDataSet($returnValue);
            $returnValue = null;
            $currentDataSet = null;
        }
    }

    public function log($msg)
    {
        if ($this->debug) {
            error_log($msg);
        }
    }

}


