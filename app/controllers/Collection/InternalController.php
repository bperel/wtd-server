<?php

namespace DmServer\Controllers\Collection;

use Dm\Contracts\Results\UpdateCollectionResult;
use Dm\Models\Achats;
use Dm\Models\BibliothequeAccesExternes;
use Dm\Models\BibliothequeOrdreMagazines;
use Dm\Models\Numeros;
use DmServer\Controllers\AbstractController;
use DmServer\DmServer;
use DmServer\MiscUtil;
use DmServer\ModelHelper;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\OrderBy;
use Silex\Application;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use DDesrosiers\SilexAnnotations\Annotations as SLX;

/**
 * @SLX\Controller(prefix="/internal/collection")
 */
class InternalController extends AbstractController
{
    protected static function wrapInternalService($app, $function) {
        return parent::returnErrorOnException($app, DmServer::CONFIG_DB_KEY_DM, $function);
    }

    /**
     * @SLX\Route(
     *     @SLX\Request(method="GET", uri="issues"),
     * )
     * @param Application $app
     * @return JsonResponse
     */
    public function listIssues(Application $app) {
        return self::wrapInternalService($app, function(EntityManager $dmEm) use ($app) {
            /** @var Numeros[] $issues */
            $issues = $dmEm->getRepository(Numeros::class)->findBy(
                ['idUtilisateur' => self::getSessionUser($app)['id']],
                ['pays' => 'asc', 'magazine' => 'asc', 'numero' => 'asc']
            );

            return new JsonResponse(ModelHelper::getSerializedArray($issues));
        });
    }

    /**
     * @SLX\Route(
     *     @SLX\Request(method="DELETE", uri="issues"),
     * )
     * @param Request $request
     * @param Application $app
     * @return JsonResponse
     */
    public function deleteIssues(Request $request, Application $app) {
        return self::wrapInternalService($app, function(EntityManager $dmEm) use ($app, $request) {
            $country = $request->request->get('country');
            $publication = $request->request->get('publication');
            $issuenumbers = $request->request->get('issuenumbers');

            $qb = $dmEm->createQueryBuilder();
            $qb
                ->delete(Numeros::class, 'issues')

                ->andWhere($qb->expr()->eq('issues.pays', ':country'))
                ->setParameter(':country', $country)

                ->andWhere($qb->expr()->eq('issues.magazine', ':publication'))
                ->setParameter(':publication', $publication)

                ->andWhere($qb->expr()->in('issues.numero', ':issuenumbers'))
                ->setParameter(':issuenumbers', $issuenumbers)

                ->andWhere($qb->expr()->in('issues.idUtilisateur', ':userId'))
                ->setParameter(':userId', self::getSessionUser($app)['id']);

            $nbRemoved = $qb->getQuery()->getResult();

            $deletionResult = new UpdateCollectionResult('DELETE', $nbRemoved);

            return new JsonResponse(ModelHelper::getSimpleArray([$deletionResult]));
        });
    }

    /**
     * @SLX\Route(
     *     @SLX\Request(method="POST", uri="purchases/{purchaseId}"),
     *     @SLX\Value(variable="purchaseId", default=null)
     * )
     * @param Application $app
     * @param Request     $request
     * @param string      $purchaseId
     * @return JsonResponse
     */
    public function postPurchase(Application $app, Request $request, $purchaseId) {
        return self::wrapInternalService($app, function (EntityManager $dmEm) use ($app, $request, $purchaseId) {

            $purchaseDate = $request->request->get('date');
            $purchaseDescription = $request->request->get('description');
            $idUser = self::getSessionUser($app)['id'];

            if (!is_null($purchaseId)) {
                $purchase = $dmEm->getRepository(Achats::class)->findOneBy(['idAcquisition' => $purchaseId, 'idUser' => $idUser]);
                if (is_null($purchase)) {
                    return new Response('You don\'t have the rights to update this purchase', Response::HTTP_UNAUTHORIZED);
                }
            }
            else {
                $purchase = new Achats();
            }

            $purchase->setIdUser($idUser);
            $purchase->setDate(\DateTime::createFromFormat('Y-m-d H:i:s', $purchaseDate.' 00:00:00'));
            $purchase->setDescription($purchaseDescription);

            $dmEm->persist($purchase);
            $dmEm->flush();

            return new Response();
        });
    }

