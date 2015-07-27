<?php


namespace microinginer\CbRFRates;


use yii\base\Component;
use yii\base\Exception;

/**
 * Class CBRF
 * @author Ruslan Madatov <ruslanmadatov@yandex.ru>
 * @package microinginer\CbRFRates
 */
class CBRF extends Component
{
    /**
     * @var string
     */
    public $defaultCurrency = "USD";
    /**
     * @var bool
     */
    public $cached = true;
    /**
     * @var string
     */
    public $cachedId = 'CBRF_CACHE_das7878das8da8asd';
    /**
     * @var int
     */
    public $cacheDuration = 86400;
    /**
     * @var string
     */
    private $url = "http://www.cbr.ru/scripts/XML_daily.asp";
    /**
     * @var array
     */
    private $allCurrency = [];
    /**
     * @var string
     */
    private $filter = '';
    /**
     * @var array
     */
    private $filterCurrency = [];
    /**
     * @var bool
     */
    private $shortFormat = false;

    /**
     * Initializes the object.
     * This method is invoked at the end of the constructor after the object is initialized with the
     * given configuration.
     */
    public function init ()
    {
        $this->getDataFromUrl();
    }

    /**
     * @param int $duration
     * @return $this
     */
    public function cache ($duration = 3600)
    {
        $this->cached = true;
        $this->cacheDuration = $duration;

        return $this;
    }

    /**
     * @return array
     * @throws CBRFException
     */
    public function all ()
    {
        $this->getDataFromUrl();

        return $this->allCurrency;
    }

    /**
     * @param string $currency
     * @return array|mixed
     * @throws CBRFException
     */
    public function one ($currency = 'default')
    {
        $this->getDataFromUrl();

        $key = ($currency == 'default' ? $this->defaultCurrency : $currency);

        if ($currency == 'default' && empty($this->allCurrency[ $key ])) {
            $this->allCurrency = array_shift($this->allCurrency);
        } else {
            $this->allCurrency = $this->allCurrency[ $key ];
        }

        return $this->allCurrency;
    }

    /**
     * @param array $params
     * @return $this
     */
    public function filter (array $params)
    {
        $params = array_merge([
            'date'     => '',
            'currency' => '',
        ], $params);

        if ($params['date']) {
            if (!is_numeric($params['date'])) {
                $params['date'] = strtotime($params['date']);
            }
            $this->filter .= "&date_req=" . date("d/m/Y", $params['date']);
        }

        if ($params['currency']) {
            $this->filterCurrency = array_flip(array_map(function ($val) { return strtoupper(trim($val)); }, explode(',', $params['currency'])));
        }

        $this->setCacheId($this->filter);

        return $this;
    }

    /**
     * @return $this
     */
    public function short ()
    {
        $this->shortFormat = true;

        return $this;
    }

    /**
     * @throws CBRFException
     */
    private function getDataFromUrl ()
    {
        if (!$result = \Yii::$app->cache->get($this->cachedId)) {
            if (function_exists("curl_init")) {
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $this->url . "?1=1" . $this->filter);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $out = curl_exec($curl);
                curl_close($curl);

                $result = $out;
            } else {
                $result = file_get_contents($this->url);
            }

            if ($this->cached) {
                \Yii::$app->cache->set($this->cachedId, $result, $this->cacheDuration);
            }
        }

        $xml = simplexml_load_string($result);

        if (!$xml) throw new CBRFException("Not correct XML");

        foreach ($xml->Valute as $val) {
            $value = str_replace(',', '.', $val->Value) / $val->Nominal;
            if (!$this->shortFormat) {
                $this->allCurrency[ current($val->CharCode) ] = [
                    'name'      => current($val->Name),
                    'value'     => $value,
                    'char_code' => current($val->CharCode),
                    'num_code'  => current($val->NumCode),
                    'nominal'   => current($val->Nominal),
                ];
            } else {
                $this->allCurrency[ current($val->CharCode) ] = $value;
            }
        }

        if (empty($this->allCurrency)) throw new CBRFException('No loaded data');

        if ($this->filterCurrency) {
            $this->allCurrency = array_intersect_key($this->allCurrency, $this->filterCurrency);
        }
    }

    /**
     * @param $cacheId
     */
    private function setCacheId ($cacheId)
    {
        $this->cachedId .= md5($cacheId);
    }
}

/**
 * Class CBRFException
 * @package app\components
 */
class CBRFException extends Exception
{
}