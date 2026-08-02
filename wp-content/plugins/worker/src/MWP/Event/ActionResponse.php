<?php
/*
 * This file is part of the ManageWP Worker plugin.
 *
 * (c) ManageWP LLC <contact@managewp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class MWP_Event_ActionResponse extends Symfony_EventDispatcher_Event
{

    private $request;

    private $params;

    private $data;

    /**
     * @var MWP_Http_ResponseInterface
     */
    private $response;

    public function __construct(MWP_Worker_Request $request, array $params, $data)
    {
        $this->request = $request;
        $this->params  = $params;
        $this->data    = $data;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param mixed $data
     */
    public function setData($data)
    {
        $this->data = $data;
    }

    /**
     * @return MWP_Http_ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param MWP_Http_ResponseInterface|null $response
     *
     * Note: Type hint removed from $response parameter to fix PHP 8.4+ deprecation warning
     * about implicitly nullable parameters while maintaining backward compatibility with PHP 5.5+.
     * The nullable type syntax (?MWP_Http_ResponseInterface) is not supported in PHP 5.5-7.0.
     */
    public function setResponse($response = null)
    {
        $this->response = $response;
    }

    /**
     * @return MWP_Worker_Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }
}
