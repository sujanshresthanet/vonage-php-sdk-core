<?php

namespace Nexmo\Account;

use Nexmo\Client\Exception;
use Nexmo\Client\APIResource;
use Nexmo\Client\ClientAwareTrait;
use Nexmo\Client\ClientAwareInterface;
use Nexmo\Client\Exception\Request as ExceptionRequest;
use Nexmo\Client\Exception\Validation;
use Nexmo\Entity\KeyValueFilter;

/**
 * @todo Unify the exception handling to avoid duplicated code and logic (ie: getPrefixPricing())
 */
class Client implements ClientAwareInterface
{
    /**
     * @deprecated This object will be dropping support for ClientAwareInterface in the future
     */
    use ClientAwareTrait;

    /**
     * APIResource
     */
    protected $api;

    public function __construct(?APIResource $api = null)
    {
        $this->api = $api;
    }

    /**
     * Shim to handle older instatiations of this class
     * @deprecated Will remove in v3
     */
    protected function getApiResource() : APIResource
    {
        if (is_null($this->api)) {
            $api = new APIResource();
            $api->setClient($this->getClient())
                ->setBaseUrl($this->getClient()->getRestUrl())
                ->setIsHAL(false)
                ->setBaseUri('/account')
                ->setCollectionName('')
            ;
            $this->api = $api;
        }
        return clone $this->api;
    }

    /**
     * Returns pricing based on the prefix requested
     */
    public function getPrefixPricing($prefix) : array
    {
        $api = $this->getApiResource();
        $api->setBaseUri('/account/get-prefix-pricing/outbound');
        $api->setCollectionName('prices');

        $data = $api->search(new KeyValueFilter(['prefix' => $prefix]));

        if (count($data) == 0) {
            return [];
        }

        // Multiple countries can match each prefix
        $prices = [];

        foreach ($data as $p) {
            $prefixPrice = new PrefixPrice();
            $prefixPrice->jsonUnserialize($p);
            $prices[] = $prefixPrice;
        }

        return $prices;
    }

    /**
     * Get SMS Pricing based on Country
     */
    public function getSmsPrice(string $country) : SmsPrice
    {
        $body = $this->makePricingRequest($country, 'sms');
        $smsPrice = new SmsPrice();
        $smsPrice->jsonUnserialize($body);
        return $smsPrice;
    }

    /**
     * Get Voice pricing based on Country
     */
    public function getVoicePrice(string $country) : VoicePrice
    {
        $body = $this->makePricingRequest($country, 'voice');
        $voicePrice = new VoicePrice();
        $voicePrice->jsonUnserialize($body);
        return $voicePrice;
    }

    /**
     * @todo This should return an empty result instead of throwing an Exception on no results
     */
    protected function makePricingRequest($country, $pricingType) : array
    {
        $api = $this->getApiResource();
        $api->setBaseUri('/account/get-pricing/outbound/' . $pricingType);
        $results = $api->search(new KeyValueFilter(['country' => $country]));
        $data = $results->getPageData();

        if (is_null($data)) {
            throw new Exception\Server('No results found');
        }

        return $data;
    }

    /**
     * Gets the accounts current balance in Euros
     *
     * @todo This needs further investigated to see if '' can even be returned from this endpoint
     */
    public function getBalance() : Balance
    {
        $data = $this->getApiResource()->get('get-balance');
        
        if (is_null($data)) {
            throw new Exception\Server('No results found');
        }

        $balance = new Balance($data['value'], $data['autoReload']);
        return $balance;
    }

    public function topUp($trx) : void
    {
        $api = $this->getApiResource();
        $api->setBaseUri('/account/top-up');
        $api->submit(['trx' => $trx]);
    }

    /**
     * Return the account settings
     */
    public function getConfig() : Config
    {
        $api = $this->getApiResource();
        $api->setBaseUri('/account/settings');
        $body = $api->submit();

        if ($body === '') {
            throw new Exception\Server('Response was empty');
        }

        $body = json_decode($body, true);

        $config = new Config(
            $body['mo-callback-url'],
            $body['dr-callback-url'],
            $body['max-outbound-request'],
            $body['max-inbound-request'],
            $body['max-calls-per-second']
        );
        return $config;
    }

    /**
     * Update account config
     */
    public function updateConfig($options) : Config
    {
        // supported options are SMS Callback and DR Callback
        $params = [];
        if (isset($options['sms_callback_url'])) {
            $params['moCallBackUrl'] = $options['sms_callback_url'];
        }

        if (isset($options['dr_callback_url'])) {
            $params['drCallBackUrl'] = $options['dr_callback_url'];
        }

        $api = $this->getApiResource();
        $api->setBaseUri('/account/settings');

        $rawBody = $api->submit($params);

        if ($rawBody === '') {
            throw new Exception\Server('Response was empty');
        }

        $body = json_decode($rawBody, true);

        $config = new Config(
            $body['mo-callback-url'],
            $body['dr-callback-url'],
            $body['max-outbound-request'],
            $body['max-inbound-request'],
            $body['max-calls-per-second']
        );
        return $config;
    }

    public function listSecrets(string $apiKey) : SecretCollection
    {
        $api = $this->getApiResource();
        $api->setBaseUrl($this->getClient()->getApiUrl());
        $api->setBaseUri('/accounts');
        
        $data = $api->get($apiKey . '/secrets');
        return @SecretCollection::fromApi($data);
    }

    public function getSecret(string $apiKey, string $secretId) : Secret
    {
        $api = $this->getApiResource();
        $api->setBaseUrl($this->getClient()->getApiUrl());
        $api->setBaseUri('/accounts');

        $data = $api->get($apiKey . '/secrets/' . $secretId);
        return @Secret::fromApi($data);
    }

    /**
     * Create a new account secret
     */
    public function createSecret(string $apiKey, string $newSecret) : Secret
    {
        $api = $this->getApiResource();
        $api->setBaseUrl($this->getClient()->getApiUrl());
        $api->setBaseUri('/accounts/' . $apiKey . '/secrets');

        try {
            $response = $api->create(['secret' => $newSecret]);
        } catch (ExceptionRequest $e) {
            // @deprectated Throw a Validation exception to preserve old behavior
            // This will change to a general Request exception in the future
            $rawResponse = json_decode($e->getResponse()->getBody()->getContents(), true);
            if (array_key_exists('invalid_parameters', $rawResponse)) {
                throw new Validation($e->getMessage(), $e->getCode(), null, $rawResponse['invalid_parameters']);
            }
            throw $e;
        }

        return @Secret::fromApi($response);
    }

    public function deleteSecret(string $apiKey, string $secretId) : void
    {
        $api = $this->getApiResource();
        $api->setBaseUrl($this->getClient()->getApiUrl());
        $api->setBaseUri('/accounts/' . $apiKey . '/secrets');
        $api->delete($secretId);
    }
}
