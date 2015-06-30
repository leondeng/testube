<?php

namespace LeonDeng\TesTube\Symfony;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use LeonDeng\TesTube\TestConfiguration;
use Symfony\Component\HttpFoundation\HeaderBag;
use Doctrine\Common\Inflector\Inflector;

abstract class ControllerTestCase extends WebTestCase
{
  protected function tearDown() {
    $refl = new \ReflectionObject($this);
    foreach ( $refl->getProperties() as $prop ) {
      if (! $prop->isStatic() && 0 !== strpos($prop->class, 'PHPUnit_')) {
        $prop->setAccessible(true);
        $prop->setValue($this, null);
      }
    }

    parent::tearDown();
  }

  /**
   * Recursive config handler
   *
   * @param string $key
   *          key to fetch
   * @return mixed
   */
  protected function getConfig($key = null) {
    if (! isset($GLOBALS['test_configuration']) || ! isset($GLOBALS['test_configuration'][static::getConfigPrefix()])) {
      parse_str(implode('&', array_filter($GLOBALS['argv'], function ($i) {
        return ! preg_match('/^(phpunit|-c|app|[a-zA-Z]+Test|[\-]{2}[\-a-zA-Z]+|\w+\.xml)$/', basename($i));
      })), $cli_config);
      $GLOBALS['test_configuration'] = TestConfiguration::getConfig(array (
        static::getConfigPrefix()
      ), static::getConfigPaths(), $cli_config, static::getConfigSchemas());
    }
    if (isset($GLOBALS['test_configuration'][static::getConfigPrefix()][$key])) {
      return $GLOBALS['test_configuration'][static::getConfigPrefix()][$key];
    } else {
      return array ();
    }
  }

  /**
   *
   * @see https://stackoverflow.com/questions/4440626/how-can-i-validate-regex
   * @param string $regex
   *          regex to check
   * @return boolean
   */
  protected function isValidRegex($regex) {
    return ! (@preg_match($regex, null) === false);
  }

  /**
   * Basic logging
   *
   * Environment variables:
   * - file path|php://stderr|php://stdout (default: php://stderr)
   * - verbosity 0-9 (default: 0)
   *
   * @param string $message
   * @param integer $level
   *          0-9 (0 nothing, 9 lots)
   */
  protected function log($message, $level = 1) {
    $settings = $this->getConfig('logging');

    if ($level <= $settings['verbosity']) {
      file_put_contents($settings['file'], $message . PHP_EOL, FILE_APPEND);
    }
  }

  /**
   * Generate all test data packaged for controller
   *
   * @throws Exception
   * @return array
   */
  protected function controllerAutomatedProvider() {
    $data = array();
    $env_test_regex = $this->getConfig('test_regex');
    $env_test_id = $this->getConfig('test_filter');

    if (!empty($env_test_regex) && !$this->isValidRegex((string) $env_test_regex)) {
      throw new Exception('Invalid Regex provided via test_regex config variable');
    }

    foreach ($this->getConfig('actions') as $idx => $test) {
      $test_id = $test['test_id'];

      if ((!empty($env_test_id) && $env_test_id != $test_id) || (!empty($env_test_regex) && !preg_match($env_test_regex, $test_id))) {
        continue;
      }

      // package test array for method call. sf2 config doesn't preserve array order
      foreach (array(
        'method',
        'uri',
        'parameters',
        'files',
        'server',
        'content'
      ) as $arg) {
        $data[$idx][$arg] = isset($test[$arg]) ? $test[$arg] : null;
      }

      // organise checks
      foreach (array(
        'status_code',
        'header_regexp',
        'content_type',
        'content_regexp',
        'content_decoded',
      ) as $check_ordered) {
        $data[$idx]['checks'][$check_ordered] = isset($test['checks'][$check_ordered]) ? $test['checks'][$check_ordered] : null;
      }

      $data[$idx]['test_id'] = $test['test_id'];
    }

    return $data;
  }

