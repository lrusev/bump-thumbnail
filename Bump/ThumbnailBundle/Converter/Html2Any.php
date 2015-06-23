<?php

namespace Bump\ThumbnailBundle\Converter;

use Curl\Curl;
use RuntimeException;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

class Html2Any
{
    private $baseUrl;
    private $username;
    private $password;
    private $logger;
    private $enabled = true;

    public function __construct($baseUrl = 'http://html2any.pdc.org/api', $username = null, $password = null, $logger = null, $enabled = true)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        if (strtolower(substr($this->baseUrl, -3)) != 'api') {
            $this->baseUrl .= '/api';
        }

        $this->username = $username;
        $this->password = $password;

        if (is_null($logger)) {
            $this->logger = new Logger('html2any');
            $this->logger->pushHandler(new NullHandler());
        } else {
            $this->logger = $logger;
        }

        $this->enabled = $enabled;
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    public function disable($flag = true)
    {
        $this->enabled = (bool)$flag;

        return $this;
    }

    public function convertPDFToImage($accessUrl, $callbackUrl, array $params = array())
    {
        return $this->handleRequest('convertPdfToImage', $accessUrl, $callbackUrl, $params);
    }

    public function convertOfficeToImage($accessUrl, $callbackUrl, array $params = array())
    {
        return $this->handleRequest('convertOfficeToImage', $accessUrl, $callbackUrl, $params);
    }

    public function convertHtmlToImage($accessUrl, $callbackUrl, array $params = array())
    {
        return $this->handleRequest('convertHtmlToImage', $accessUrl, $callbackUrl, $params);
    }

    protected function handleRequest($action, $accessUrl, $callbackUrl, array $params = array())
    {
        $client = $this->getClient();

        $data = array_merge(
            [
                'st' => 1,
                'm' => 'content',
                'action' => $action,
                'url' => $accessUrl,
                'callback' => $callbackUrl,
            ],
            $params
        );

        $client->post($this->baseUrl, $data);

        return $this->handleResponse($client);
    }

    protected function handleResponse(Curl $client)
    {
        if ($client->error) {
            throw new RuntimeException("Curl error {$client->error_code}: {$client->error_message}");
        }

        $data = json_decode($client->response, true);

        if ($data && isset($data['response']['result']) && $data['response']['result'] === true) {
            $this->logger->info("Html2Any request success", $data, $client->response_headers);

            return true;
        }

        $this->logger->addError("Html2Any request failed", $data, $client->response_headers);

        return false;
    }

    /**
     * Gets the value of baseUrl.
     *
     * @return mixed
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * Sets the value of baseUrl.
     *
     * @param mixed $baseUrl the base url
     *
     * @return self
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    /**
     * Gets the value of username.
     *
     * @return mixed
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Sets the value of username.
     *
     * @param mixed $username the username
     *
     * @return self
     */
    public function setUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Gets the value of password.
     *
     * @return mixed
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Sets the value of password.
     *
     * @param mixed $password the password
     *
     * @return self
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    public function getClient()
    {
        $client = new Curl();

        if (!is_null($this->username) && !is_null($this->password)) {
            $client->setBasicAuthentication($this->username, $this->password);
        }

        $client->setopt(CURLOPT_CONNECTTIMEOUT, 0);
        $client->setopt(CURLOPT_TIMEOUT, 60);
        $client->setopt(CURLOPT_NOSIGNAL, true);

        return $client;
    }
}
