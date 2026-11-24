<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Controller\Admin;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Form\Type\ConfigurationType;
use Cyfrowe\SyliusNpsPlugin\Mailer\TestEmailSender;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sends the survey email to the person editing the settings, built from the values on screen.
 *
 * The configuration itself is deliberately not validated: it may well be invalid as a record - a
 * second one for the same channel, say - and still be perfectly good to preview. The CSRF token is
 * another matter. It is the only thing standing between this route and an outside page making the
 * shop send an email of somebody else's wording, from the shop's own address and in its own
 * layout, to any recipient.
 */
#[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
final class SendTestEmailAction
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly TestEmailSender $sender,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Validation off for the record, on for the token: the CSRF listener adds its error
        // directly to the form, so isValid() still catches it with no validation groups at all.
        // The method follows the request, because a form only accepts a submission made with
        // its own method - and the button posts the create form as POST, the edit form as PUT.
        $form = $this->formFactory->create(ConfigurationType::class, new Configuration(), [
            'validation_groups' => false,
            'method' => $request->getMethod(),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->back($request, 'error', 'cyfrowe_nps.flash.test_email.failed');
        }

        $configuration = $form->getData();
        $recipient = $form->get('testEmailRecipient')->getData();

        if (!$configuration instanceof ConfigurationInterface) {
            return $this->back($request, 'error', 'cyfrowe_nps.flash.test_email.failed');
        }

        if (!is_string($recipient) || false === filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
            return $this->back($request, 'error', 'cyfrowe_nps.flash.test_email.bad_address');
        }

        $this->sender->send($configuration, $recipient, $request->getLocale());

        return $this->back($request, 'success', 'cyfrowe_nps.flash.test_email.sent');
    }

    private function back(Request $request, string $type, string $message): RedirectResponse
    {
        $session = $request->getSession();

        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }

        return new RedirectResponse($this->somewhereOnThisSite($request));
    }

    /**
     * Sending the visitor back where they came from is convenient, but an unchecked Referer turns
     * this into an open redirect - and next to a forged request, a neat way to end the journey on
     * the attacker's page.
     */
    private function somewhereOnThisSite(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');

        if ('' !== $referer) {
            $host = parse_url($referer, \PHP_URL_HOST);

            if (false !== $host && (null === $host || $host === $request->getHost())) {
                return $referer;
            }
        }

        return $this->urlGenerator->generate('cyfrowe_nps_admin_channel_configuration_index');
    }
}
