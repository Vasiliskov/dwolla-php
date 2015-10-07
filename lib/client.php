<?php

/**
 *      _               _ _
 *   __| |_      _____ | | | __ _
 *  / _` \ \ /\ / / _ \| | |/ _` |
 * | (_| |\ V  V / (_) | | | (_| |
 *  \__,_| \_/\_/ \___/|_|_|\__,_|
 * An official Guzzle based wrapper for the Dwolla API.
 * Support is available on our forums at: https://discuss.dwolla.com/category/api-support
 *
 * @package   Dwolla
 * @author    Dwolla (David Stancu): api@dwolla.com, david@dwolla.com
 * @copyright Copyright (C) 2014 Dwolla Inc.
 * @license   MIT (http://opensource.org/licenses/MIT)
 * @version   2.1.6
 * @link      http://developers.dwolla.com
 */

namespace Dwolla;

require_once '_settings.php';

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Exception\RequestException;

use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Stream\Stream;


class RestClient {

    /**
     * @var $settings
     *
     * Settings object.
     */
    public static $settings;

    /**
     * @var $client
     *
     * Placeholder for Guzzle REST client.
     */
    public static $client;

    /**
     * PHP "magic" getter.
     *
     * @param $name
     *
     * @return $value
     */
    public function __get ($name) {
        return $this->$name;
    }

    /**
     * PHP "magic" setter.
     *
     * @param $name
     * @param $value
     */
    public function __set ($name, $value) {
        $this->$name = $value;
    }

    /**
     * Logs console messages to file for convenience.
     * (Thank you, @redzarf for your contribution)
     *
     * @param $data {???} Can be anything.
     */
    protected function _logtofile ($data) {
        if (!empty(self::$settings->logfilePath) && file_exists(self::$settings->logfilePath . "/")) {
            file_put_contents(
                self::$settings->logfilePath . "/" . date("Y-m-d") . ".log",
                date("Y-m-d H:i:s") . '  ' . (is_array($data) ? print_r($data) : trim($data)) . "\n",
                FILE_APPEND
            );
        }
    }

    /**
     * Echos output and logs to console (and js console to make browser debugging easier).
     *
     * @param $data {???} Can be anything.
     */
    protected function _console ($data) {
        if (self::$settings->debug) {
            if (self::$settings->browserMessages) {
                print("<script>console.log(");
                is_array($data) ? print_r($data) : print($data);
                print(");</script>\n\n");
                is_array($data) ? (print_r($data) && print("\n")) : print($data . "\n");
            }
            if (!empty(self::$settings->logfilePath)) {
                $this->_logtofile($data);
            }
        }
    }

    /**
     * Small error message wrapper for missing parameters, etc.
     *
     * @param string $message Error message.
     *
     * @return bool
     */
    protected function _error ($message) {
        print("DwollaPHP: " . $message);
        $this->_console("DwollaPHP: " . $message);
        return false;
    }

    /**
     * Parses API response out of envelope and informs user of issues if they arise.
     *
     * @param String[] $response Response body
     *
     * @return String[] Data from API
     */
    private function _dwollaparse ($response) {
        if ($response['Success'] != true) {
            $this->_console("DwollaPHP: An API error was encountered.\nServer Message:\n");
            $this->_console($response['Message']);
            if ($response['Response']) {
                $this->_console("Server Response:\n");
                $this->_console($response['Response']);
            }
            return array('Error' => $response['Message']);
        } else {
            return $response['Response'];
        }
    }


    /**
     * Returns default host URL dependent on sandbox flag.
     *
     * @return string Host
     */
    protected function _host () {
        return self::$settings->sandbox ? self::$settings->sandbox_host : self::$settings->production_host;
    }

    /**
     * Wrapper around Guzzle POST request.
     *
     * @param string $endpoint      API endpoint string
     * @param string $request       Request body. JSON encoding is optional.
     * @param bool   $customPostfix Use default REST postfix?
     * @param bool   $dwollaParse   Parse out of message envelope?
     *
     * @return String[] Response body.
     */
    protected function _post ($endpoint, $request, $customPostfix = false, $dwollaParse = true) {
        return $this->_send('POST', $endpoint, $request, $customPostfix, $dwollaParse);
    }