    /**
     * @SLX\Route(
     *     @SLX\Request(method="POST", uri="issues")
     * )
     * @param Request     $request
     * @param Application $app
     * @return JsonResponse
     */
    public function postIssues(Request $request, Application $app) {
        return self::wrapInternalService($app, function(EntityManager $dmEm) use ($app, $request) {
            $country = $request->request->get('country');
            $publication = $request->request->get('publication');
            $issuenumbers = $request->request->get('issuenumbers');

            $condition = $request->request->get('condition');
            $conditionNewIssues = is_null($condition) ? 'possede' : $condition;

            $istosell = $request->request->get('istosell');
            $istosellNewIssues = is_null($istosell) ? false : $istosell;

            $purchaseid = $request->request->get('purchaseid');
            $purchaseidNewIssues = is_null($purchaseid) ? -2 : $purchaseid; // TODO allow NULL

            $qb = $dmEm->createQueryBuilder();
            $qb
                ->select('issues')
                ->from(Numeros::class, 'issues')

                ->andWhere($qb->expr()->eq('issues.pays', ':country'))
                ->setParameter(':country', $country)

                ->andWhere($qb->expr()->eq('issues.magazine', ':publication'))
                ->setParameter(':publication', $publication)

                ->andWhere($qb->expr()->in('issues.numero', ':issuenumbers'))
                ->setParameter(':issuenumbers', $issuenumbers)

                ->andWhere($qb->expr()->eq('issues.idUtilisateur', ':userId'))
                ->setParameter(':userId', self::getSessionUser($app)['id'])

                ->indexBy('issues', 'issues.numero');

            /** @var Numeros[] $existingIssues */
            $existingIssues = $qb->getQuery()->getResult();

            foreach($existingIssues as $existingIssue) {
                if (!is_null($condition)) {
                    $existingIssue->setEtat($condition);
                }
                if (!is_null($istosell)) {
                    $existingIssue->setAv($istosell);
                }
                if (!is_null($purchaseid)) {
                    $existingIssue->setIdAcquisition($purchaseid);
                }
                $dmEm->persist($existingIssue);
            }

            $issueNumbersToCreate = array_diff($issuenumbers, array_keys($existingIssues));
            foreach($issueNumbersToCreate as $issueNumberToCreate) {
                $newIssue = new Numeros();
                $newIssue->setPays($country);
                $newIssue->setMagazine($publication);
                $newIssue->setNumero($issueNumberToCreate);
                $newIssue->setEtat($conditionNewIssues);
                $newIssue->setAv($istosellNewIssues);
                $newIssue->setIdAcquisition($purchaseidNewIssues);
                $newIssue->setIdUtilisateur(self::getSessionUser($app)['id']);
                $newIssue->setDateajout(time());

                $dmEm->persist($newIssue);
            }

            $dmEm->flush();
            $dmEm->clear();

            $updateResult = new UpdateCollectionResult('UPDATE', count($existingIssues));
            $creationResult = new UpdateCollectionResult('CREATE', count($issueNumbersToCreate));

            return new JsonResponse(ModelHelper::getSimpleArray([$updateResult, $creationResult]));
        });
    }

    /**
     * @SLX\Route(
     *     @SLX\Request(method="PUT", uri="externalaccess")
     * )
     * @param Application $app
     * @return JsonResponse
     */
    public function addExternalAccess(Application $app) {
        return self::wrapInternalService($app, function(EntityManager $dmEm) use ($app) {
            $key = MiscUtil::getRandomString();

            $externalAccess = new BibliothequeAccesExternes();
            $externalAccess->setIdUtilisateur(self::getSessionUser($app)['id']);
            $externalAccess->setCle($key);

            $dmEm->persist($externalAccess);
            $dmEm->flush();

            return new JsonResponse(['key' => $key]);
        });
    }

