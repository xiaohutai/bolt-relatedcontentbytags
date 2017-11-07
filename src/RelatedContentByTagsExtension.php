<?php

namespace Bolt\Extension\Xiaohutai\RelatedContentByTags;

use Bolt\Extension\SimpleExtension;

/**
 * RelatedContentByTags extension class.
 *
 * @author Xiao-Hu Tai (github.com/xiaohutai)
 * @author Nicolas Béhier-Dévigne (github.com/nbehier)
 */
class RelatedContentByTagsExtension extends SimpleExtension
{
    /**
     * @inheritdoc
     *
     * @return array
     */
    protected function registerTwigFunctions()
    {
        return [
            'relatedcontentbytags' => 'relatedContentByTags',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig()
    {
        return [
            'points' => [
                'tag'  => 10,
                'type' => 10
            ],
            'limit' => 10
        ];
    }

    /**
     * Pretty extension name
     *
     * @return string
     */
    public function getDisplayName()
    {
        return 'Related Content By Tags';
    }

    /**
     * @author Xiao-Hu Tai
     * @param \Bolt\Content $record   The record to search similar content for.
     * @param array         $options  Options for custom queries.
     *
     * @return array Returns an array with the elements sorted by similarity.
     */
    function relatedContentByTags($record, $options = [])
    {
        $app          = $this->getContainer();
        $config       = $this->getConfig();
        $limit        = isset($options['limit'])  ? $options['limit']  : $config['limit'];
        $contenttypes = $app['config']->get('contenttypes');
        $filter       = isset($options['contenttypes']) ? $options['contenttypes'] : false;

        // Get all taxonomies that behave like tags and their values from $record.
        $tagsValues     = [];
        $tagsTaxonomies = [];

        // If no taxonomies exist, then no matching items exist
        if (!isset( $record->contenttype['taxonomy'])) {
            return [];
        }
        foreach ( $record->contenttype['taxonomy'] as $key ) {
            if ($app['config']->get('taxonomy/'.$key.'/behaves_like') == 'tags') {
                // only useful if values exist, otherwise just skip this taxonomy
                if ($record->taxonomy[$key]) {
                    $tagsValues[$key] = array_values( $record->taxonomy[$key] );
                    $tagsTaxonomies[] = $key;
                }
            }
        }

        // Get all contenttypes (database tables) that have a similar behaves-like-tags taxonomies like $record
        $results = [];
        foreach ($contenttypes as $contentType) {

            // Keep contentType if non-filtered by options
            if (!empty($filter) && ! in_array($contentType['slug'], $filter) ) {
                continue;
            }

            // Keep only contentType with taxonomy behave like tags
            if ( ! isset($contentType['taxonomy'])
                || count(array_intersect($contentType['taxonomy'], $tagsTaxonomies) ) == 0) {
                continue;
            }

            // Get all contentType published which have at least one tag in common
            $repoT = $app['storage']->getRepository('Bolt\Storage\Entity\Taxonomy');
            $qbT   = $repoT->createQueryBuilder('taxonomy');
            $qbT->select('taxonomy.content_id as id')
                ->where(
                    $qbT->expr()->andX(
                        $qbT->expr()->eq('taxonomy.taxonomytype', ':tags'),
                        $qbT->expr()->eq('taxonomy.contenttype', ':contenttype'),
                        $qbT->expr()->in('taxonomy.slug', ':aTags')
                    )
                );

            $repoC = $app['storage']->getRepository($contentType['slug']);
            $qb    = $repoC->createQueryBuilder('content');
            $qb->where(
                    $qb->expr()->andX(
                        $qb->expr()->eq('content.status', ':published'),
                        $qb->expr()->in('content.id', $qbT->getSQL() )
                    )
                )
                ->orderBy('content.datepublish', 'DESC')
                ->setMaxResults(9999)
                ->setParameter('published', 'published')
                ->setParameter('tags', 'tags')
                ->setParameter('contenttype', $contentType['slug'])
                ->setParameter('aTags', $tagsValues['tags'], \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);

            //but not record itself
            if ($contentType['slug'] == $record->contenttype['slug']) {
                $qb->andWhere('content.id != :id')
                   ->setParameter('id', $record->id);
            }

            $queryResults = $qb->execute()->fetchAll();

            if (!empty($queryResults)) {
                $ids      = implode(' || ', \utilphp\util::array_pluck($queryResults, 'id'));
                $contents = $app['storage']->getContent($contentType['slug'], ['id' => $ids, 'returnsingle' => false]);
                $results  = array_merge( $results,  $contents );
            }
        }

        // Add similarities by tags and difference in publication dates.
        foreach ($results as $result) {
            $similarity = $this->calculateTaxonomySimilarity($record, $result, $tagsTaxonomies, $tagsValues, $options);
            $diff       = $this->calculatePublicationDiff($record, $result);
            $result->similarity = $similarity;
            $result->diff       = $diff;
        }

        // Sort results
        usort($results, [$this, 'compareSimilarity']);

        // Limit results
        $results = array_slice($results, 0, $limit);

        return $results;

    }

    /**
     * @param \Bolt\Content $a
     * @param \Bolt\Content $b
     * @param array         $tagsTaxonomies
     * @param array         $tagsValues
     * @param array         $options
     *
     * @return int
     */
    private function calculateTaxonomySimilarity($a, $b, $tagsTaxonomies, $tagsValues, $options = [])
    {
        $config     = $this->getConfig();
        $similarity = 0;
        $pointsTag  = isset($options['pointsTag'])  ? $options['pointsTag']  : $config['points']['tag'];
        $pointsType = isset($options['pointsType']) ? $options['pointsType'] : $config['points']['type'];

        // 1. more similar tags => higher score
        $taxonomies = $b->taxonomy;
        foreach ($taxonomies as $taxonomyKey => $values) {
            if( in_array($taxonomyKey, $tagsTaxonomies) ) {
                foreach ($values as $value) {
                    if (in_array($value, $tagsValues[$taxonomyKey])) {
                        $similarity += $pointsTag;
                    }
                }
            }
        }

        // 2. same contenttype => higher score
        //    e.g. a book and a book is more similar, than a book and a kitchensink
        if ($a->contenttype['slug'] == $b->contenttype['slug']) {
            $similarity += $pointsType;
        }

        return $similarity;
    }

    /**
     * Calculates the difference in seconds between two Bolt records.
     *
     * A smaller difference in 'datepublish' implies a higher score, e.g. a news
     * article around the same period is more similar than others.
     *
     * @param \Bolt\Content $b
     * @param \Bolt\Content $a
     *
     * @return int
     */
    private function calculatePublicationDiff($a, $b)
    {
        $t1   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $a->values['datepublish'] );
        $t2   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $b->values['datepublish'] );
        $diff = abs( $t1->getTimestamp() - $t2->getTimestamp() ); // diff in seconds

        return $diff;
    }

    /**
     * Compares two Bolt records for sorting.
     *
     * @param \Bolt\Content $a
     * @param \Bolt\Content $b
     *
     * @return int
     */
    private function compareSimilarity($a, $b)
    {
        if ($a->similarity > $b->similarity) {
            return -1;
        }
        if ($a->similarity < $b->similarity) {
            return +1;
        }

        if ($a->diff < $b->diff) {
            // less difference is more important
            return -1;
        }
        if ($a->diff > $b->diff) {
            // more difference is less important
            return +1;
        }

        return strcasecmp($a->values['title'], $b->values['title']);
    }

}