    /**
     * Wrapper around Guzzle PUT request.
     *
     * @param string $endpoint      API endpoint string
     * @param string $request       Request body. JSON encoding is optional.
     * @param bool   $customPostfix Use default REST postfix?
     * @param bool   $dwollaParse   Parse out of message envelope?
     *
     * @return String[] Response body.
     */
    protected function _put ($endpoint, $request, $customPostfix = false, $dwollaParse = true) {
        return $this->_send('PUT', $endpoint, $request, $customPostfix, $dwollaParse);
    }

    /**
     * Wrapper around Guzzle GET request.
     *
     * @param string   $endpoint      API endpoint string
     * @param string[] $query         Array of URLEncoded query items in key-value pairs.
     * @param bool     $customPostfix Use default REST postfix?
     * @param bool     $dwollaParse   Parse out of message envelope?
     *
     * @return string[] Response body.
     */
    protected function _get ($endpoint, $query, $customPostfix = false, $dwollaParse = true) {
        return $this->_send('GET', $endpoint, $query, $customPostfix, $dwollaParse);
    }

    /**
     * Wrapper around Guzzle DELETE request.
     *
     * @param string   $endpoint      API endpoint string
     * @param string[] $query         Array of URLEncoded query items in key-value pairs.
     * @param bool     $customPostfix Use default REST postfix?
     * @param bool     $dwollaParse   Parse out of message envelope?
     *
     * @return string[] Response body.
     */
    protected function _delete ($endpoint, $query, $customPostfix = false, $dwollaParse = true) {
        return $this->_send('DELETE', $endpoint, $query, $customPostfix, $dwollaParse);
    }

    /**
     * Wrapper around Guzzle request.
     *
     * @param string   $endpoint      API endpoint string
     * @param string[] $query         Query params.
     * @param bool     $customPostfix Use default REST postfix?
     * @param bool     $dwollaParse   Parse out of message envelope?
     *
     * @return string[] Response body.
     */
    private function _send ($method, $endpoint, $query, $customPostfix = false, $dwollaParse = true) {
        if (!empty(self::$settings->useMockResponse) && empty(self::$settings->saveMockResponse)) {
            $this->client->getEmitter()->attach($this->getMock($this->_host() . ($customPostfix ? $customPostfix : self::$settings->default_postfix) . $endpoint, $query));
        }
        if ($method == 'GET' || $method == 'DELETE') {
            $configArray = ['query' => $query];
        } else {
            $configArray = ['json' => $query];
        }
        $request = $this->client->createRequest($method, $this->_host() . ($customPostfix ? $customPostfix : self::$settings->default_postfix) . $endpoint, $configArray);
        // First, we try to catch any errors as the request "goes out the door"
        try {
            $response = $this->client->send($request, ['timeout' => 2]);
            if (self::$settings->debug) {
                $this->_console("$method Request to $endpoint\n");
                $this->_console("    " . json_encode($query));
            }
            if (!empty(self::$settings->saveMockResponse)) {
                $this->saveMock($this->_host() . ($customPostfix ? $customPostfix : self::$settings->default_postfix) . $endpoint, $query, $response->getStatusCode(), $response->getHeaders(), (string)$response->getBody());
            }
        } catch (RequestException $exception) {
            $response    = false;
            $responseRaw = '';
            if (self::$settings->debug) {
                $this->_console("DwollaPHP: An error has occurred during a $method request.\nRequest Body:\n");
                $this->_console($exception->getRequest());
                if ($exception->hasResponse()) {
                    $this->_console("Server Response:\n");
                    $this->_console($exception->getResponse());
                    $responseRaw = $exception->getResponse();
                }
            }
            if (!empty(self::$settings->saveMockResponse)) {
                $this->saveMock($this->_host() . ($customPostfix ? $customPostfix : self::$settings->default_postfix) . $endpoint, $query, $exception->getCode(), array(), (string)$responseRaw);
            }
        }
        if ($response) {
            if ($response->getBody()) {
                // If we get a response, we parse it out of the Dwolla envelope and catch API errors.
                return $dwollaParse ? $this->_dwollaparse($response->json()) : $response->json();
            }
        } else {
            if (self::$settings->debug) {
                $this->_console("DwollaPHP: An error has occurred; the response body is empty");
            }
            return null;
        }
    }

