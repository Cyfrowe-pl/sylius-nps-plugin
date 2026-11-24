<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Security;

/**
 * What the guard made of a request. The distinction matters: most signals only mean "do not take
 * a score from this request", while one of them means the record already holds scores that were
 * never the customer's.
 */
enum Automation
{
    case NotDetected;

    /**
     * A prefetch, a known scanner, or an address sending far too much. The request itself scores
     * nothing, and there is nothing to undo - whatever the customer answered earlier stands.
     */
    case RequestIgnored;

    /**
     * The links of one survey are being worked through. The first few arrived before anything
     * could tell, so the record has to give back what they left.
     */
    case LinksWorkedThrough;
}
