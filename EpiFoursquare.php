<?php
/*
 *  Class to integrate with Foursquare's API.
 *    Authenticated calls are done using OAuth and require access tokens for a user.
 *    API calls which do not require authentication do not require tokens
 * 
 *  Full documentation available on github
 *    http://wiki.github.com/jmathai/foursquare-async
 * 
 *  @author Jaisen Mathai <jaisen@jmathai.com>
 */
class EpiFoursquare extends EpiOAuth
{
  const EPIFOURSQUARE_SIGNATURE_METHOD = 'HMAC-SHA1';
  const EPIFOURSQUARE_AUTH_OAUTH = 'oauth';
  const EPIFOURSQUARE_AUTH_BASIC = 'basic';
  protected $requestTokenUrl= 'http://foursquare.com/oauth/request_token';
  protected $accessTokenUrl = 'http://foursquare.com/oauth/access_token';
  protected $authorizeUrl   = 'http://foursquare.com/oauth/authorize';
  //protected $authenticateUrl= 'http://foursquare.com/oauth/authorize'; // In case four square implements sign in with like Twitter
  protected $apiUrl         = 'http://api.foursquare.com';
  protected $userAgent      = 'EpiFoursquare (http://github.com/jmathai/foursquare-async/tree/)';
  protected $apiVersion     = 'v1';
  protected $isAsynchronous = EpiCurl::easy;

  /* OAuth methods */
  public function delete($endpoint, $params = null)
  {
    return $this->request('DELETE', $endpoint, $params);
  }

  public function get($endpoint, $params = null)
  {
    return $this->request('GET', $endpoint, $params);
  }

  public function post($endpoint, $params = null)
  {
    return $this->request('POST', $endpoint, $params);
  }

  /* Basic auth methods */
  public function delete_basic($endpoint, $params = null, $username = null, $password = null)
  {
    return $this->request_basic('DELETE', $endpoint, $params, $username, $password);
  }

  public function get_basic($endpoint, $params = null, $username = null, $password = null)
  {
    return $this->request_basic('GET', $endpoint, $params, $username, $password);
  }

  public function post_basic($endpoint, $params = null, $username = null, $password = null)
  {
    return $this->request_basic('POST', $endpoint, $params, $username, $password);
  }

  public function useApiVersion($version = null)
  {
    $this->apiVersion = $version;
  }

  public function useAsynchronous($async = true)
  {
    $this->isAsynchronous = $async ? EpiCurl::multi : EpiCurl::easy;
    $this->curl = EpiCurl::getInstance($this->isAsynchronous);
  }

  public function __construct($consumerKey = null, $consumerSecret = null, $oauthToken = null, $oauthTokenSecret = null)
  {
    parent::__construct($consumerKey, $consumerSecret, self::EPIFOURSQUARE_SIGNATURE_METHOD, $this->isAsynchronous);
    $this->setToken($oauthToken, $oauthTokenSecret);
  }

  public function __call($name, $params = null/*, $username, $password*/)
  {
    $parts  = explode('_', $name);
    $method = strtoupper(array_shift($parts));
    $parts  = implode('_', $parts);
    $endpoint   = '/' . preg_replace('/[A-Z]|[0-9]+/e', "'/'.strtolower('\\0')", $parts) . '.json';
    /* HACK: this is required for list support that starts with a user id */
    $endpoint = str_replace('//','/',$endpoint);
    $args = !empty($params) ? array_shift($params) : null;

    // calls which do not have a consumerKey are assumed to not require authentication
    if($this->consumerKey === null)
    {
      if(!empty($params))
      {
        $username = array_shift($params);
        $password = !empty($params) ? array_shift($params) : null;
      }

      return $this->request_basic($method, $endpoint, $args, $username, $password);
    }
    return $this->request($method, $endpoint, $args);
  }

  private function getApiUrl($endpoint)
  {
    if(!empty($this->apiVersion))
      return "{$this->apiUrl}/{$this->apiVersion}{$endpoint}";
    else
      return "{$this->apiUrl}{$endpoint}";
  }

