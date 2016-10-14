<?php

namespace microinginer\CbRFRates;

use yii\base\Component;
use yii\base\Exception;
use yii\caching\DummyCache;

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
    public $cached          = false;

    /**
     * @var string
     */
    public $cachedId        = 'CBRF_CACHE_das7878das8da8asd';

    /**
     * @var int
     */
    public $cacheDuration   = 3600;

    /**
     * @var $cache \yii\caching\Cache
     */
    private $cache          = null;

    /**
     * @var string
     */
    private $url            = "http://www.cbr.ru/scripts/XML_daily.asp";

    /**
     * @var string
     */
    private $urlDynamic     = "http://www.cbr.ru/scripts/XML_dynamic.asp?1=1";

    /**
     * @var array
     */
    private $allCurrency    = [];

    /**
     * @var string
     */
    private $filter         = '';

    /**
     * @var array
     */
    private $filterCurrency = [];

    /**
     * @var bool
     */
    private $shortFormat    = false;

    /**
     * @var bool
     */
    private $withDynamic = false;

    /**
     * @var array
     */
    private $dynamicParams = [];

    /**
     * Initializes the object.
     * This method is invoked at the end of the constructor after the object is initialized with the
     * given configuration.
     */
    public function init()
    {
        if ($this->cached && empty(\Yii::$app->cache)) {
            throw new CBRFException("Cache component not found! Please check your config file!");
        }

        $this->cache = (!empty(\Yii::$app->cache) ? \Yii::$app->cache : new DummyCache());
    }

    /**
     * @param int $duration
     * @return $this
     * @throws CBRFException
     */
    public function cache($duration = 3600)
    {
        $this->cached        = true;
        $this->cacheDuration = $duration;

        if (empty(\Yii::$app->cache)) {
            throw new CBRFException("Cache component not found! Please check your config file!");
        }

        return $this;
    }

    /**
     * @return array
     * @throws CBRFException
     */
    public function all()
    {
        $this->getRates();

        return $this->allCurrency;
    }

    /**
     * @param string $currency
     * @return array|mixed
     * @throws CBRFException
     */
    public function one($currency = 'default')
    {
        $this->getRates();

        $key = ($currency == 'default' ? $this->defaultCurrency : $currency);

        if ($currency == 'default' && empty($this->allCurrency[$key])) {
            $this->allCurrency = array_shift($this->allCurrency);
        } else {
            $this->allCurrency = $this->allCurrency[$key];
        }

        return $this->allCurrency;
    }

    /**
     * @param array $params
     * @return $this
     */
    public function filter(array $params)
    {
        $params = array_merge([
            'date' => '',
            'currency' => '',
            ], $params);

        if ($params['date']) {
            if (!is_numeric($params['date'])) {
                $params['date'] = strtotime($params['date']);
            }
            $this->filter .= "&date_req=".date("d/m/Y", $params['date']);
        }

        if ($params['currency']) {
            if (!is_array($params['currency'])) {
                $params['currency'] = explode(',', $params['currency']);
            }
            $this->filterCurrency = array_flip(array_map(function ($val) {
                    return strtoupper(trim($val));
                }, $params['currency']));
        }

        $this->setCacheId($this->filter);

        return $this;
    }

    /**
     * @return $this
     */
    public function short()
    {
        $this->shortFormat = true;

        return $this;
    }

    /**
     * @param array $params
     * @return array
     * @throws CBRFException
     */
    public function dynamic(array $params)
    {
        $params = array_merge([
            'id' => 0,
            'date_from' => time() - (86400 * 7),
            'date_to' => time(),
            ], $params, $this->dynamicParams);

        if (!is_numeric($params['date_from'])) {
            $params['date_from'] = strtotime($params['date_from']);
        }

        if (!is_numeric($params['date_to'])) {
            $params['date_to'] = strtotime($params['date_to']);
        }

        $params['date_req1'] = date('d/m/Y', $params['date_from']);
        $params['date_req2'] = date('d/m/Y', $params['date_to']);
        $params['VAL_NM_RQ'] = $params['id'];

        unset($params['date_to'], $params['date_from'], $params['id']);
        $urlWithParams = $this->urlDynamic."&".http_build_query($params);

        $xml  = $this->getHttpRequest($urlWithParams);
        $data = [];
        foreach ($xml->Record as $record) {
            $attribute = $record->attributes();
            $data[]    = [
                'date' => strtotime(current($attribute)['Date']),
                'value' => str_replace(',', '.', current($record->Value)),
            ];
        }

        return $data;
    }

    /**
     * @param array $params
     * @return $this
     */
    public function withDynamic(array $params)
    {
        $this->withDynamic   = true;
        $this->dynamicParams = $params;

        return $this;
    }

    /**
     * @param $cacheId
     */
    private function setCacheId($cacheId)
    {
        $this->cachedId = md5($cacheId);
    }

    /**
     * @throws CBRFException
     */
    private function getRates()
    {
        $urlWithParams = $this->url."?1=1".$this->filter;

        $xml = $this->getHttpRequest($urlWithParams);

        if (!$xml) throw new CBRFException("Not correct XML");

        foreach ($xml->Valute as $val) {
            $attr  = $val->attributes();
            $value = str_replace(',', '.', $val->Value) / $val->Nominal;
            if (!$this->shortFormat) {
                $id                                           = current($attr)['ID'];
                $charCode                                     = current($val->CharCode);
                $this->allCurrency[current($val->CharCode)] = [
                    'name' => current($val->Name),
                    'value' => $value,
                    'char_code' => $charCode,
                    'num_code' => current($val->NumCode),
                    'nominal' => current($val->Nominal),
                    'id' => $id,
                ];

                if ($this->withDynamic && (!$this->filterCurrency || array_key_exists($charCode,
                        $this->filterCurrency))) {
                    $this->allCurrency[current($val->CharCode)]['dynamic'] = $this->dynamic([
                        'id' => $id
                    ]);
                }
            } else {
                $this->allCurrency[current($val->CharCode)] = $value;
            }
        }

        if (empty($this->allCurrency))
                throw new CBRFException('No loaded data');

        if ($this->filterCurrency) {
            $this->allCurrency = array_intersect_key($this->allCurrency,
                $this->filterCurrency);
        }
    }

    /**
     * @param $url
     * @return \SimpleXMLElement
     * @throws CBRFException
     */
    private function getHttpRequest($url)
    {
        $this->setCacheId($url);
        $result = $this->cache->get($this->cachedId);

        if (empty($result)) {
            if (function_exists("curl_init")) {
                $curl   = curl_init();
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $result = curl_exec($curl);
                curl_close($curl);
            } else {
                $result = file_get_contents($url);
            }

            if ($this->cached) {
                $this->cache->set($this->cachedId, $result, $this->cacheDuration);
            }
        }
        $xml = simplexml_load_string($result);

        if (!$xml) {
            throw new CBRFException("getHttpRequest is broken");
        }

        return $xml;
    }

    /**
     * 
     * @param string $date
     * @param string $fromCur Currency code. Convert from currency.
     * @param string $toCur Currency code. Convert to currency.
     * @param float $amount Amount for convertin
     * @param int $precision rounding precision
     * @return float
     * @throws CBRFException
     */
    public function convert($date, $fromCur, $toCur, $amount, $precision = 2)
    {
        $rates = $this->filter(['date' => $date, 'currency' => [$fromCur, $toCur]])->short()->all();

        if ($fromCur == CBRFDef::C_RUR || $toCur == CBRFDef::C_RUR) {
            $rates[CBRFDef::C_RUR] = 1;
        }

        if (!isset($rates[$fromCur]) || !$rates[$fromCur]) {
            throw new CBRFException("Currency ".$fromCur." do not have rate on ".$date);
        }
        if (!isset($rates[$toCur]) || !$rates[$toCur]) {
            throw new CBRFException("Currency ".$toCur." do not have rate on ".$date);
        }

        return round($amount * $rates[$fromCur] / $rates[$toCur], $precision);
    }
}

