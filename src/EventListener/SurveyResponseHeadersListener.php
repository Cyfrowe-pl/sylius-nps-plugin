<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The survey link carries a token, so it must never reach a search index, leak through the Referer
 * header of anything the customer clicks next, or sit in a shared cache. The headers go on every
 * survey response, redirects included - a listener is the only place that catches all of them.
 */
final class SurveyResponseHeadersListener
{
    private const ROUTE_PREFIX = 'cyfrowe_nps_shop_survey';

    public function __invoke(ResponseEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');

        if (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Referrer-Policy', 'no-referrer');

        // A CDN in front of the shop would otherwise be free to hand a cached form to someone who
        // has already answered.
        $headers->set('Cache-Control', 'no-store, private');
    }
}