  /**
   * Automatically generate a set of client call to test
   *
   * @param string $method HTTP method
   * @param string $uri URI
   * @param array $parameters request parameters
   * @param array $files files posted
   * @param array $server server parameters
   * @param string $content data posted
   * @param array $checks checks to perform
   */
  protected function controllerAutomatedTest($method, $uri, $parameters, $files, $server, $content, $checks) {
    $client = static::createClient();
    $em = static::$kernel->getContainer()->get('doctrine')->getManager();

    $content = json_encode($content);
    $this->log(sprintf('Sending request data "%s"', $content), 2);

    $em->beginTransaction();
    $client->request($method, $uri, $parameters, $files, $server, $content);
    $em->rollback();

    $response = $client->getResponse();
    $statuscode = $response->getStatusCode();
    $header = $response->headers;
    $body = $response->getContent();
    $this->log(sprintf('Received response data "%s"', strval($body)), 6);

    foreach ( array_filter((array) $checks) as $check_name => $check_args ) {
      call_user_func_array(array (
        $this,
        'check' . Inflector::camelize($check_name)
      ), array (
        $statuscode,
        $header,
        $body,
        $check_args
      ));
    }
  }

  /**
   * checkStatusCode
   *
   * verifies the response code matches the configured code
   *
   * @param int $statuscode
   * @param HeaderBag $header
   * @param string $body
   * @param string $code
   */
  protected function checkStatusCode($statuscode, $header, $body, $code = 200) {
    $this->assertEquals($code, $statuscode, "HTTP Code is $code");
  }

  /**
   * @param int $statuscode
   * @param HeaderBag $header
   * @param string $body
   * @param string $regexp
   */
  protected function checkHeaderRegexp($statuscode, $header, $body, $regexp) {
    if ($regexp) {
      $this->assertRegExp($regexp, $header, 'Response body matches Regexp');
    }
  }

  /**
   * @param int $statuscode
   * @param HeaderBag $header
   * @param string $body
   * @param string $mimeType
   */
  protected function checkContentType($statuscode, $header, $body, $mimeType) {
    $message = sprintf('Content type is valid (%s)', $mimeType);

    if (in_array($mimeType, array('json', 'application/json'))) {
      $this->assertTrue(!is_null(json_decode($body)), $message);
    }
  }

  /**
   * @param int $statuscode
   * @param HeaderBag $header
   * @param string $body
   * @param string $regexp
   */
  protected function checkContentRegexp($statuscode, $header, $body, $regexp) {
    if ($regexp) {
      $this->assertRegExp($regexp, $body, 'Response body matches Regexp');
    }
  }

  /**
   * checkContentDecoded
   *
   * @param HeaderBag $header
   * @param string $body
   * @param array $matchMap
   */
  protected function checkContentDecoded($statuscode, $header, $body, $matchMap) {
    $content = json_decode($body, true);
    $flat_content = $this->flattenArray((array) $content, '.', false);

    foreach ($matchMap as $path => $value) {
      $this->assertTrue(array_key_exists($path, $flat_content), sprintf('Path "%s" exists in decoded content', $path));
      if (!is_null($value)) {
        if ($this->isValidRegex($value)) {
          $this->assertRegExp($value, $flat_content[$path], sprintf('Value "%s" of path in decoded content matches regex "%s"', $value, $flat_content[$path]));
        } else {
          $this->assertEquals($value, $flat_content[$path], sprintf('Value "%s" of path in decoded content matches text "%s"', $value, $flat_content[$path]));
        }
      } else {
        $this->assertEquals($value, $flat_content[$path], sprintf('Value "%s" of path in decoded content is null', $value));
      }
    }
  }

  /**
   * get the prefix to use for config
   *
   * @return string
   */
  protected static function getConfigPrefix() {
    throw new \Exception('Please implement getConfigPrefix as a static method in your class.');
  }

  /**
   * get the schemas to use for config
   *
   * @return array
   */
  protected static function getConfigSchemas() {
    return array ();
  }

  /**
   * get the paths to search for yml specs
   *
   * @return array<string>
   */
  protected static function getConfigPaths() {
    throw new \Exception('Please implement getConfigPaths as a static method in your class.');
  }

  /*
   * @param array $array
   * array to flatten
   * @param string $sep
   * separator
   * @param string $lowercase
   * convert to lowercase
   * @return array flattened array
   */
  protected function flattenArray(array $array, $sep = '_', $lowercase = true) {
    $result = array ();
    foreach ( $array as $k => $v ) {
      if ($lowercase) $k = strtolower($k);

      if (! is_array($v)) {
        $result[$k] = $v;
        continue;
      }

      foreach ( self::flattenArray($v, $sep, $lowercase) as $kk => $vv ) {
        if ($lowercase) $kk = strtolower($kk);
        $result[sprintf('%s%s%s', $k, $sep, $kk)] = $vv;
      }
    }

    return $result;
  }
}