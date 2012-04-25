<?php

namespace Kunstmaan\AdminNodeBundle\Repository;

use Kunstmaan\AdminNodeBundle\Entity\HasNode;
use Kunstmaan\AdminNodeBundle\Entity\Node;
use Kunstmaan\AdminNodeBundle\Entity\NodeTranslation;
use Kunstmaan\AdminBundle\Entity\PageIFace;
use Kunstmaan\AdminBundle\Entity\AddCommand;
use Kunstmaan\AdminBundle\Entity\User as Baseuser;
use Kunstmaan\AdminBundle\Modules\Slugifier;

use Kunstmaan\AdminBundle\Modules\ClassLookup;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\ResultSetMapping;

/**
 * NodeRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class NodeTranslationRepository extends EntityRepository
{
    /**
     * Get all childs of a given node
     * @param \Kunstmaan\AdminNodeBundle\Entity\Node $node
     *
     * @return array
     */
    public function getChildren(Node $node)
    {
        return $this->findBy(array("parent" => $node->getId()));
    }

    /**
     * Get the nodetranslation for a node
     * @param \Kunstmaan\AdminNodeBundle\Entity\HasNode $hasNode
     *
     * @return Kunstmaan\AdminNodeBundle\Entity\NodeTranslation
     */
    public function getNodeTranslationFor(HasNode $hasNode)
    {
        $nodeVersion = $this->getEntityManager()->getRepository('KunstmaanAdminNodeBundle:NodeVersion')->getNodeVersionFor($hasNode);
        if (!is_null($nodeVersion)) {
            return $nodeVersion->getNodeTranslation();
        }

        return null;
    }

    /**
     * Get the nodetranslation for a given url
     * @param \Kunstmaan\AdminNodeBundle\Entity\NodeTranslation|null $parentNode
     * @param $slug
     *
     * @return \Kunstmaan\AdminNodeBundle\Entity\NodeTranslation|null|object
     */
    public function getNodeTranslationForSlug(NodeTranslation $parentNode = null, $slug)
    {
        if (empty($slug)) {
            return $this->getNodeTranslationForSlugPart(null, $slug);
        }

        $slugparts = explode("/", $slug);
        $result = $parentNode;
        foreach ($slugparts as $slugpart) {
            $result = $this->getNodeTranslationForSlugPart($result, $slugpart);
        }

        return $result;
    }

    /**
     * Returns the nodetranslation for a given slug
     * @param \Kunstmaan\AdminNodeBundle\Entity\NodeTranslation|null $parentNode
     * @param $slugpart
     *
     * @return null|object
     */
    private function getNodeTranslationForSlugPart(NodeTranslation $parentNode = null, $slugpart)
    {
    	 if ($parentNode != null) {
            $qb = $this->createQueryBuilder('b')
                ->select('b')
                ->innerJoin('b.node', 'n', 'WITH', 'b.node = n.id')
                ->where('b.slug = ?1 and n.parent = ?2')
                ->andWhere('n.deleted != 1')
                ->addOrderBy('n.sequencenumber', 'DESC')
                ->setFirstResult(0)
                ->setMaxResults(1)
                ->setParameter(1, $slugpart)
                ->setParameter(2, $parentNode->getNode()->getId());
            $result = $qb->getQuery()->getResult();
            if (sizeof($result) == 1) {
                return $result[0];
            } else if (sizeof($result) == 0) {
                return null;
            } else {
                return $result[0];
            }
        } else {
        	/* if parent is null we should look for slugs that have no parent */
	        $qb = $this->createQueryBuilder('t')
	                ->select('t')
	                ->innerJoin('t.node', 'n', 'WITH', 't.node = n.id')
	                ->where('n.deleted != 1 and n.parent IS NULL')
	                ->addOrderBy('n.sequencenumber', 'DESC')
	                ->setFirstResult(0)
	                ->setMaxResults(1);
	        
	        if(empty($slugpart)){
	        	$qb->andWhere('t.slug is NULL');
	        }else{
	        	$qb->andWhere('t.slug = ?1');
	        	$qb->setParameter(1, $slugpart);
	        }
	        $result = $qb->getQuery()->getResult();
	        if (sizeof($result) == 1) {
	        	return $result[0];
	        } else if (sizeof($result) == 0) {
	            return null;
	        } else {
	            return $result[0];
	        }    
        }
    }

    /**
     * Create a nodetranslation for a given node
     * @param \Kunstmaan\AdminNodeBundle\Entity\HasNode $hasNode
     * @param string $lang
     * @param \Kunstmaan\AdminNodeBundle\Entity\Node $node
     * @param \Kunstmaan\AdminBundle\Entity\User $owner
     *
     * @return \Kunstmaan\AdminNodeBundle\Entity\NodeTranslation
     * @throws \Exception
     */
    public function createNodeTranslationFor(HasNode $hasNode, $lang, Node $node, Baseuser $owner)
    {
        $em = $this->getEntityManager();
        $classname = ClassLookup::getClass($hasNode);
        if (!$hasNode->getId() > 0) {
            throw new \Exception("the entity of class " . $classname . " has no id, maybe you forgot to flush first");
        }
        $entityrepo = $em->getRepository($classname);
        $nodeTranslation = new NodeTranslation();
        $nodeTranslation->setNode($node);
        $nodeTranslation->setLang($lang);
        $nodeTranslation->setTitle($hasNode->__toString());
        $nodeTranslation->setSlug(Slugifier::slugify($hasNode->__toString()));
        $nodeTranslation->setOnline($hasNode->isOnline());

        $addcommand = new AddCommand($em, $owner);
        $addcommand->execute("new translation for page \"" . $nodeTranslation->getTitle() . "\" with locale: " . $lang, array('entity' => $nodeTranslation));

        $nodeVersion = $em->getRepository('KunstmaanAdminNodeBundle:NodeVersion')->createNodeVersionFor($hasNode, $nodeTranslation, $owner);
        $nodeTranslation->setPublicNodeVersion($nodeVersion);
        $em->persist($nodeTranslation);
        $em->flush();
        $em->refresh($nodeTranslation);
        return $nodeTranslation;
    }
}