    /**
     * @SLX\Route(
     *     @SLX\Request(method="GET", uri="externalaccess/{key}")
     * )
     * @param Application $app
     * @param string $key
     * @return JsonResponse
     */
    public function getExternalAccess(Application $app, $key) {
        return self::wrapInternalService($app, function(EntityManager $dmEm) use ($key) {
            $access = $dmEm->getRepository(BibliothequeAccesExternes::class)->findBy(
                ['cle' => $key]
            );

            return new JsonResponse(ModelHelper::getSerializedArray($access));
        });
    }

    /**
     * @SLX\Route(
     *     @SLX\Request(method="GET", uri="bookcase/sort/withMax/{maxSort}")
     * )
     * @param Application $app
     * @param $maxSort
     * @return JsonResponse
     */
    public function getBookcaseSorting(Application $app, $maxSort) {
        return self::wrapInternalService($app, function(EntityManager $dmEm) use ($app, $maxSort) {

            $qbMissingSorts = $dmEm->createQueryBuilder();
            $qbMissingSorts
                ->select('distinct concat(issues.pays, \'/\', issues.magazine) AS missing_publication_code')
                ->from(Numeros::class, 'issues')

                ->andWhere('concat(issues.pays, \'/\', issues.magazine) not in (select sorts.publicationcode from '.BibliothequeOrdreMagazines::class.' sorts where sorts.idUtilisateur = :userId)')
                ->setParameter(':userId', self::getSessionUser($app)['id'])

                ->andWhere('issues.idUtilisateur =  :userId')

                ->orderBy(new OrderBy('missing_publication_code', 'ASC'));

            $missingSorts = $qbMissingSorts->getQuery()->getArrayResult();
            foreach($missingSorts as $missingSort) {
                $sort = new BibliothequeOrdreMagazines();
                $sort->setPublicationcode($missingSort['missing_publication_code']);
                $sort->setOrdre(++$maxSort);
                $sort->setIdUtilisateur(self::getSessionUser($app)['id']);
                $dmEm->persist($sort);
            }
            $dmEm->flush();

            $sorts = $dmEm->getRepository(BibliothequeOrdreMagazines::class)->findBy(
                ['idUtilisateur' => self::getSessionUser($app)['id']],
                ['ordre' => 'ASC']
            );

            return new JsonResponse(array_map(function(BibliothequeOrdreMagazines $sort) {
                return $sort->getPublicationcode();
            }, $sorts));
        });
    }

    /**
     * @SLX\Route(
     *     @SLX\Request(method="POST", uri="bookcase/sort")
     * )
     * @param Application $app
     * @param Request $request
     * @return JsonResponse
     */
    public function setBookcaseSorting(Application $app, Request $request) {
        return self::wrapInternalService($app, function(EntityManager $dmEm) use ($app, $request) {
            $sorts = $request->request->get('sorts');

            if (is_array($sorts)) {
                $qbMissingSorts = $dmEm->createQueryBuilder();
                $qbMissingSorts
                    ->delete(BibliothequeOrdreMagazines::class, 'sorts')
                    ->where('sorts.idUtilisateur = :userId')
                    ->setParameter(':userId', self::getSessionUser($app)['id']);
                $qbMissingSorts->getQuery()->execute();

                $maxSort = -1;
                foreach($sorts as $publicationCode) {
                    $sort = new BibliothequeOrdreMagazines();
                    $sort->setPublicationcode($publicationCode);
                    $sort->setOrdre(++$maxSort);
                    $sort->setIdUtilisateur(self::getSessionUser($app)['id']);
                    $dmEm->persist($sort);
                }
                $dmEm->flush();
                return new JsonResponse(['max' => $maxSort]);
            }
            return new Response('Invalid sorts parameter',Response::HTTP_BAD_REQUEST);
        });
    }

