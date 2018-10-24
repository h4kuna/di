<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\DI\Config;

use Nette;
use Nette\DI\Definitions\Statement;
use Nette\DI\Extensions;
use Nette\DI\ServiceCreationException;
use Nette\Utils\Validators;


/**
 * Configuration processor
 */
class Processor
{
	use Nette\SmartObject;

	/** @var Nette\DI\ContainerBuilder */
	private $builder;


	public function __construct(Nette\DI\ContainerBuilder $builder)
	{
		$this->builder = $builder;
	}


	/**
	 * Normalizes and merges configurations.
	 */
	public function merge(array $mainConfig, array $config): array
	{
		return Helpers::merge($config, $mainConfig);
	}


	public function normalizeStructure($def): array
	{
		if ($def === null || $def === false) {
			return (array) $def;

		} elseif (is_string($def) && interface_exists($def)) {
			return ['implement' => $def];

		} elseif ($def instanceof Statement && is_string($def->getEntity()) && interface_exists($def->getEntity())) {
			return ['implement' => $def->getEntity(), 'factory' => array_shift($def->arguments)];

		} elseif (!is_array($def) || isset($def[0], $def[1])) {
			return ['factory' => $def];

		} elseif (is_array($def)) {
			foreach (['class' => 'type', 'dynamic' => 'external'] as $alias => $original) {
				if (array_key_exists($alias, $def)) {
					if (array_key_exists($original, $def)) {
						throw new Nette\InvalidStateException("Options '$alias' and '$original' are aliases, use only '$original'.");
					}
					$def[$original] = $def[$alias];
					unset($def[$alias]);
				}
			}
			return $def;

		} else {
			throw new Nette\InvalidStateException('Unexpected format of service definition');
		}
	}


	/**
	 * Adds service normalized definitions from configuration.
	 */
	public function loadDefinitions(array $services): void
	{
		foreach ($services as $name => $def) {
			if (is_int($name)) {
				$factory = $def['factory'] ?? null;
				$postfix = $factory instanceof Statement && is_string($factory->getEntity()) ? '.' . $factory->getEntity() : (is_scalar($factory) ? ".$factory" : '');
				$name = (count($this->builder->getDefinitions()) + 1) . preg_replace('#\W+#', '_', $postfix);
			} elseif (preg_match('#^@[\w\\\\]+\z#', $name)) {
				$name = $this->builder->getByType(substr($name, 1), true);
			}

			if ($def === [false]) {
				$this->builder->removeDefinition($name);
				continue;
			}

			$params = $this->builder->parameters;
			if (isset($def['parameters'])) {
				foreach ((array) $def['parameters'] as $k => $v) {
					$v = explode(' ', is_int($k) ? $v : $k);
					$params[end($v)] = $this->builder::literal('$' . end($v));
				}
			}
			$def = Nette\DI\Helpers::expand($def, $params);

			if (!empty($def['alteration']) && !$this->builder->hasDefinition($name)) {
				throw new ServiceCreationException("Service '$name': missing original definition for alteration.");
			}
			if (Helpers::takeParent($def)) {
				$this->builder->removeDefinition($name);
			}
			$definition = $this->builder->hasDefinition($name)
				? $this->builder->getDefinition($name)
				: $this->builder->addDefinition($name);

			try {
				$this->updateDefinition($definition, $def, $name);
			} catch (\Exception $e) {
				throw new ServiceCreationException("Service '$name': " . $e->getMessage(), 0, $e);
			}
		}
	}


	/**
	 * Parses single service definition from configuration.
	 */
	public function updateDefinition(Nette\DI\Definitions\ServiceDefinition $definition, array $config, string $name = null): void
	{
		$known = ['type', 'factory', 'arguments', 'setup', 'autowired', 'external', 'inject', 'parameters', 'implement', 'run', 'tags', 'alteration'];
		if ($error = array_diff(array_keys($config), $known)) {
			$hints = array_filter(array_map(function ($error) use ($known) {
				return Nette\Utils\ObjectHelpers::getSuggestion($known, $error);
			}, $error));
			$hint = $hints ? ", did you mean '" . implode("', '", $hints) . "'?" : '.';
			throw new Nette\InvalidStateException(sprintf("Unknown key '%s' in definition of service$hint", implode("', '", $error)));
		}

		$config = Nette\DI\Helpers::filterArguments($config);

		if (array_key_exists('type', $config) || array_key_exists('factory', $config)) {
			$definition->setType(null);
			$definition->setFactory(null);
		}

		if (array_key_exists('type', $config)) {
			Validators::assertField($config, 'type', 'string|Nette\DI\Definitions\Statement|null');
			if ($config['type'] instanceof Statement) {
				trigger_error("Service '$name': option 'type' or 'class' should be changed to 'factory'.", E_USER_DEPRECATED);
			} else {
				$definition->setType($config['type']);
			}
			$definition->setFactory($config['type']);
		}

		if (array_key_exists('factory', $config)) {
			Validators::assertField($config, 'factory', 'callable|Nette\DI\Definitions\Statement|null');
			$definition->setFactory($config['factory']);
		}

		if (array_key_exists('arguments', $config)) {
			Validators::assertField($config, 'arguments', 'array');
			$arguments = $config['arguments'];
			if (!Helpers::takeParent($arguments) && !Nette\Utils\Arrays::isList($arguments) && $definition->getFactory()) {
				$arguments += $definition->getFactory()->arguments;
			}
			$definition->setArguments($arguments);
		}

		if (isset($config['setup'])) {
			if (Helpers::takeParent($config['setup'])) {
				$definition->setSetup([]);
			}
			Validators::assertField($config, 'setup', 'list');
			foreach ($config['setup'] as $id => $setup) {
				Validators::assert($setup, 'callable|Nette\DI\Definitions\Statement|array:1', "setup item #$id");
				if (is_array($setup)) {
					$setup = new Statement(key($setup), array_values($setup));
				}
				$definition->addSetup($setup);
			}
		}

		if (isset($config['parameters'])) {
			Validators::assertField($config, 'parameters', 'array');
			$definition->setParameters($config['parameters']);
		}

		if (isset($config['implement'])) {
			Validators::assertField($config, 'implement', 'string');
			$definition->setImplement($config['implement']);
			$definition->setAutowired(true);
		}

		if (isset($config['autowired'])) {
			Validators::assertField($config, 'autowired', 'bool|string|array');
			$definition->setAutowired($config['autowired']);
		}

		if (isset($config['external'])) {
			Validators::assertField($config, 'external', 'bool');
			$definition->setDynamic($config['external']);
		}

		if (isset($config['inject'])) {
			Validators::assertField($config, 'inject', 'bool');
			$definition->addTag(Extensions\InjectExtension::TAG_INJECT, $config['inject']);
		}

		if (isset($config['tags'])) {
			Validators::assertField($config, 'tags', 'array');
			if (Helpers::takeParent($config['tags'])) {
				$definition->setTags([]);
			}
			foreach ($config['tags'] as $tag => $attrs) {
				if (is_int($tag) && is_string($attrs)) {
					$definition->addTag($attrs);
				} else {
					$definition->addTag($tag, $attrs);
				}
			}
		}
	}


	/**
	 * Replaces @extension with and prepends service names with namespace.
	 */
	public function applyNamespace(array $services, string $namespace): array
	{
		foreach ($services as $name => $def) {
			$def = Nette\DI\Helpers::prefixServiceName($def, $namespace);
			if (is_string($name)) {
				unset($services[$name]);
				$name = $namespace . '.' . $name;
			}
			$services[$name] = $def;
		}
		return $services;
	}
}