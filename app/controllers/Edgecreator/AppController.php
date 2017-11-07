<?php

namespace DmServer\Controllers\Edgecreator;

use Coa\Models\BaseModel;
use DmServer\Controllers\AbstractController;
use DmServer\Controllers\UnexpectedInternalCallResponseException;
use Silex\Application;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AppController extends AbstractController
{

    /**
     * @param $routing ControllerCollection
     */
    public static function addRoutes($routing)
    {
        $routing->put(
            '/edgecreator/step/{publicationcode}/{stepnumber}',
            function (Application $app, Request $request, $publicationcode, $stepnumber) {
                $functionName = $request->request->get('functionname');
                $optionName = $request->request->get('optionname');
                $optionValue = $request->request->get('optionvalue');
                $firstIssueNumber = $request->request->get('firstissuenumber');
                $lastIssueNumber = $request->request->get('lastissuenumber');

                try {
                    $optionId = self::getResponseIdFromServiceResponse(
                        self::callInternal($app, "/edgecreator/step/$publicationcode/$stepnumber", 'PUT', [
                            'functionname' => $functionName,
                            'optionname' => $optionName
                        ]),
                        'optionid');

                    $valueId = self::getResponseIdFromServiceResponse(
                        self::callInternal($app, "/edgecreator/value", 'PUT', [
                            'optionid' => $optionId,
                            'optionvalue' => $optionValue
                        ]),
                        'valueid'
                    );

                    $intervalId = self::getResponseIdFromServiceResponse(
                        self::callInternal($app, "/edgecreator/interval/$valueId/$firstIssueNumber/$lastIssueNumber", 'PUT'),
                        'intervalid'
                    );

                    return new JsonResponse(['optionid' => $optionId, 'valueid' => $valueId, 'intervalid' => $intervalId]);
                }
                catch (UnexpectedInternalCallResponseException $e) {
                    return new Response($e->getContent(), $e->getStatusCode());
                }
            }
        )
            ->assert('publicationcode', self::getParamAssertRegex(BaseModel::PUBLICATION_CODE_VALIDATION))
            ->assert('stepnumber', self::getParamAssertRegex('\\d+'));

        $routing->get(
            '/edgecreator/v2/model',
            function (Request $request, Application $app) {
                return self::callInternal($app, "/edgecreator/v2/model", 'GET');
            }
        );

        $routing->get(
            '/edgecreator/v2/model/{modelId}',
            function (Request $request, Application $app, $modelId) {
                return self::callInternal($app, "/edgecreator/v2/model/$modelId", 'GET');
            }
        )
            ->assert('modelId', self::getParamAssertRegex('\\d+'));

        $routing->get(
            '/edgecreator/v2/model/editedbyother/all',
            function (Request $request, Application $app) {
                return self::callInternal($app, "/edgecreator/v2/model/editedbyother/all", 'GET');
            }
        );

        $routing->get(
            '/edgecreator/v2/model/unassigned/all',
            function (Request $request, Application $app) {
                return self::callInternal($app, "/edgecreator/v2/model/unassigned/all", 'GET');
            }
        );

        $routing->get(
            '/edgecreator/v2/model/{publicationcode}/{issuenumber}',
            function (Request $request, Application $app, $publicationcode, $issuenumber) {
                return self::callInternal($app, "/edgecreator/v2/model/$publicationcode/$issuenumber", 'GET');
            }
        )
            ->assert('publicationcode', self::getParamAssertRegex(BaseModel::PUBLICATION_CODE_VALIDATION))
            ->assert('issuenumber', self::getParamAssertRegex(BaseModel::ISSUE_NUMBER_VALIDATION));

        $routing->put(
            '/edgecreator/v2/model/{publicationcode}/{issuenumber}/{iseditor}',
            function (Application $app, Request $request, $publicationcode, $issuenumber, $iseditor) {
                try {
                    $modelId = self::getResponseIdFromServiceResponse(
                        self::callInternal($app, "/edgecreator/v2/model/$publicationcode/$issuenumber/$iseditor", 'PUT'),
                        'modelid'
                    );

                    return new JsonResponse(['modelid' => $modelId]);
                }
                catch (UnexpectedInternalCallResponseException $e) {
                    return new Response($e->getContent(), $e->getStatusCode());
                }
            }
        )
            ->assert('publicationcode', self::getParamAssertRegex(BaseModel::PUBLICATION_CODE_VALIDATION))
            ->assert('issuenumber', self::getParamAssertRegex(BaseModel::ISSUE_NUMBER_VALIDATION))
            ->value('iseditor', '0')
        ;

        $routing->post(
            '/edgecreator/v2/model/clone/to/{publicationcode}/{issuenumber}',
            function (Application $app, Request $request, $publicationcode, $issuenumber) {
                $steps = $request->request->get('steps');

                $targetModelId = null;
                $deletedSteps = 0;

                try {
                    // Target model already exists
                    $targetModelId = self::getResponseIdFromServiceResponse(
                        self::callInternal($app, "/edgecreator/v2/model/$publicationcode/$issuenumber", 'GET'),
                        'id'
                    );
                    try {
                        $deletedSteps = self::getResponseIdFromServiceResponse(
                            self::callInternal($app, "/edgecreator/v2/model/$targetModelId/empty", 'POST'),
                            'steps'
                        )->deleted;
                    }
                    catch(UnexpectedInternalCallResponseException $e) {
                        return new Response($e->getContent(), $e->getStatusCode());
                    }
                }
                catch(UnexpectedInternalCallResponseException $e) {
                    if ($e->getStatusCode() === Response::HTTP_NO_CONTENT) {
                        $targetModelId = self::getResponseIdFromServiceResponse(
                            self::callInternal($app, "/edgecreator/v2/model/$publicationcode/$issuenumber/1", 'PUT'),
                            'modelid'
                        );
                    }
                    else {
                        return new Response($e->getContent(), $e->getStatusCode());
                    }
                }
                finally {
                    $valueIds = [];
                    foreach($steps as $stepNumber => $stepOptions) {
                        $valueIds[$stepNumber] = self::getResponseIdFromServiceResponse(
                            self::callInternal($app, "/edgecreator/v2/step/$targetModelId/$stepNumber", 'PUT', [
                                'newFunctionName' => $stepOptions['stepfunctionname'],
                                'options' => $stepOptions['options']
                            ]),
                            'valueids'
                        );
                    }
                    return new JsonResponse([
                        'modelid' => $targetModelId,
                        'valueids' => $valueIds,
                        'deletedsteps' => $deletedSteps
                    ]);
                }
            }
        )
            ->assert('publicationcode', self::getParamAssertRegex(BaseModel::PUBLICATION_CODE_VALIDATION))
            ->assert('issuenumber', self::getParamAssertRegex(BaseModel::ISSUE_NUMBER_VALIDATION));

        $routing->post(
            '/edgecreator/v2/step/{modelid}/{stepnumber}',
            function (Application $app, Request $request, $modelid, $stepnumber) {
                $stepFunctionName = $request->request->get('stepfunctionname');
                $optionValues = $request->request->get('options');

                try {
                    $valueIds = self::getResponseIdFromServiceResponse(
                        self::callInternal($app, "/edgecreator/v2/step/$modelid/$stepnumber", 'PUT', [
                            'newFunctionName' => $stepFunctionName,
                            'options' => $optionValues
                        ]),
                        'valueids'
                    );

                    return new JsonResponse(['valueids' => $valueIds]);
                }
                catch (UnexpectedInternalCallResponseException $e) {
                    return new Response($e->getContent(), $e->getStatusCode());
                }
            }
        )
            ->assert('modelId', self::getParamAssertRegex('\\d+'))
            ->assert('stepnumber', self::getParamAssertRegex('\\d+'))
        ;

        $routing->post(
            '/edgecreator/v2/step/shift/{modelid}/{stepnumber}/{isincludingthisstep}',
            function (Application $app, Request $request, $modelid, $stepnumber, $isincludingthisstep) {
                return self::callInternal($app, "/edgecreator/step/shift/$modelid/$stepnumber/$isincludingthisstep", 'POST');
            }
        )
            ->assert('stepnumber', self::getParamAssertRegex('\\d+'));

        $routing->post(
            '/edgecreator/v2/step/clone/{modelid}/{stepnumber}/to/{newstepnumber}',
            function (Application $app, Request $request, $modelid, $stepnumber, $newstepnumber) {
                return self::callInternal($app, "/edgecreator/step/clone/$modelid/$stepnumber/$newstepnumber", 'POST');
            }
        )
        ->assert('publicationcode', self::getParamAssertRegex(BaseModel::PUBLICATION_CODE_VALIDATION))
        ->assert('stepnumber', self::getParamAssertRegex('\\d+'))
        ->assert('newstepnumber', self::getParamAssertRegex('\\d+'));

        $routing->delete(
            '/edgecreator/v2/step/{modelid}/{stepnumber}',
            function (Application $app, Request $request, $modelid, $stepnumber) {
                return self::callInternal($app, "/edgecreator/step/$modelid/$stepnumber", 'DELETE');
            }
        )
            ->assert('stepnumber', self::getParamAssertRegex('\\d+'));

        $routing->put(
            '/edgecreator/myfontspreview',
            function (Application $app, Request $request) {
                $previewId = self::getResponseIdFromServiceResponse(
                    self::callInternal($app, "/edgecreator/myfontspreview", 'PUT', [
                        'font' => $request->request->get('font'),
                        'fgColor' => $request->request->get('fgColor'),
                        'bgColor' => $request->request->get('bgColor'),
                        'width' => $request->request->get('width'),
                        'text' => $request->request->get('text'),
                        'precision' => $request->request->get('precision'),
                    ]),
                    'previewid'
                );

                return new JsonResponse(['previewid' => $previewId]);
            }
        );

        $routing->delete(
            '/edgecreator/myfontspreview/{previewid}',
            function (Application $app, Request $request, $previewid) {
                return self::callInternal($app, "/edgecreator/myfontspreview/$previewid", 'DELETE');
            }
        );

        $routing->post(
            '/edgecreator/model/v2/{modelid}/deactivate',
            function (Application $app, Request $request, $modelid) {
                return self::callInternal($app, "/edgecreator/model/v2/$modelid/deactivate", 'POST');
            }
        );

        $routing->post(
            '/edgecreator/model/v2/{modelid}/readytopublish/{isreadytopublish}',
            function (Application $app, Request $request, $modelid, $isreadytopublish) {
                return self::callInternal($app, "/edgecreator/model/v2/$modelid/readytopublish/$isreadytopublish", 'POST', [
                    'designers' => $request->request->get('designers'),
                    'photographers' => $request->request->get('photographers')
                ]);
            }
        );

        $routing->put(
            '/edgecreator/model/v2/{modelid}/photo/main',
            function (Application $app, Request $request, $modelid) {
                return self::callInternal($app, "/edgecreator/model/v2/$modelid/photo/main", 'PUT', [
                    'photoname' => $request->request->get('photoname')
                ]);
            }
        );

        $routing->get(
            '/edgecreator/model/v2/{modelid}/photo/main',
            function (Application $app, Request $request, $modelid) {
                return self::callInternal($app, "/edgecreator/model/v2/$modelid/photo/main");
            }
        );

        $routing->get(
            '/edgecreator/multiple_edge_photo/today',
            function (Request $request, Application $app) {
                return self::callInternal($app, "/edgecreator/multiple_edge_photo/today", 'GET');
            }
        );

        $routing->get(
            '/edgecreator/multiple_edge_photo/hash/{hash}',
            function (Request $request, Application $app, $hash) {
                return self::callInternal($app, "/edgecreator/multiple_edge_photo/$hash", 'GET');
            }
        );

        $routing->put(
            '/edgecreator/multiple_edge_photo',
            function (Request $request, Application $app) {
                return self::callInternal($app, "/edgecreator/multiple_edge_photo", 'PUT', [
                    'hash' => $request->request->get('hash'),
                    'filename' => $request->request->get('filename')
                ]);
            }
        );

    }
}