    /**
     * @SLX\Route(
     *     @SLX\Request(method="GET", uri="bookcase/sort/max")
     * )
     * @param Application $app
     * @return JsonResponse
     * @throws \InvalidArgumentException
     */
    public function getLastPublicationPosition(Application $app) {
        return self::wrapInternalService($app, function(EntityManager $dmEm) use ($app) {

            $qb = $dmEm->createQueryBuilder();
            $qb
                ->select('max(sorts.ordre) as max')
                ->from(BibliothequeOrdreMagazines::class, 'sorts')

                ->andWhere($qb->expr()->eq('sorts.idUtilisateur', ':userId'))
                ->setParameter(':userId', self::getSessionUser($app)['id']);

            $maxSort = $qb->getQuery()->getResult(Query::HYDRATE_SCALAR);

            if (count($maxSort) === 0 || is_null($maxSort[0]['max'])) {
                return new Response('No publication found for the bookcase', Response::HTTP_NO_CONTENT);
            }

            return new JsonResponse(['max' => (int)($maxSort[0]['max'])]);
        });
    }

    /**
     * @SLX\Route(
     *   @SLX\Request(method="POST", uri="inducks/import/init")
     * )
     * @param Application $app
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function importFromInducksInit(Application $app, Request $request) {
        return self::wrapInternalService($app, function() use ($app, $request) {
            $rawData = $request->request->get('rawData');

            if (strpos($rawData, 'country^entrycode^collectiontype^comment') === false) {
                return new Response('No headers', Response::HTTP_NO_CONTENT);
            }

            preg_match_all('#^((?!country)[^\n\^]+)\^([^\n\^]+)\^[^\n\^]*\^.*$#m', $rawData, $matches, PREG_SET_ORDER);
            if (count($matches) === 0) {
                return new Response('No content', Response::HTTP_NO_CONTENT);
            }

            $issues = array_map(function($match) {
                $issueCode = implode('/', [$match[1], preg_replace('#[ ]+#', ' ', $match[2])]);
                [$publicationCode, $issueNumber] = explode(' ', $issueCode);
                return [
                    'publicationcode' => $publicationCode,
                    'issuenumber' => $issueNumber
                ];
            }, array_unique($matches, SORT_REGULAR));

            $newIssues = self::getNonPossessedIssues($issues, self::getSessionUser($app)['id']);

            return new JsonResponse([
                'issues' => $newIssues,
                'existingIssuesCount' => count($issues) - count($newIssues)
            ]);
        });
    }

    /**
     * @SLX\Route(
     *   @SLX\Request(method="POST", uri="inducks/import")
     * )
     * @param Application $app
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function importFromInducks(Application $app, Request $request) {
        return self::wrapInternalService($app, function() use ($app, $request) {
            $issues = $request->request->get('issues');
            $defaultCondition = $request->request->get('defaultCondition');

            $newIssues = self::getNonPossessedIssues($issues, self::getSessionUser($app)['id']);
            $dmEm = DmServer::getEntityManager(DmServer::CONFIG_DB_KEY_DM);

            foreach($newIssues as $issue) {
                [$country, $magazine] = explode('/', $issue['publicationcode']);
                $newIssue = new Numeros();
                $newIssue
                    ->setIdUtilisateur(self::getSessionUser($app)['id'])
                    ->setPays($country)
                    ->setMagazine($magazine)
                    ->setNumero($issue['issuenumber'])
                    ->setAv(false)
                    ->setDateajout(time())
                    ->setEtat($defaultCondition);
                $dmEm->persist($newIssue);
            }
            $dmEm->flush();

            return new JsonResponse([
                'importedIssuesCount' => count($newIssues),
                'existingIssuesCount' => count($issues) - count($newIssues)
            ]);
        });
    }

    /**
     * @param array $issues
     * @param int   $userId
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\Common\Annotations\AnnotationException
     */
    private function getNonPossessedIssues($issues, $userId) {
        $dmEm = DmServer::getEntityManager(DmServer::CONFIG_DB_KEY_DM);
        $currentIssues = $dmEm->getRepository(Numeros::class)->findBy(['idUtilisateur' => $userId]);

        $currentIssuesByPublication = [];
        foreach($currentIssues as $currentIssue) {
            $currentIssuesByPublication[$currentIssue->getPays().'/'.$currentIssue->getMagazine()][] = $currentIssue->getNumero();
        }

        return array_values(array_filter($issues, function($issue) use ($currentIssuesByPublication) {
            return (!(isset($currentIssuesByPublication[$issue['publicationcode']]) && in_array($issue['issuenumber'], $currentIssuesByPublication[$issue['publicationcode']], true)));
        }));
    }
}
