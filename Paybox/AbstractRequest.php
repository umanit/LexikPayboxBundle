<?php

namespace Lexik\Bundle\PayboxBundle\Paybox;

use Lexik\Bundle\PayboxBundle\Paybox\System\Tools;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Class AbstractRequest
 *
 * @package Lexik\Bundle\PayboxBundle\Paybox
 *
 * @author Lexik <dev@lexik.fr>
 * @author Olivier Maisonneuve <o.maisonneuve@lexik.fr>
 */
abstract class AbstractRequest implements RequestInterface
{
    /**
     * Context.
     *
     * @var string
     */
    protected $context;

    /**
     * Array of the transaction's raw parameters.
     *
     * @var array
     */
    protected $rawParameters;

    /**
     * Array of the transaction's parameters.
     *
     * @var array
     */
    protected $parameters;

    /**
     * Array of globals parameters.
     *
     * @var array
     */
    protected $globals;

    /**
     * Array of servers informations.
     *
     * @var array
     */
    protected $servers;

    /**
     * Constructor.
     *
     * @param  array $parameters
     * @param  array $servers
     */
    public function __construct(array $parameters, array $servers)
    {
        $this->context       = null;
        $this->rawParameters = $parameters;
        $this->parameters    = array();
        $this->globals       = array();
        $this->servers       = $servers;
    }

    /**
     * Initialize the object with the defaults values.
     *
     * @param array $parameters
     */
    abstract protected function initGlobals(array $parameters);

    /**
     * Initialize defaults parameters with globals.
     */
    abstract protected function initParameters();

    /**
     * Sets the context that defines globals and parameters.
     *
     * This must be called when implementing Paybox.
     *
     * @param  string $context
     *
     * @return RequestInterface
     */
    public function setContext($context)
    {
        $rawParameters = $this->getRawParameters();

        if ($context === null) {
            throw new \Exception('Request context is undefined.');
        } elseif (!isset($rawParameters[$context])) {
            throw new \Exception(sprintf('Request context %s is not configured.', $context));
        }

        $this->context = $context;

        // Context has changed, reload globals and parameters
        $this->initGlobals($rawParameters);
        $this->initParameters();

        return $this;
    }

    /**
     * Returns the context.
     *
     * @return RequestInterface
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Sets a bunch of raw parameters.
     *
     * @param  array $rawParameters
     *
     * @return RequestInterface
     */
    public function setRawParameters($rawParameters)
    {
        $this->rawParameters = $rawParameters;

        return $this;
    }

    /**
     * Returns all raw parameters set for a payment.
     *
     * @return array
     */
    public function getRawParameters()
    {
        return $this->rawParameters;
    }

    /**
     * {@inheritdoc}
     */
    public function setParameter($name, $value)
    {
        $this->parameters[$name] = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setParameters(array $parameters)
    {
        foreach ($parameters as $name => $value) {
            $this->setParameter($name, $value);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getParameter($name)
    {
        return (isset($this->parameters[strtoupper($name)])) ? $this->parameters[strtoupper($name)] : null;
    }

    /**
     * Returns all parameters as a querystring.
     *
     * @return string
     */
    protected function stringifyParameters()
    {
        if (isset($this->parameters['PBX_HMAC'])) {
            unset($this->parameters['PBX_HMAC']);
        }

        ksort($this->parameters);

        return Tools::stringify($this->parameters);
    }

    /**
     * Computes the hmac hash.
     *
     * @return string
     */
    protected function computeHmac()
    {
        $binKey = pack('H*', $this->globals['hmac_key']);

        return hash_hmac($this->globals['hmac_algorithm'], $this->stringifyParameters(), $binKey);
    }

    /**
     * Returns the url of an available server.
     *
     * @return array
     *
     * @throws InvalidArgumentException If the specified environment is not valid (dev/prod).
     * @throws RuntimeException         If no server is available.
     */
    protected function getServer()
    {
        $servers = array();

        if (isset($this->globals['production']) && (true === $this->globals['production'])) {
            $servers[] = $this->servers['primary'];
            $servers[] = $this->servers['secondary'];
        } else {
            $servers[] = $this->servers['preprod'];
        }

        foreach ($servers as $server) {
            $doc = new \DOMDocument();
            $doc->loadHTML($this->getWebPage(sprintf(
                '%s://%s%s',
                $server['protocol'],
                $server['host'],
                $server['test_path']
            )));
            $element = $doc->getElementById('server_status');

            if ($element && 'OK' == $element->textContent) {
                return $server;
            }
        }

        throw new RuntimeException('No server available.');
    }

    /**
     * Returns the content of a web resource.
     *
     * @param  string $url
     *
     * @return string
     */
    protected function getWebPage($url)
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL,            $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER,         false);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        $output = curl_exec($curl);
        curl_close($curl);

        return (string) $output;
    }
}
