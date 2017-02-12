<?php
namespace DmServer\Controllers;

use DmServer\ModelHelper;
use Silex\Application;
use Silex\Application\TranslationTrait;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

abstract class AbstractController
{
    /** @var $translator TranslationTrait */
    static $translator;

    static function initTranslation($app) {
        self::$translator = $app['translator'];
    }

    /**
     * @param Application $app
     * @param string $url
     * @param string $type
     * @param array $parameters
     * @return Response
     */
    public static function callInternal(Application $app, $url, $type, $parameters = [])
    {
        if ($type === 'GET') {
            $subRequest = Request::create('/internal' . $url . (count($parameters) === 0 ? '' : '/' . implode('/', array_values($parameters))));
        }
        else {
            $subRequest = Request::create('/internal' . $url, $type, $parameters);
        }
        return $app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
    }

    /**
     * @param Application $app
     * @param string $url
     * @param string $type
     * @param array $parameterList
     * @param int $chunkSize
     * @return Response
     */
    public static function callInternalSingleParameter(Application $app, $url, $type, $parameterList, $chunkSize = 0) {
        if ($chunkSize === 0) {
            $parameterListChunks = [$parameterList];
        }
        else {
            $parameterListChunks = array_chunk($parameterList, $chunkSize);
        }
        $results = [];
        foreach($parameterListChunks as $parameterListChunk) {
            $results = array_merge(
                $results,
                ModelHelper::getUnserializedArrayFromJson(
                    self::callInternal(
                        $app, $url, $type, [implode(',', $parameterListChunk)]
                    )->getContent()
                )
            );
        }
        return $results;
    }

    /**
     * @param Application $app
     * @param string $username
     * @param $userId
     */
    protected static function setSessionUser(Application $app, $username, $userId) {
        $app['session']->set('user', ['username' => $username, 'id' => $userId]);
    }

    /**
     * @param Application $app
     * @return string
     */
    public static function getSessionUser(Application $app) {
        return $app['session']->get('user');
    }

    /**
     * @param Application $app
     * @param string $clientVersion
     */
    protected static function setClientVersion(Application $app, $clientVersion) {
        $app['session']->set('clientVersion', $clientVersion);
    }

    /**
     * @param Application $app
     * @return string
     */
    public static function getClientVersion(Application $app) {
        return $app['session']->get('clientVersion');
    }

    public function authenticateUser(Application $app, Request $request) {
        if (preg_match('#^/collection/((?!new/?).)+$#', $request->getPathInfo())) {
            $username = $request->headers->get('x-dm-user');
            $password = $request->headers->get('x-dm-pass');
            if (isset($username) && isset($password)) {
                $app['monolog']->addInfo("Authenticating $username...");

                $userCheck = self::callInternal($app, '/user/check', 'GET', [
                    'username' => $username,
                    'password' => $password
                ]);
                if ($userCheck->getStatusCode() !== Response::HTTP_OK) {
                    return new Response('', Response::HTTP_UNAUTHORIZED);
                } else {
                    $this->setSessionUser($app, $username, $userCheck->getContent());
                    $app['monolog']->addInfo("$username is logged in");
                    return true;
                }
            }
            else {
                return new Response('', Response::HTTP_UNAUTHORIZED);
            }
        }
    }

    /**
     * @param $app Application
     * @param $function callable
     * @return mixed|Response
     */
    protected static function return500ErrorOnException($app, $function) {
        try {
            return call_user_func($function);
        }
        catch (Exception $e) {
            if (isset($app['monolog'])) {
                $app['monolog']->addError($e->getMessage());
            }
            return new Response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected static function getParamAssertRegex($baseRegex, $maxOccurrences = 1) {
        if ($maxOccurrences === 1) {
            return '^'.$baseRegex.'$';
        }
        return '^('.$baseRegex.',){0,'.($maxOccurrences-1).'}'.$baseRegex.'$';
    }
}
