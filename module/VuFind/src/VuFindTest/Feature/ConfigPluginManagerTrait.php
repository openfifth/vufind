<?php

/**
 * Trait for configuration handling in tests.
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2022.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Tests
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */

namespace VuFindTest\Feature;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use VuFind\Config\Config;
use VuFind\Config\PathResolver;
use VuFind\Config\PluginManager;

/**
 * Trait for configuration handling in tests.
 *
 * @category VuFind
 * @package  Tests
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */
trait ConfigPluginManagerTrait
{
    use PathResolverTrait;

    /**
     * Get a mock configuration plugin manager with the given configuration "files"
     * available.
     *
     * @param array            $configs   An associative array of configurations
     * where key is the file (e.g. 'config') and value an array of configuration
     * sections and directives
     * @param array            $default   Default configuration to return when no
     * entry is found in $configs
     * @param ?InvocationOrder $getExpect The expected invocation order for the get()
     * method (null for any)
     * @param ?InvocationOrder $hasExpect The expected invocation order for the has()
     * method (null for any)
     *
     * @return MockObject&PluginManager
     */
    protected function getMockConfigPluginManager(
        array $configs,
        array $default = [],
        ?InvocationOrder $getExpect = null,
        ?InvocationOrder $hasExpect = null
    ): PluginManager {
        $manager = $this->getMockBuilder(PluginManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects($getExpect ?? $this->any())
            ->method('get')
            ->with($this->isType('string'))
            ->will(
                $this->returnCallback(
                    function ($config) use ($configs, $default): Config {
                        return new Config($configs[$config] ?? $default);
                    }
                )
            );
        $manager->expects($hasExpect ?? $this->any())
            ->method('has')
            ->with($this->isType('string'))
            ->will(
                $this->returnCallback(
                    function ($config) use ($configs): bool {
                        return isset($configs[$config]);
                    }
                )
            );
        return $manager;
    }

    /**
     * Get a mock configuration plugin manager that will throw an exception.
     *
     * @param \Throwable $exception Exception to throw
     *
     * @return MockObject&PluginManager
     */
    protected function getMockFailingConfigPluginManager(
        \Throwable $exception
    ): PluginManager {
        $manager = $this->getMockBuilder(PluginManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects($this->any())
            ->method('get')
            ->with($this->isType('string'))
            ->will($this->throwException($exception));
        return $manager;
    }

    /**
     * Add config plugin manager and required services to a mock container.
     *
     * @param \VuFindTest\Container\MockContainer $container Mock Container
     * @param array                               $config    Module config
     *
     * @return void
     */
    protected function addConfigPluginManagerToContainer(
        \VuFindTest\Container\MockContainer $container,
        array $config
    ): void {
        $this->addPathResolverToContainer($container);
        $configHandlerPluginManager = new \VuFind\Config\Handler\PluginManager(
            $container,
            $config['vufind']['plugin_managers']['config_handler']
        );
        $configManager = new \VuFind\Config\ConfigManager(
            $configHandlerPluginManager,
            $container->get(PathResolver::class)
        );
        $container->set(\VuFind\Config\ConfigManager::class, $configManager);
        $configPluginManager = new \VuFind\Config\PluginManager(
            $container,
            $config['vufind']['config_reader']
        );
        $container->set(\VuFind\Config\PluginManager::class, $configPluginManager);
    }

    /**
     * Get a mock container that has a config plugin manager and required services.
     *
     * @return \VuFindTest\Container\MockContainer
     */
    protected function getContainerWithConfigPluginManager(): \VuFindTest\Container\MockContainer
    {
        $container = new \VuFindTest\Container\MockContainer($this);
        $config = include APPLICATION_PATH . '/module/VuFind/config/module.config.php';
        $this->addConfigPluginManagerToContainer($container, $config);
        return $container;
    }
}