/**
 * Class CBRFException
 * @package app\components
 */
class CBRFException extends Exception
{

}

/**
 * Class CBRFDef
 */
class CBRFDef
{
    /** Австралийский доллар */
    const C_AUD = 'AUD';

    /** Азербайджанский манат */
    const C_AZN = 'AZN';

    /** Фунт стерлингов Соединенного королевства */
    const C_GBP = 'GBP';

    /** Армянских драмов */
    const C_AMD = 'AMD';

    /** Белорусский рубль */
    const C_BYN = 'BYN';

    /** Болгарский лев */
    const C_BGN = 'BGN';

    /** Бразильский реал */
    const C_BRL = 'BRL';

    /** Венгерских форинтов */
    const C_HUF = 'HUF';

    /** Датских крон */
    const C_DKK = 'DKK';

    /** Доллар США */
    const C_USD = 'USD';

    /** Евро */
    const C_EUR = 'EUR';

    /** Индийских рупий */
    const C_INR = 'INR';

    /** Казахстанских тенге */
    const C_KZT = 'KZT';

    /** Канадский доллар */
    const C_CAD = 'CAD';

    /** Киргизских сомов */
    const C_KGS = 'KGS';

    /** Китайских юаней */
    const C_CNY = 'CNY';

    /** Молдавских леев */
    const C_MDL = 'MDL';

    /** Норвежских крон */
    const C_NOK = 'NOK';

    /** Польский злотый */
    const C_PLN = 'PLN';

    /** Румынский лей */
    const C_RON = 'RON';

    /** СДР (специальные права заимствования) */
    const C_XDR = 'XDR';

    /** Сингапурский доллар */
    const C_SGD = 'SGD';

    /** Таджикских сомони */
    const C_TJS = 'TJS';

    /** Турецкая лира */
    const C_TRY = 'TRY';

    /** Новый туркменский манат */
    const C_TMT = 'TMT';

    /** Узбекских сумов */
    const C_UZS = 'UZS';

    /** Украинских гривен */
    const C_UAH = 'UAH';

    /** Чешских крон */
    const C_CZK = 'CZK';

    /** Шведских крон */
    const C_SEK = 'SEK';

    /** Швейцарский франк */
    const C_CHF = 'CHF';

    /** Южноафриканских рэндов */
    const C_ZAR = 'ZAR';

    /** Вон Республики Корея */
    const C_KRW = 'KRW';

    /** Японских иен */
    const C_JPY = 'JPY';

    /** Krievu rubulis */
    const C_RUR = 'RUR';

}