<?php
namespace App\Service;

use App\Entity\Coa\InducksPerson;
use App\Entity\Coa\InducksPublication;
use App\Entity\Coa\InducksStory;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\QueryException;

class CoaService
{
    /**@var EntityManager */
    private static $coaEm;

    public function __construct(ManagerRegistry $doctrineManagerRegistry)
    {
        self::$coaEm = $doctrineManagerRegistry->getManager('coa');
    }

    /**
     * @param string[] $authorCodes
     * @return string[]
     * @throws QueryException
     */
    public function getAuthorNames(array $authorCodes) : array {
        if (empty($authorCodes)) {
            return [];
        }
        $qbAuthorsFullNames = self::$coaEm->createQueryBuilder();
        $qbAuthorsFullNames
            ->select('p.personcode, p.fullname')
            ->from(InducksPerson::class, 'p')
            ->where($qbAuthorsFullNames->expr()->in('p.personcode', array_unique($authorCodes)))
            ->indexBy('p', 'p.personcode');

        $fullNamesResults = $qbAuthorsFullNames->getQuery()->getResult();
        return array_map(function(array $person) {
            return $person['fullname'];
        }, $fullNamesResults);
    }

    /**
     * @param string[] $storyCodes
     * @return array[]
     * @throws QueryException
     */
    public function getStoryDetails(array $storyCodes) : array
    {
        if (empty($storyCodes)) {
            return [];
        }
        $qbStoryDetails = self::$coaEm->createQueryBuilder();
        $qbStoryDetails
            ->select('story')
            ->from(InducksStory::class, 'story')
            ->where($qbStoryDetails->expr()->in('story.storycode', $storyCodes))
            ->indexBy('story', 'story.storycode');

        $storyDetailsResults = $qbStoryDetails->getQuery()->getResult();

        $storyDetails = array_map(function(InducksStory $story) {
            return [
                'storycomment' => $story->getStorycomment(),
                'title' => $story->getTitle()
            ];
        }, $storyDetailsResults);

        // Empty properties if the story couldn't be found
        foreach($storyCodes as $storyCode) {
            if (!isset($storyDetails[$storyCode])) {
                $storyDetails[$storyCode] = [
                    'storycomment' => '',
                    'title' => '?'
                ];
            }
        }
        return $storyDetails;
    }

    /**
     * @param string[] $publicationCodes
     * @return array
     * @throws QueryException
     */
    public function getPublicationTitles(array $publicationCodes) : array {
        if (empty($publicationCodes)) {
            return [];
        }
        $qb = (self::$coaEm->createQueryBuilder());
        $qb
            ->select('inducks_publication.publicationcode, inducks_publication.title')
            ->from(InducksPublication::class, 'inducks_publication')
            ->where($qb->expr()->in('inducks_publication.publicationcode', $publicationCodes))
            ->orderBy('inducks_publication.title')
            ->indexBy('inducks_publication', 'inducks_publication.publicationcode');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function(array $person) {
            return $person['title'];
        }, $results);
    }

    /**
     * @param string $country
     * @return string[]
     * @throws QueryException
     */
    public function getPublicationTitlesFromCountry(string $country) : array {
        $qb = (self::$coaEm->createQueryBuilder());
        $qb
            ->select('inducks_publication.publicationcode, inducks_publication.title')
            ->from(InducksPublication::class, 'inducks_publication')
            ->where($qb->expr()->like('inducks_publication.publicationcode', "'$country/%'"))
            ->orderBy('inducks_publication.title')
            ->indexBy('inducks_publication', 'inducks_publication.publicationcode');

        $results = $qb->getQuery()->getArrayResult();
        return array_map(function(array $person) {
            return $person['title'];
        }, $results);
    }
}