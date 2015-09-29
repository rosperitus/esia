<?php
namespace esia;

/**
 * Class OpenId
 * @package esia
 */
class OpenId
{

    public $clientId;
    public $redirectUrl;

    /**
     * @var callable|null
     */
    public $log = null;
    public $portalUrl = 'https://esia-portal1.test.gosuslugi.ru/';
    public $tokenUrl = 'aas/oauth2/te';
    public $codeUrl = 'aas/oauth2/ac';
    public $personUrl = 'rs/prns';
    public $privateKeyPath;
    public $privateKeyPassword;
    public $certPath;

    protected $scope = 'http://esia.gosuslugi.ru/usr_inf';

    protected $clientSecret = null;
    protected $responseType = 'code';
    protected $state = null;
    protected $timestamp = null;
    protected $accessType = 'offline';
    protected $tmpPath;

    private $url = null;
    private $token = null;
    private $oid = null;

    public function __construct(array $config = [])
    {
        foreach ($config as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
    }


    /**
     * @return null|string
     */
    public function getUrl()
    {
        $this->timestamp = $this->getTimeStamp();
        $this->state = $this->getState();
        $this->clientSecret = $this->scope . $this->timestamp . $this->clientId . $this->state;
        $this->clientSecret = $this->signPKCS7($this->clientSecret);

        $url = $this->getCodeUrl() . '?%s';

        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUrl,
            'scope' => $this->scope,
            'response_type' => $this->responseType,
            'state' => $this->state,
            'access_type' => $this->accessType,
            'timestamp' => $this->timestamp,
        ];

        $request = http_build_query($params);

        $this->url = sprintf($url, $request);


        return $this->url;
    }

    /**
     * @return string
     */
    public function getTokenUrl()
    {
        return $this->portalUrl . $this->tokenUrl;
    }

    /**
     * @return string
     */
    public function getCodeUrl()
    {
        return $this->portalUrl . $this->codeUrl;
    }

    /**
     * @return string
     */
    public function getPersonUrl()
    {
        return $this->portalUrl . $this->personUrl;
    }


    /**
     * @param $code
     * @return null
     */
    public function getToken($code)
    {
        $this->timestamp = $this->getTimeStamp();
        $this->state = $this->getState();

        $clientSecret = $this->signPKCS7($this->scope . $this->timestamp . $this->clientId . $this->state);

        $request = [
            'client_id' => $this->clientId,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_secret' => $clientSecret,
            'state' => $this->state,
            'redirect_uri' => $this->redirectUrl,
            'scope' => $this->scope,
            'timestamp' => $this->timestamp,
            'token_type' => 'Bearer',
            'refresh_token' => $this->state,
        ];


        $c = curl_init();

        $curlOpt = [
            CURLOPT_URL => $this->getTokenUrl(),
            CURLOPT_POSTFIELDS => http_build_query($request),
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
        ];


        curl_setopt_array($c, $curlOpt);


        $result = json_decode(curl_exec($c));

        $this->writeLog(print_r($result, true));

        $this->token = $result->access_token;

        # get object id from token
        $chunks = explode('.', $this->token);
        $payload = json_decode($this->base64urlSafeDecode($chunks[1]));
        $this->oid = $payload->{'urn:esia:sbj_id'};

        $this->writeLog(var_export($payload, true));

        return $this->token;

    }


    /**
     * @param $message
     * @return bool|mixed
     */
    public function signPKCS7($message)
    {
        $certContent = file_get_contents($this->certPath);
        $keyContent = file_get_contents($this->privateKeyPath);

        $cert = openssl_x509_read($certContent);
        $this->writeLog('Cert: ' . print_r($cert, true));

        $privateKey = openssl_pkey_get_private($keyContent, $this->privateKeyPassword);
        $this->writeLog('Private key: : ' . print_r($privateKey, true));

        $messageFile = $this->tmpPath . DIRECTORY_SEPARATOR . uniqid();
        $signFile = $this->tmpPath . DIRECTORY_SEPARATOR . uniqid();
        file_put_contents($messageFile, $message);

        $signResult = openssl_pkcs7_sign(
            $messageFile,
            $signFile,
            $cert,
            $privateKey, []);

        if ($signResult) {
            $this->writeLog('Sign success');
        } else {
            $this->writeLog('Sign fail');
            return false;
        }

        $signed = file_get_contents($signFile);

        # split by section
        $signed = explode("\n\n", $signed);

        # get third section which contains sign and join into one line
        $sign = str_replace("\n", "", $this->urlSafe($signed[3]));

        unlink($signFile);
        unlink($messageFile);

        return $sign;

    }

    /**
     * @throws \Exception
     */
    public function getPersonInfo()
    {
        $url = $this->personUrl . '/' . $this->oid;

        $request = $this->buildRequest();
        return $request->call($url);

    }

    /**
     * @throws \Exception
     */
    public function getContactInfo()
    {

        $url = $this->personUrl . '/' . $this->oid . '/ctts';

        $request = $this->buildRequest();

        $result = $request->call($url);

        if ($result) {

            if ($result->size > 0) {
                $contacts = [];
                foreach ($result->elements as $element) {

                    $request = $this->buildRequest();
                    $contact = $request->call($element, true);

                    if ($contact) {
                        array_push($contacts, $contact);
                    }


                }

                return $contacts;
            }
        }

        return $result;

    }

    /**
     * @return Request
     * @throws \Exception
     */
    protected function buildRequest()
    {
        if (!$this->token) {
            throw new \Exception('Access token is empty');
        }

        return new Request($this->portalUrl, $this->token);

    }

    /**
     * @return bool|string
     */
    private function getTimeStamp()
    {
        return date("Y.m.d H:i:s O");
    }


    /**
     * Generate state with uuid
     *
     * @return string
     */
    private function getState()
    {

        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Url safe for base64
     *
     * @param $string
     * @return string
     */
    private function urlSafe($string)
    {
        return rtrim(strtr(trim($string), '+/', '-_'), '=');
    }


    /**
     * Url safe for base64
     *
     * @param $string
     * @return string
     */
    private function base64urlSafeDecode($string)
    {
        $base64 = strtr($string, '-_', '+/');

        return base64_decode($base64);
    }

    /**
     * Write log
     *
     * @param $message
     */
    private function writeLog($message)
    {
        $log = $this->log;

        if (is_callable($log)) {
            $log($message);
        }
    }
}