  private function request($method, $endpoint, $params = null)
  {
    $url = $this->getUrl($this->getApiUrl($endpoint));
    $resp= new EpiFoursquareJson(call_user_func(array($this, 'httpRequest'), $method, $url, $params, $this->isMultipart($params)), $this->debug);
    return $resp;
  }

  private function request_basic($method, $endpoint, $params = null, $username = null, $password = null)
  {
    $url = $this->getApiUrl($endpoint);
    if($method === 'GET')
      $url .= is_null($params) ? '' : '?'.http_build_query($params, '', '&');
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeout);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if($method === 'POST' && $params !== null)
    {
      if($this->isMultipart($params))
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
      else
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->buildHttpQueryRaw($params));
    }
    if(!empty($username) && !empty($password))
      curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");

    $resp = new EpiFoursquareJson(EpiCurl::getInstance($this->isAsynchronous)->addCurl($ch), $this->debug);
    return $resp;
  }
}

class EpiFoursquareJson implements ArrayAccess, Countable, IteratorAggregate
{
  private $debug;
  private $__resp;
  public function __construct($response, $debug = false)
  {
    $this->__resp = $response;
    $this->debug  = $debug;
  }

  // ensure that calls complete by blocking for results, NOOP if already returned
  public function __destruct()
  {
    $this->responseText;
  }

  // Implementation of the IteratorAggregate::getIterator() to support foreach ($this as $...)
  public function getIterator ()
  {
    if ($this->__obj) {
      return new ArrayIterator($this->__obj);
    } else {
      return new ArrayIterator($this->response);
    }
  }

  // Implementation of Countable::count() to support count($this)
  public function count ()
  {
    return count($this->response);
  }
  
  // Next four functions are to support ArrayAccess interface
  // 1
  public function offsetSet($offset, $value) 
  {
    $this->response[$offset] = $value;
  }

  // 2
  public function offsetExists($offset) 
  {
    return isset($this->response[$offset]);
  }
  
  // 3
  public function offsetUnset($offset) 
  {
    unset($this->response[$offset]);
  }

  // 4
  public function offsetGet($offset) 
  {
    return isset($this->response[$offset]) ? $this->response[$offset] : null;
  }

  public function __get($name)
  {
    $accessible = array('responseText'=>1,'headers'=>1,'code'=>1);
    $this->responseText = $this->__resp->data;
    $this->headers      = $this->__resp->headers;
    $this->code         = $this->__resp->code;
    if(isset($accessible[$name]) && $accessible[$name])
      return $this->$name;
    elseif(($this->code < 200 || $this->code >= 400) && !isset($accessible[$name]))
      EpiFoursquareException::raise($this->__resp, $this->debug);

    // Call appears ok so we can fill in the response
    $this->response     = json_decode($this->responseText, 1);
    $this->__obj        = json_decode($this->responseText);

    if(gettype($this->__obj) === 'object')
    {
      foreach($this->__obj as $k => $v)
      {
        $this->$k = $v;
      }
    }

    if (property_exists($this, $name)) {
      return $this->$name;
    }
    return null;
  }

  public function __isset($name)
  {
    $value = self::__get($name);
    return !empty($name);
  }
}

class EpiFoursquareException extends Exception 
{
  public static function raise($response, $debug)
  {
    $message = $response->data;
 
    switch($response->code)
    {
      case 400:
        throw new EpiFoursquareBadRequestException($message, $response->code);
      case 401:
        throw new EpiFoursquareNotAuthorizedException($message, $response->code);
      case 403:
        throw new EpiFoursquareForbiddenException($message, $response->code);
      case 404:
        throw new EpiFoursquareNotFoundException($message, $response->code);
      default:
        throw new EpiFoursquareException($message, $response->code);
    }
  }
}
class EpiFoursquareBadRequestException extends EpiFoursquareException{}
class EpiFoursquareNotAuthorizedException extends EpiFoursquareException{}
class EpiFoursquareForbiddenException extends EpiFoursquareException{}
class EpiFoursquareNotFoundException extends EpiFoursquareException{}
