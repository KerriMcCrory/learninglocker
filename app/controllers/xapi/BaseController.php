<?php namespace Controllers\xAPI;

use Illuminate\Routing\Controller;
use Illuminate\Http\Response as Response;
use Illuminate\Http\JsonResponse as JsonResponse;
use Controllers\API\BaseController as APIBaseController;
use \app\locker\helpers\FailedPrecondition as FailedPrecondition;
use \app\locker\helpers\Conflict as Conflict;
use \app\locker\helpers\ValidationException as ValidationException;

class BaseController extends APIBaseController {

  // Sets constants for status codes.
  const OK = 200;
  const NO_CONTENT = 204;
  const NO_AUTH = 403;
  const CONFLICT = 409;

  // Defines properties to be set by the constructor.
  protected $params, $method, $lrs;

  /**
   * Constructs a new xAPI controller.
   */
  public function __construct() {
    $this->setMethod();
    $this->getLrs();
  }

  private function addCORSOptions($response) {
    if (in_array('Illuminate\Http\ResponseTrait', class_uses($response))) {
      $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : URL();

      $response->header(
        'Access-Control-Allow-Origin',
        $origin,
        false
      );
      $response->header(
        'Access-Control-Allow-Methods',
        'GET, PUT, POST, DELETE, OPTIONS',
        false
      );
      $response->header(
        'Access-Control-Allow-Headers',
        'Origin, Content-Type, Accept, Authorization, X-Requested-With, X-Experience-API-Version, X-Experience-API-Consistent-Through, Updated',
        false
      );
      $response->header(
        'Access-Control-Allow-Credentials',
        'true',
        false
      );
    }

    return $response;
  }

  /**
   * Selects a method to be called.
   * @return mixed Result of the method.
   */
  public function selectMethod() {
    $response = null;

    try {
      switch ($this->method) {
        case 'HEAD':
        case 'GET': $response = $this->get(); break;
        case 'PUT': $response = $this->update(); break;
        case 'POST': $response = $this->store(); break;
        case 'DELETE': $response = $this->destroy(); break;
      }
    } catch (ValidationException $e) {
      $response = self::errorResponse($e, 400);
    } catch (Conflict $e) {
      $response = self::errorResponse($e, 409);
    } catch (FailedPrecondition $e) {
      $response = self::errorResponse($e, 412);
    } catch (\Exception $e) {
      $response = self::errorResponse($e, 400);
    } finally {
      return $this->addCORSOptions($response);
    }
  }

  public function get() {
    return \LockerRequest::hasParam($this->identifier) ? $this->show() : $this->index();
  }

  /**
   * Checks the request header for correct xAPI version.
   **/
  protected function checkVersion() {
    $version = \LockerRequest::header('X-Experience-API-Version');

    if (!isset($version) || substr($version, 0, 4) !== '1.0.') {
      return $this->returnSuccessError(
        false,
        'This is not an accepted version of xAPI.',
        '400'
      );
    }
  }

  /**
   * Sets the method (to support CORS).
   */
  protected function setMethod() {
    parent::setParameters();
    $this->method = \LockerRequest::getParam(
      'method',
      \Request::server('REQUEST_METHOD')
    );
  }

  /**
   * Constructs a error response with a $message and optional $statusCode.
   * @param string $message
   * @param integer $statusCode
   */
  public static function errorResponse($e = '', $statusCode = 400) {
    $json = [
      'error' => true, // @deprecated
      'success' => false
    ];

    if ($e instanceof ValidationException) {
      $json['message'] = $e->getErrors();
    } else if ($e instanceof \Exception || $e instanceof \Locker\XApi\Errors\Error) {
      $json['message'] = $e->getMessage();
      $json['trace'] = $e->getTraceAsString();
    } else {
      $json['message'] = $e;
    }

    return \Response::json($json, $statusCode);
  }

  protected function optionalValue($name, $value, $type) {
    $decodedValue = $this->decodeValue($value);
    if (isset($decodedValue)) $this->validateValue($name, $decodedValue, $type);
    return $decodedValue;
  }

  protected function requiredValue($name, $value, $type) {
    $decodedValue = $this->decodeValue($value);
    if (isset($decodedValue)) {
      $this->validateValue($name, $decodedValue, $type);
    } else {
      throw new \Exception('Required parameter is missing - ' . $name);
    }
    return $decodedValue;
  }

  protected function validatedParam($type, $param, $default = null) {
    $paramValue = \LockerRequest::getParam($param, $default);
    $value = $this->decodeValue($paramValue);
    if (isset($value)) $this->validateValue($param, $value, $type);
    return $value;
  }

  protected function decodeValue($value) {
    $decoded = gettype($value) === 'string' ? json_decode($value, true) : $value;
    return isset($decoded) ? $decoded : $value;
  }

  protected function validateValue($name, $value, $type) {
    $validator = new \app\locker\statements\xAPIValidation();
    $validator->checkTypes($name, $value, $type, 'params');
    if ($validator->getStatus() !== 'passed') {
      throw new \Exception(implode(',', $validator->getErrors()));
    }
  }
}