    /**
     * Constructor. Takes no arguments.
     */
    public function __construct () {

        self::$settings       = new Settings();
        self::$settings->host = self::$settings->sandbox ? self::$settings->sandbox_host : self::$settings->production_host;

        $this->settings = self::$settings;

        $p = [
            'defaults' => [
                'headers' =>
                    [
                        'Content-Type' => 'application/json',
                        'User-Agent'   => 'dwolla-php/2'
                    ],
                'timeout' => self::$settings->rest_timeout
            ]
        ];

        if (self::$settings->proxy) {
            $p['proxy'] = self::$settings->proxy;
        }

        $this->client = new Client($p);
    }

    /**
     * Getting Guzzle mock response.
     *
     * @param string   $requestUrl      Full API endpoint URL
     * @param mixed    $requestBody     Request body.
     *
     * @return Mock    Guzzle mock response.
     */
    private function getMock ($requestUrl, $requestBody) {
        if (!is_string($requestBody)) {
            $requestBody = print_r($requestBody, true);
        }
        $filename = self::$settings->mockResponsesDir . md5($requestUrl) . md5($requestBody) . '.inc';
        if (file_exists($filename)) {
            $data = null;
            require $filename;
            $data['headers'] = (array)json_decode(htmlspecialchars_decode($data['headers_json'], ENT_QUOTES));
            $mockResponse    = new Response($data['httpCode']);
            $mockResponse->setHeaders($data['headers']);
            $separator = "\r\n\r\n";
            $bodyParts = explode($separator, htmlspecialchars_decode($data['response']), ENT_QUOTES);
            if (count($bodyParts) > 1) {
                $mockResponse->setBody(Stream::factory($bodyParts[count($bodyParts) - 1]));
            } else {
                $mockResponse->setBody(Stream::factory(htmlspecialchars_decode($data['response'])));
            }
            $mock = new Mock([
                $mockResponse
            ]);
        } else {
            $mockResponse = new Response(404);
            $mock         = new Mock([$mockResponse]);
        }
        return $mock;
    }

    /**
     * Saving Guzzle mock response.
     *
     * @param string   $requestUrl      Full API endpoint URL
     * @param mixed    $requestBody     Request body.
     * @param int      $httpCode        HTTP response code.
     * @param array    $headers_source  HTTP headers array
     * @param mixed    $response        Response body
     */
    private function saveMock ($requestUrl, $requestBody, $httpCode, $headers_source, $response) {
        if (!file_exists(self::$settings->mockResponsesDir)) {
            mkdir(self::$settings->mockResponsesDir, 0777, true);
        }
        if (!is_string($requestBody)) {
            $requestBody = print_r($requestBody, true);
        }
        $headers = array();
        foreach ($headers_source as $name => $value) {
            if (is_array($value)) {
                $headers[$name] = $value[0];
            } else {
                $headers[$name] = $value;
            }
        }
        $response     = htmlspecialchars($response, ENT_QUOTES);
        $headers_json = htmlspecialchars(json_encode($headers), ENT_QUOTES);
        $data         = "<?\n\$data = array('headers_json' => '$headers_json', \n'httpCode' => $httpCode, \n'response' => '$response');";
        $filename     = self::$settings->mockResponsesDir . md5($requestUrl) . md5($requestBody) . '.inc';
        file_put_contents($filename, $data);
        if (self::$settings->debug) {
            $requestData = "<?\n\$reqdata = array('url' => '$requestUrl', \n'httpCode' => $httpCode, \n'body' => '$requestBody');";
            $filename    = self::$settings->mockResponsesDir . md5($requestUrl) . md5($requestBody) . '_req.inc';
            file_put_contents($filename, $requestData);
        }
    }

}

