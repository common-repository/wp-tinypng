<?php
/**
 *  TinyPNG API v1
 *
 *  Michael Wright - @michaelw90
 */


class TinyPNG
{

    private $url = 'http://api.tinypng.org/api/shrink';
    private $curl = null;
    private $lastResult = null;

    /**
     * Constructor
     * @param strong $key API key for all requests
     */
    public function __construct($key)
    {
        if ($this->curl === null) {
            $this->curl = curl_init();
            $curlOpts = array(
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_URL => $this->url,
                CURLOPT_USERAGENT => 'TinyPNG PHP API v1',
                CURLOPT_POST => 1,
                CURLOPT_USERPWD => 'api:' . $key,
                CURLOPT_BINARYTRANSFER => 1
            );
            curl_setopt_array($this->curl, $curlOpts);
        }
    }

    /**
     * Send image shrink request
     * @param  string $file path to file to shrink
     * @return boolean|exception       Is HTTP response 200
     */
    public function shrink($file)
    {
    	if (file_exists($file) === false) {
            throw new Exception('File does not exist');
        }
        curl_setopt($this->getCurl(), CURLOPT_POSTFIELDS, file_get_contents($file));
        $this->lastResult = curl_exec($this->getCurl());
        return curl_getinfo($this->getCurl(), CURLINFO_HTTP_CODE) === 200;
    }

    /**
     * Return API response object
     * @return object|exception
     */
    public function getResult() {
        return $this->_getResult();
    }

    /**
     * Return API response as JSON
     * @return string|exception
     */
    public function getResultJson() {
        return json_decode($this->_getResult());
    }

    /**
     * Return API response object
     * @return object|exception
     */
    protected function _getResult()
    {
        if ($this->lastResult === null) {
            throw new Exception('No current result');
        }
        return $this->lastResult;
    }

    /**
     * Return Curl object
     * @return object|exception
     */
    protected function getCurl()
    {
        if ($this->curl === null) {
            throw new Exception('cURL not yet initialized.');
        }

        return $this->curl;
    }
}