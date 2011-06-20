<?php

/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CacheController extends Controller
{
    public function esiAction()
    {
        $request = $this->get('request');

        if ($request->get('manager') == 'page') {
            $manager = $this->get('sonata.page.cms.page');
        } else {
            $manager = $this->get('sonata.page.cms.snapshot');
        }

        $page    = $manager->getPageById($request->get('page_id'));
        $block   = $manager->getBlock($request->get('block_id'));

        if (!$page || !$block) {
            return new Response('', 404);
        }

        $response = $manager->renderBlock($block, $page, false);

        if ($request->get('handler') == 'page') {
            $response->setPrivate();
        } else {
            $response->setPublic();
            $response->headers->add(array(
                'x-sonata-block-id'    => $block->getId(),
                'x-sonata-page-id'     => $page->getId(),
                'x-sonata-block-type'  => $block->getType(),
                'x-sonata-page-route'  => $page->getRouteName(),
            ));
        }

        return $response;
    }

    public function apcAction($token)
    {
        if ($this->get('sonata.page.cache.apc')->getToken() == $token) {
            apc_clear_cache('user');

            return new Response('ok', 200, array(
                'Cache-Control' => 'no-cache, must-revalidate'
            ));
        }

        throw new AccessDeniedException('invalid token');
    }

    public function jsAction()
    {
        $request = $this->get('request');

        $securityContext = $this->container->get('security.context');

        if ($securityContext->isGranted('ROLE_SONATA_PAGE_ADMIN_PAGE_EDIT')) {
            $manager = $this->get('sonata.page.cms.page');
        } else {
            $manager = $this->get('sonata.page.cms.snapshot');
        }

        $page    = $manager->getPageById($request->get('page_id'));
        $block   = $manager->getBlock($request->get('block_id'));

        if (!$page || !$block) {
            return new Response('', 404);
        }

        $response = $manager->renderBlock($block, $page, false);
        $response->setPrivate(); //  always set to private

        if ($request->get('_sync') == true) {
            return $response;
        }

        $response->setContent(sprintf(<<<JS
    (function () {
      var block = document.getElementById('block-cms-%s');

      var div = document.createElement("div");
      div.innerHTML = %s;

      for (var node in div.childNodes) {
        if (div.childNodes[node] && div.childNodes[node].nodeType == 1) {
          block.parentNode.replaceChild(div.childNodes[node], block);
        }
      }
    })();
JS
, $block->getId(), json_encode($response->getContent())));

        $response->headers->set('Content-Type', 'application/javascript');

        return $response;
    }
}