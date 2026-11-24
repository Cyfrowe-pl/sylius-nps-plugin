<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('cyfrowe_sylius_nps');

        $rootNode = $treeBuilder->getRootNode();
        assert($rootNode instanceof ArrayNodeDefinition);

        $children = $rootNode->children();

        $children
            ->booleanNode('shipment_trigger')
            ->defaultTrue()
            ->info('Starts a survey once every shipment of an order has been shipped. Switch it off when the shop starts surveys itself through SurveyStarterInterface.')
        ;

        $email = $children->arrayNode('email');
        $email->addDefaultsIfNotSet();
        $email->children()
            ->scalarNode('layout')
            ->defaultValue('@SyliusShop/Email/layout.html.twig')
            ->cannotBeEmpty()
            ->info('The layout the survey email is wrapped in. Point it at your own when the shop brands its emails somewhere other than the stock Sylius layout.')
        ;

        $messenger = $children->arrayNode('messenger');
        $messenger->addDefaultsIfNotSet();
        $messengerChildren = $messenger->children();
        $messengerChildren
            ->scalarNode('transport')
            ->defaultNull()
            ->info('Transport the survey message is routed to. Left empty, the application routes it like any other message.')
        ;
        $messengerChildren
            ->scalarNode('bus')
            ->defaultValue('messenger.default_bus')
            ->info('Bus the survey message is dispatched on. Point it at a bus without doctrine_transaction, so a survey that was sent but could not be saved is never retried.')
        ;

        $shop = $children->arrayNode('shop');
        $shop->addDefaultsIfNotSet();
        $shop->children()
            ->scalarNode('layout')
            ->defaultValue('@SyliusShop/layout.html.twig')
            ->cannotBeEmpty()
            ->info('The layout the survey pages are wrapped in. Point it at your own when the storefront is not the stock Sylius shop.')
        ;

        $botUserAgents = $shop->children()->arrayNode('bot_user_agents');
        $botUserAgents->scalarPrototype();
        $botUserAgents->defaultValue([
            'bot',
            'crawler',
            'spider',
            'scan',
            'preview',
            'proofpoint',
            'barracuda',
            'mimecast',
        ]);
        $botUserAgents->info('User agent fragments that mark a request as automated. Mail filters differ between shops, so replace the list with the ones yours sees.');

        $export = $children->arrayNode('export');
        $export->addDefaultsIfNotSet();

        $csv = $export->children()->arrayNode('csv');
        $csv->addDefaultsIfNotSet();

        $csvChildren = $csv->children();

        // Checked here rather than left to the writer: these reach fputcsv, which needs exactly
        // one character. A second character would pass an emptiness check and then fail halfway
        // through a download, with the headers already sent and the file already half saved.
        $delimiter = $csvChildren->scalarNode('delimiter');
        $delimiter->defaultValue(',');
        $delimiter->info('Column separator. The default is RFC 4180. Excel on a locale whose list separator is a semicolon needs one of those instead, which is why this is not hard coded.');
        $delimiter->validate()
            ->ifTrue(self::notASingleCharacter())
            ->thenInvalid('The column separator has to be exactly one character, %s given.')
        ;

        $enclosure = $csvChildren->scalarNode('enclosure');
        $enclosure->defaultValue('"');
        $enclosure->info('The character a value is wrapped in when it contains a separator, a line break or the character itself.');
        $enclosure->validate()
            ->ifTrue(self::notASingleCharacter())
            ->thenInvalid('The enclosure has to be exactly one character, %s given.')
        ;

        $decimalSeparator = $csvChildren->scalarNode('decimal_separator');
        $decimalSeparator->defaultValue('.');
        $decimalSeparator->info('Decimal separator of the order value. A full stop travels between tools; a comma is what Excel reads as a number on a locale that writes them that way - the same choice as the column separator, and made in the same place.');
        $decimalSeparator->validate()
            ->ifTrue(self::notASingleCharacter())
            ->thenInvalid('The decimal separator has to be exactly one character, %s given.')
        ;

        $csv->validate()
            ->ifTrue(static fn (array $csv): bool => $csv['delimiter'] === $csv['enclosure'])
            ->thenInvalid('The column separator and the enclosure cannot be the same character. Given: %s.')
        ;

        $csvChildren
            ->booleanNode('bom')
            ->defaultFalse()
            ->info('Writes a UTF-8 byte order mark at the start of the file. Off by default, because it is what makes Excel read the file as UTF-8 and what makes most other tools read a stray character.')
        ;

        $lock = $children->arrayNode('lock');
        $lock->addDefaultsIfNotSet();
        $lock->children()
            ->scalarNode('dsn')
            ->defaultValue('flock')
            ->cannotBeEmpty()
            ->info('Where the sending command keeps its lock. The default is a file on the machine running it, which is enough for one application server - point it at redis:// or a database when there are several.')
        ;

        return $treeBuilder;
    }

    private static function notASingleCharacter(): \Closure
    {
        return static fn (mixed $value): bool => !is_string($value) || 1 !== strlen($value);
    }
}
