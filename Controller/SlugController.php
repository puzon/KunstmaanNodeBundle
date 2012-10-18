<?php

namespace Kunstmaan\NodeBundle\Controller;

use Doctrine\ORM\EntityManager;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\SecurityContextInterface;

use Kunstmaan\NodeBundle\Helper\NodeMenu;
use Kunstmaan\NodeBundle\Helper\RenderContext;
use Kunstmaan\NodeBundle\Entity\NodeTranslation;
use Kunstmaan\NodeBundle\Entity\HasNodeInterface;
use Kunstmaan\NodeBundle\Entity\DynamicRoutingPageInterface;
use Kunstmaan\AdminBundle\Helper\Security\Acl\AclHelper;
use Kunstmaan\AdminBundle\Helper\Security\Acl\Permission\PermissionMap;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * This controller is for showing frontend pages based on slugs
 */
class SlugController extends Controller
{

    /**
     * Handle slug action.
     *
     * @param string $url     The url
     * @param bool   $preview Show in preview mode
     * @param bool   $draft   Show the draft version or not
     *
     * @throws NotFoundHttpException
     * @throws AccessDeniedHttpException
     * @Route("/")
     * @Route("/draft", defaults={"preview" = true, "draft" = true})
     * @Route("/preview", defaults={"preview" = true})
     * @Route("/draft/{url}", requirements={"url" = ".+"}, defaults={"preview" = true, "draft" = true}, name="_slug_draft")
     * @Route("/preview/{url}", requirements={"url" = ".+"}, defaults={"preview" = true}, name="_slug_preview")
     * @Route("/{url}", requirements={"url" = ".+"}, name="_slug")
     * @Template()
     *
     * @return Response|array
     */
    public function slugAction($url = null, $preview = false, $draft = false)
    {
        /* @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $request = $this->getRequest();
        $locale = $request->getLocale();

        if (empty($locale)) {
            $locale = $request->getLocale();
        }

        $requiredLocales = $this->container->getParameter('requiredlocales');

        $localesArray = explode('|', $requiredLocales);

        if (!empty($localesArray[0])) {
            $fallback = $localesArray[0];
        } else {
            $fallback = $this->container->getParameter('locale');
        }

        if (!in_array($locale, $localesArray)) {
            if (empty($url)) {
                $url = $locale;
            } else {
                $url = $locale . '/' . $url;
            }
            $locale = $fallback;
            if ($draft) {
                return $this->redirect($this->generateUrl('_slug_draft', array('url' => $url, '_locale' => $locale)));
            } else {
                return $this->redirect($this->generateUrl('_slug', array('url' => $url, '_locale' => $locale)));
            }
        }

        /* @var NodeTranslation $nodeTranslation */
        $nodeTranslation = $em->getRepository('KunstmaanNodeBundle:NodeTranslation')->getNodeTranslationForUrl($url, $locale);
        $exactMatch = true;
        if (!$nodeTranslation) {
            // Lookup node by best match for url
            $nodeTranslation = $em->getRepository('KunstmaanNodeBundle:NodeTranslation')->getBestMatchForUrl($url, $locale);
            $exactMatch = false;
        }

        /* @var HasNodeInterface $page */
        $page = null;
        $node = null;
        if ($nodeTranslation) {
            if ($draft) {
                $version = $nodeTranslation->getNodeVersion('draft');
                if (is_null($version)) {
                    $version = $nodeTranslation->getPublicNodeVersion();
                }
                $page = $version->getRef($em);
            } else {
                $page = $nodeTranslation->getPublicNodeVersion()->getRef($em);
            }
            $node = $nodeTranslation->getNode();
        }

        // If no node translation or no exact match that is not a dynamic routing page -> 404
        if (!$nodeTranslation || (!$exactMatch && !($page instanceof DynamicRoutingPageInterface))) {
            throw $this->createNotFoundException('No page found for slug ' . $url);
        }

        // check if the requested node is online, else throw a 404 exception (only when not previewing!)
        if (!$preview && !$nodeTranslation->isOnline()) {
            throw $this->createNotFoundException("The requested page is not online");
        }

        /* @var SecurityContextInterface $securityContext */
        $securityContext = $this->get('security.context');
        if (false === $securityContext->isGranted(PermissionMap::PERMISSION_VIEW, $node)) {
            throw new AccessDeniedHttpException('You do not have sufficient rights to access this page.');
        }

        /* @var AclHelper $aclHelper */
        $aclHelper  = $this->container->get('kunstmaan_admin.acl.helper');
        $nodeMenu = new NodeMenu($em, $securityContext, $aclHelper, $locale, $node);

        if ($page instanceof DynamicRoutingPageInterface) {
            /* @var DynamicRoutingPageInterface $page */
            $page->setLocale($locale);
            $slugPart = substr($url, strlen($nodeTranslation->getUrl()));
            if (false === $slugPart) {
                $slugPart = '/';
            }
            $path = $page->match($slugPart);
            if ($path) {
                $path['nodeTranslationId'] = $nodeTranslation->getId();

                return $this->forward($path['_controller'], $path, $request->query->all());
            }
        }

        //render page
        $renderContext = new RenderContext(array(
                'nodetranslation' => $nodeTranslation,
                'slug' => $url,
                'page' => $page,
                'resource' => $page,
                'nodemenu' => $nodeMenu,
                'locales' => $localesArray));
        $hasView = false;
        if (method_exists($page, 'getDefaultView')) {
            /** @noinspection PhpUndefinedMethodInspection */
            $renderContext->setView($page->getDefaultView());
            $hasView = true;
        }
        if (method_exists($page, 'service')) {
            /** @noinspection PhpUndefinedMethodInspection */
            $redirect = $page->service($this->container, $request, $renderContext);
            if (!empty($redirect)) {
                return $redirect;
            } elseif (!$exactMatch && !$hasView) {
                // If it was a dynamic routing page and no view and no service implementation -> 404
                throw $this->createNotFoundException('No page found for slug ' . $url);
            }
        }

        return $this->render($renderContext->getView(), (array) $renderContext);
    }
}
