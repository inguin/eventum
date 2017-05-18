<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Extension;

use InvalidArgumentException;
use Setup;
use Zend\Config\Config;

class ExtensionManager
{
    /** @var ExtensionInterface[] */
    protected $extensions;

    /**
     * Singleton Extension Manager
     *
     * @return ExtensionManager
     */
    public static function getManager()
    {
        static $manager;
        if (!$manager) {
            $manager = new self();
        }

        return $manager;
    }

    public function __construct()
    {
        $this->extensions = $this->loadExtensions();
    }

    /**
     * Return instances of Workflow implementations.
     *
     * @return array
     */
    public function getWorkflowClasses()
    {
        return $this->createInstances('getAvailableWorkflows');
    }

    /**
     * Return instances of Custom Field implementations.
     *
     * @return array
     */
    public function getCustomFieldClasses()
    {
        return $this->createInstances('getAvailableCustomFields');
    }

    /**
     * Return instances of CRM implementations.
     *
     * @return array
     */
    public function getCustomerClasses()
    {
        return $this->createInstances('getAvailableCRMs');
    }

    /**
     * Return instances of Partner implementations.
     *
     * @return \Abstract_Partner_Backend[]
     */
    public function getPartnerClasses()
    {
        /** @var \Abstract_Partner_Backend[] $backends */
        $backends = $this->createInstances('getAvailablePartners');

        return $backends;
    }

    /**
     * Helper to get merged class list from all extensions
     * and create instances of them.
     *
     * @param string $methodName method name to call on each Extension to obtain class names
     * @return object[]
     */
    protected function createInstances($methodName)
    {
        $classes = [];
        foreach ($this->extensions as $extension) {
            $classes = array_merge($classes, $extension->$methodName());
        }

        $extensions = [];
        foreach ($classes as $classname) {
            $extensions[$classname] = $this->createInstance($classname);
        }

        return $extensions;
    }

    /**
     * Create new instance of named class
     *
     * @param string $classname
     * @return object
     */
    protected function createInstance($classname)
    {
        if (!class_exists($classname)) {
            throw new InvalidArgumentException("Class '$classname' does not exist");
        }

        return new $classname();
    }

    /**
     * Create all extensions, initialize them
     *
     * @return ExtensionInterface[]
     */
    protected function loadExtensions()
    {
        $extensions = [];
        $loader = $this->getAutoloader();

        foreach ($this->getExtensionFiles() as $classname => $filename) {
            $extension = $this->loadExtension($classname, $filename);
            $extension->registerAutoloader($loader);
            $extensions[$classname] = $extension;
        }

        return $extensions;
    }

    /**
     * Load $filename and create $classname instance
     *
     * @param string $classname
     * @param string $filename
     * @return ExtensionInterface
     */
    protected function loadExtension($classname, $filename)
    {
        // class may already be loaded
        // can ignore the filename requirement
        if (!class_exists($classname)) {
            if (!file_exists($filename)) {
                throw new InvalidArgumentException("File does not exist: $filename");
            }

            /** @noinspection PhpIncludeInspection */
            require_once $filename;

            if (!class_exists($classname)) {
                throw new InvalidArgumentException("Could not load $classname from $filename");
            }
        }

        return new $classname();
    }

    /**
     * Get configured extensions from setup.php
     *
     * @return Config|\Traversable|array
     */
    protected function getExtensionFiles()
    {
        return Setup::get()['extensions'] ?: [];
    }

    /**
     * Return composer autoloader
     *
     * @return \Composer\Autoload\ClassLoader
     */
    protected function getAutoloader()
    {
        return require APP_PATH . '/vendor/autoload.php';
    }
}