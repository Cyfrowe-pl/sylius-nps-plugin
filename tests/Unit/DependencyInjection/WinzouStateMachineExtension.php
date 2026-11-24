<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Unit\DependencyInjection;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

/**
 * Stands in for the winzou bundle, so the plugin's prepend can be exercised without pulling the
 * whole state machine into a unit test.
 */
final class WinzouStateMachineExtension implements ExtensionInterface, ConfigurationExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function getNamespace(): string
    {
        return '';
    }

    public function getXsdValidationBasePath(): string|false
    {
        return false;
    }

    public function getAlias(): string
    {
        return 'winzou_state_machine';
    }

    public function getConfiguration(array $config, ContainerBuilder $container): ?ConfigurationInterface
    {
        return null;
    }
}
