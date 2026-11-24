<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Scoring happens on a GET request, which mail clients and antivirus scanners are happy to follow
 * on the customer's behalf - and there are eleven of those links in one email. Four independent
 * signals, because no single one catches every scanner.
 */
final class BotGuard
{
    private const PREFETCH_HEADERS = [
        'Purpose' => 'prefetch',
        'X-Purpose' => 'preview',
        'X-Moz' => 'prefetch',
    ];

    /** @param list<string> $userAgentSignatures */
    public function __construct(
        private readonly RateLimiterFactory $surveyTokenLimiter,
        private readonly RateLimiterFactory $surveyIpLimiter,
        private readonly array $userAgentSignatures,
    ) {
    }

    /**
     * Inspects one request. The token limiter is consumed here and nowhere else, so this runs once
     * per request and the answer carries everything the caller needs.
     */
    public function inspect(Request $request, string $token): Automation
    {
        // Checked first, because it is the only signal that says something about the record
        // rather than about this one request.
        if ($this->linksOfOneSurveyAreBeingWorkedThrough($token)) {
            return Automation::LinksWorkedThrough;
        }

        if (
            $this->announcesItselfAsPrefetch($request) ||
            $this->carriesAKnownBotSignature($request) ||
            $this->comesFromAnAddressHittingTooOften($request)
        ) {
            return Automation::RequestIgnored;
        }

        return Automation::NotDetected;
    }

    private function announcesItselfAsPrefetch(Request $request): bool
    {
        foreach (self::PREFETCH_HEADERS as $header => $value) {
            if ($value === strtolower((string) $request->headers->get($header, ''))) {
                return true;
            }
        }

        return false;
    }

    private function carriesAKnownBotSignature(Request $request): bool
    {
        $userAgent = strtolower((string) $request->headers->get('User-Agent', ''));

        if ('' === $userAgent) {
            return false;
        }

        foreach ($this->userAgentSignatures as $signature) {
            if (str_contains($userAgent, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The decisive signal, because it works against a scanner with no recognisable signature:
     * a person does not open a dozen of the eleven links within a minute.
     */
    private function linksOfOneSurveyAreBeingWorkedThrough(string $token): bool
    {
        return !$this->surveyTokenLimiter->create($token)->consume()->isAccepted();
    }

    private function comesFromAnAddressHittingTooOften(Request $request): bool
    {
        return !$this->surveyIpLimiter->create($request->getClientIp())->consume()->isAccepted();
    }
}
